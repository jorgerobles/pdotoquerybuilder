<?php
/**
 * Memory-Efficient PHP Method and Class Usage Scanner with Caching
 *
 * Optimized for large codebases with minimal memory footprint and intelligent caching
 * Uses streaming processing, progress indicators, and file modification time caching
 *
 * Usage: php usage-scanner.php --search="ClassName,AnotherClass::method" --path="/path/to/scan" --exclude="vendor,tests" [--cache-dir="/tmp/php-scanner-cache"] [--no-cache]
 */

require_once 'vendor/autoload.php';
error_reporting(E_ALL & ~E_DEPRECATED);
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\Error;
use PhpParser\PhpVersion;

class CacheManager
{
    private $cacheDir;
    private $searchHash;
    private $cacheEnabled;
    private $cacheStats = [
        'hits' => 0,
        'misses' => 0,
        'writes' => 0,
        'purged' => 0
    ];

    public function __construct(string $cacheDir, array $searchTargets, array $excludePaths, bool $enabled = true)
    {
        $this->cacheEnabled = $enabled;

        if (!$this->cacheEnabled) {
            return;
        }

        $this->cacheDir = rtrim($cacheDir, '/') . '/php-usage-scanner';
        $this->searchHash = $this->generateSearchHash($searchTargets, $excludePaths);

        $this->initializeCacheDirectory();
        $this->cleanupOldCaches();
    }

    private function generateSearchHash(array $searchTargets, array $excludePaths): string
    {
        $config = [
            'targets' => $searchTargets,
            'excludes' => $excludePaths,
            'version' => '2.0' // Change this when scanner logic changes
        ];
        return md5(json_encode($config));
    }

    private function initializeCacheDirectory(): void
    {
        if (!is_dir($this->cacheDir)) {
            if (!mkdir($this->cacheDir, 0755, true)) {
                echo "Warning: Cannot create cache directory {$this->cacheDir}. Caching disabled.\n";
                $this->cacheEnabled = false;
                return;
            }
        }

        if (!is_writable($this->cacheDir)) {
            echo "Warning: Cache directory {$this->cacheDir} is not writable. Caching disabled.\n";
            $this->cacheEnabled = false;
        }
    }

    private function cleanupOldCaches(): void
    {
        if (!$this->cacheEnabled) return;

        $cutoffTime = time() - (7 * 24 * 3600); // 7 days
        $iterator = new DirectoryIterator($this->cacheDir);

        foreach ($iterator as $file) {
            if ($file->isDot() || !$file->isFile()) continue;

            if ($file->getMTime() < $cutoffTime) {
                unlink($file->getPathname());
                $this->cacheStats['purged']++;
            }
        }
    }

    public function getCacheFilename(string $filePath): string
    {
        $pathHash = md5($filePath);
        return "{$this->cacheDir}/{$this->searchHash}_{$pathHash}.cache";
    }

    public function getCachedResult(string $filePath, int $fileModTime): ?array
    {
        if (!$this->cacheEnabled) return null;

        $cacheFile = $this->getCacheFilename($filePath);

        if (!file_exists($cacheFile)) {
            $this->cacheStats['misses']++;
            return null;
        }

        $cacheData = @unserialize(file_get_contents($cacheFile));
        if ($cacheData === false) {
            // Corrupted cache file
            @unlink($cacheFile);
            $this->cacheStats['misses']++;
            return null;
        }

        // Check if file has been modified since cache was created
        if (!isset($cacheData['mtime']) || $cacheData['mtime'] !== $fileModTime) {
            @unlink($cacheFile);
            $this->cacheStats['misses']++;
            return null;
        }

        $this->cacheStats['hits']++;
        return $cacheData['usages'] ?? [];
    }

    public function setCachedResult(string $filePath, int $fileModTime, array $usages): void
    {
        if (!$this->cacheEnabled) return;

        $cacheFile = $this->getCacheFilename($filePath);
        $cacheData = [
            'mtime' => $fileModTime,
            'usages' => $usages,
            'cached_at' => time()
        ];

        if (@file_put_contents($cacheFile, serialize($cacheData), LOCK_EX) !== false) {
            $this->cacheStats['writes']++;
        }
    }

    public function getCacheStats(): array
    {
        return $this->cacheStats;
    }

    public function clearCache(): void
    {
        if (!$this->cacheEnabled || !is_dir($this->cacheDir)) return;

        $iterator = new DirectoryIterator($this->cacheDir);
        $cleared = 0;

        foreach ($iterator as $file) {
            if ($file->isDot() || !$file->isFile()) continue;

            if (unlink($file->getPathname())) {
                $cleared++;
            }
        }

        echo "Cleared {$cleared} cache files.\n";
    }

    public function getCacheSize(): string
    {
        if (!$this->cacheEnabled || !is_dir($this->cacheDir)) return '0 B';

        $totalSize = 0;
        $iterator = new DirectoryIterator($this->cacheDir);

        foreach ($iterator as $file) {
            if ($file->isDot() || !$file->isFile()) continue;
            $totalSize += $file->getSize();
        }

        return $this->formatBytes($totalSize);
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= (1 << (10 * $pow));

        return round($bytes, 2) . ' ' . $units[$pow];
    }
}

class MemoryEfficientUsageScanner extends NodeVisitorAbstract
{
    private $searchTargets;
    private $currentFile;
    private $currentUsages = [];

    public function __construct(array $searchTargets)
    {
        $this->searchTargets = $this->parseSearchTargets($searchTargets);
    }

    public function setCurrentFile(string $file)
    {
        $this->currentFile = $file;
        $this->currentUsages = []; // Reset for each file
    }

    public function getCurrentUsages(): array
    {
        return $this->currentUsages;
    }

    private function parseSearchTargets(array $targets): array
    {
        $parsed = [
            'classes' => [],
            'methods' => [],
            'class_lookup' => [], // For faster method lookups
        ];

        foreach ($targets as $target) {
            if (strpos($target, '::') !== false) {
                list($class, $method) = explode('::', $target, 2);
                $class = trim($class);
                $method = trim($method);

                $parsed['methods'][$target] = [
                    'class' => $class,
                    'method' => $method,
                ];

                // Build lookup table for faster searches
                if (!isset($parsed['class_lookup'][$class])) {
                    $parsed['class_lookup'][$class] = [];
                }
                $parsed['class_lookup'][$class][] = $method;
            } else {
                $parsed['classes'][trim($target)] = true; // Use associative array for O(1) lookups
            }
        }

        return $parsed;
    }

    public function enterNode(Node $node)
    {
        // Check for new ClassName() usage
        if ($node instanceof Node\Expr\New_ && $node->class instanceof Node\Name) {
            $className = $node->class->toString();
            if (isset($this->searchTargets['classes'][$className])) {
                $this->recordUsage($className, 'new', $node->getLine());
            }
        }

        // Check for $obj->method() usage
        elseif ($node instanceof Node\Expr\MethodCall && $node->name instanceof Node\Identifier) {
            $methodName = $node->name->toString();
            $this->checkInstanceMethodCall($methodName, $node->getLine());
        }

        // Check for ClassName::method() static calls
        elseif ($node instanceof Node\Expr\StaticCall) {
            if ($node->class instanceof Node\Name && $node->name instanceof Node\Identifier) {
                $className = $node->class->toString();
                $methodName = $node->name->toString();
                $fullName = "{$className}::{$methodName}";

                if (isset($this->searchTargets['methods'][$fullName])) {
                    $this->recordUsage($fullName, 'static_call', $node->getLine());
                }
            }
        }

        // Check for class name usage in type hints, instanceof, etc.
        elseif ($node instanceof Node\Name) {
            $className = $node->toString();
            if (isset($this->searchTargets['classes'][$className])) {
                // Avoid counting class definitions
                if (!$this->isClassDefinition($node)) {
                    $this->recordUsage($className, 'reference', $node->getLine());
                }
            }
        }

        return null;
    }

    private function checkInstanceMethodCall(string $methodName, int $line)
    {
        foreach ($this->searchTargets['class_lookup'] as $className => $methods) {
            if (in_array($methodName, $methods, true)) {
                $fullName = "{$className}::{$methodName}";
                $this->recordUsage($fullName, 'method_call', $line, "?::{$methodName}");
            }
        }
    }

    private function isClassDefinition(Node\Name $node): bool
    {
        $parent = $node->getAttribute('parent');
        return $parent instanceof Node\Stmt\Class_ ||
            $parent instanceof Node\Stmt\Interface_ ||
            $parent instanceof Node\Stmt\Trait_;
    }

    private function recordUsage(string $target, string $type, int $line, ?string $display = null)
    {
        if (!isset($this->currentUsages[$target])) {
            $this->currentUsages[$target] = [
                'count' => 0,
                'lines' => [],
                'types' => []
            ];
        }

        $this->currentUsages[$target]['count']++;
        $this->currentUsages[$target]['lines'][] = $line;
        $this->currentUsages[$target]['types'][] = $type;
    }
}

class StreamingFileScanner
{
    private $parser;
    private $traverser;
    private $visitor;
    private $excludePaths;
    private $outputHandle;
    private $tempFile;
    private $totalFiles = 0;
    private $processedFiles = 0;
    private $totalUsages = 0;
    private $filesWithUsages = 0;
    private $lastProgressUpdate = 0;
    private $cacheManager;
    private $skippedFiles = 0;

    public function __construct(array $searchTargets, array $excludePaths = [], ?CacheManager $cacheManager = null)
    {
        $this->parser = $this->createParser();
        $this->traverser = new NodeTraverser();
        $this->visitor = new MemoryEfficientUsageScanner($searchTargets);
        $this->traverser->addVisitor($this->visitor);
        $this->excludePaths = $this->normalizeExcludePaths($excludePaths);
        $this->cacheManager = $cacheManager;

        // Create temporary file for streaming results
        $this->tempFile = tempnam(sys_get_temp_dir(), 'php_usage_scan_');
        $this->outputHandle = fopen($this->tempFile, 'w+');

        if (!$this->outputHandle) {
            throw new RuntimeException("Cannot create temporary file for results");
        }
    }

    private function createParser()
    {
        return (new ParserFactory)->createForVersion(PhpVersion::fromString(7.4));
    }

    public function __destruct()
    {
        if ($this->outputHandle) {
            fclose($this->outputHandle);
        }
        if ($this->tempFile && file_exists($this->tempFile)) {
            unlink($this->tempFile);
        }
    }

    private function normalizeExcludePaths(array $excludePaths): array
    {
        $normalized = [];
        foreach ($excludePaths as $path) {
            $realPath = realpath($path);
            if ($realPath) {
                $normalized[] = $realPath;
            } else {
                // If realpath fails, use the original path for pattern matching
                $normalized[] = $path;
            }
        }
        return $normalized;
    }

    public function scanDirectory(string $directory): void
    {
        $directory = realpath($directory);
        if (!$directory || !is_dir($directory)) {
            throw new InvalidArgumentException("Invalid directory: {$directory}");
        }

        echo "Analyzing directory structure...\n";
        $this->countTotalFiles($directory);
        echo "Found {$this->totalFiles} PHP files to scan.\n";

        if ($this->cacheManager) {
            $cacheSize = $this->cacheManager->getCacheSize();
            echo "Cache size: {$cacheSize}\n";
        }

        echo "Starting scan";
        if ($this->totalFiles > 100) {
            echo " (this might take a while)";
        }
        echo "...\n\n";

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $filePath = $file->getRealPath();

                if ($this->shouldExcludeFile($filePath)) {
                    $this->processedFiles++;
                    $this->updateProgress();
                    continue;
                }

                $this->scanFile($filePath);
                $this->updateProgress();

                // Force garbage collection every 100 files to keep memory usage low
                if ($this->processedFiles % 100 === 0) {
                    gc_collect_cycles();
                }
            }
        }

        echo "\n\nScan completed!\n";

        if ($this->cacheManager) {
            $this->displayCacheStats();
        }
    }

    private function displayCacheStats(): void
    {
        $stats = $this->cacheManager->getCacheStats();
        $totalRequests = $stats['hits'] + $stats['misses'];

        if ($totalRequests > 0) {
            $hitRate = ($stats['hits'] / $totalRequests) * 100;
            echo "\nCache Statistics:\n";
            echo "  Cache hits: {$stats['hits']}\n";
            echo "  Cache misses: {$stats['misses']}\n";
            echo "  Hit rate: " . number_format($hitRate, 1) . "%\n";
            echo "  Files cached: {$stats['writes']}\n";
            echo "  Files skipped (cached): {$this->skippedFiles}\n";
            if ($stats['purged'] > 0) {
                echo "  Old cache files purged: {$stats['purged']}\n";
            }
        }
    }

    private function countTotalFiles(string $directory): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $this->totalFiles++;
            }
        }
    }

    private function updateProgress(): void
    {
        $now = microtime(true);

        // Update progress every 0.1 seconds or every 10 files, whichever comes first
        if ($now - $this->lastProgressUpdate >= 0.1 || $this->processedFiles % 10 === 0) {
            $percentage = $this->totalFiles > 0 ? ($this->processedFiles / $this->totalFiles) * 100 : 0;

            // Print progress dots and percentage
            echo ".";

            if ($this->processedFiles % 50 === 0 || $this->processedFiles === $this->totalFiles) {
                $memoryUsage = $this->formatBytes(memory_get_usage(true));
                $cacheInfo = $this->skippedFiles > 0 ? " (Cached: {$this->skippedFiles})" : "";
                echo sprintf(" [%d/%d] %.1f%% (Memory: %s)%s\n",
                    $this->processedFiles, $this->totalFiles, $percentage, $memoryUsage, $cacheInfo);
            }

            $this->lastProgressUpdate = $now;
        }
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= (1 << (10 * $pow));

        return round($bytes, 2) . ' ' . $units[$pow];
    }

    private function shouldExcludeFile(string $filePath): bool
    {
        foreach ($this->excludePaths as $excludePath) {
            if (strpos($filePath, $excludePath) !== false) {
                return true;
            }
        }
        return false;
    }

    private function scanFile(string $filePath): void
    {
        $this->processedFiles++;

        try {
            $fileModTime = filemtime($filePath);

            // Try to get cached result first
            if ($this->cacheManager) {
                $cachedUsages = $this->cacheManager->getCachedResult($filePath, $fileModTime);

                if ($cachedUsages !== null) {
                    // Cache hit - use cached results
                    $this->skippedFiles++;

                    if (!empty($cachedUsages)) {
                        $this->writeUsagesToFile($filePath, $cachedUsages);
                        $this->filesWithUsages++;

                        foreach ($cachedUsages as $usage) {
                            $this->totalUsages += $usage['count'];
                        }
                    }
                    return;
                }
            }

            // Cache miss - scan the file
            $fileSize = filesize($filePath);
            if ($fileSize > 10 * 1024 * 1024) { // 10MB
                echo "\n  [LARGE FILE] Processing {$filePath} ({$this->formatBytes($fileSize)})...";
            }

            $code = file_get_contents($filePath);
            if ($code === false) {
                return;
            }

            $ast = $this->parser->parse($code);
            if ($ast === null) {
                return;
            }

            $this->visitor->setCurrentFile($filePath);
            $this->traverser->traverse($ast);

            $usages = $this->visitor->getCurrentUsages();

            // Cache the results (even if empty)
            if ($this->cacheManager) {
                $this->cacheManager->setCachedResult($filePath, $fileModTime, $usages);
            }

            if (!empty($usages)) {
                $this->writeUsagesToFile($filePath, $usages);
                $this->filesWithUsages++;

                foreach ($usages as $usage) {
                    $this->totalUsages += $usage['count'];
                }
            }

            // Unset large variables to free memory immediately
            unset($code, $ast, $usages);

        } catch (Error $e) {
            // Continue silently for parse errors to avoid cluttering output
        } catch (Exception $e) {
            // Continue silently for other errors
        }
    }

    private function writeUsagesToFile(string $filePath, array $usages): void
    {
        $data = json_encode([
                'file' => $filePath,
                'usages' => $usages
            ]) . "\n";

        fwrite($this->outputHandle, $data);
    }

    public function displayResults(): void
    {
        if ($this->filesWithUsages === 0) {
            echo "No usages found.\n";
            return;
        }

        echo "\n" . str_repeat("=", 80) . "\n";
        echo "USAGE SCAN RESULTS\n";
        echo str_repeat("=", 80) . "\n";

        // Reset file pointer to read results
        rewind($this->outputHandle);

        $targetSummary = [];

        while (($line = fgets($this->outputHandle)) !== false) {
            $data = json_decode(trim($line), true);
            if (!$data) continue;

            $file = $data['file'];
            $usages = $data['usages'];

            echo "\nFile: " . $file . "\n";
            echo str_repeat("-", min(80, strlen($file) + 6)) . "\n";

            foreach ($usages as $target => $info) {
                echo "  {$target}: {$info['count']} usage(s)\n";
                echo "    Lines: " . implode(', ', $info['lines']) . "\n";

                // Count by type
                $typeCounts = array_count_values($info['types']);
                foreach ($typeCounts as $type => $count) {
                    echo "    {$type}: {$count} occurrence(s)\n";
                }

                // Build summary
                if (!isset($targetSummary[$target])) {
                    $targetSummary[$target] = ['count' => 0, 'files' => 0];
                }
                $targetSummary[$target]['count'] += $info['count'];
                $targetSummary[$target]['files']++;
            }
        }

        echo "\n" . str_repeat("=", 80) . "\n";
        echo "SUMMARY\n";
        echo str_repeat("=", 80) . "\n";
        echo "Total files scanned: {$this->processedFiles}\n";
        echo "Files with usages: {$this->filesWithUsages}\n";
        echo "Total usages found: {$this->totalUsages}\n";
        echo "Peak memory usage: {$this->formatBytes(memory_get_peak_usage(true))}\n";

        if (!empty($targetSummary)) {
            echo "\nUsage by target:\n";
            foreach ($targetSummary as $target => $summary) {
                echo "  {$target}: {$summary['count']} usage(s) across {$summary['files']} file(s)\n";
            }
        }
    }

    public function exportToCsv(string $csvFile): void
    {
        if ($this->filesWithUsages === 0) {
            echo "No usages found to export.\n";
            return;
        }

        $csvHandle = fopen($csvFile, 'w');
        if (!$csvHandle) {
            throw new RuntimeException("Cannot create CSV file: {$csvFile}");
        }

        try {
            // Write CSV headers
            fputcsv($csvHandle, [
                'File',
                'Target',
                'Type',
                'Line',
                'Usage_Count_In_File',
                'Target_Type'
            ]);

            // Reset file pointer to read results
            rewind($this->outputHandle);

            $totalRows = 0;

            while (($line = fgets($this->outputHandle)) !== false) {
                $data = json_decode(trim($line), true);
                if (!$data) continue;

                $file = $data['file'];
                $usages = $data['usages'];

                foreach ($usages as $target => $info) {
                    // Determine target type
                    $targetType = strpos($target, '::') !== false ? 'method' : 'class';

                    // Create one row per line where usage occurs
                    foreach ($info['lines'] as $index => $line) {
                        $usageType = isset($info['types'][$index]) ? $info['types'][$index] : 'unknown';

                        fputcsv($csvHandle, [
                            $file,
                            $target,
                            $usageType,
                            $line,
                            $info['count'],
                            $targetType
                        ]);

                        $totalRows++;
                    }
                }
            }

            echo "\nCSV export completed: {$csvFile}\n";
            echo "Exported {$totalRows} usage records.\n";

        } finally {
            fclose($csvHandle);
        }
    }

    public function exportSummaryToCsv(string $csvFile): void
    {
        if ($this->filesWithUsages === 0) {
            echo "No usages found to export.\n";
            return;
        }

        $csvHandle = fopen($csvFile, 'w');
        if (!$csvHandle) {
            throw new RuntimeException("Cannot create CSV file: {$csvFile}");
        }

        try {
            // Write CSV headers for summary
            fputcsv($csvHandle, [
                'Target',
                'Target_Type',
                'Total_Usages',
                'Files_Count',
                'Usage_Types',
                'Files_List'
            ]);

            // Reset file pointer to read results
            rewind($this->outputHandle);

            $targetSummary = [];
            $targetFiles = [];
            $targetTypes = [];

            // Collect summary data
            while (($line = fgets($this->outputHandle)) !== false) {
                $data = json_decode(trim($line), true);
                if (!$data) continue;

                $file = basename($data['file']); // Use basename for cleaner output
                $usages = $data['usages'];

                foreach ($usages as $target => $info) {
                    if (!isset($targetSummary[$target])) {
                        $targetSummary[$target] = ['count' => 0, 'files' => 0];
                        $targetFiles[$target] = [];
                        $targetTypes[$target] = [];
                    }

                    $targetSummary[$target]['count'] += $info['count'];
                    $targetSummary[$target]['files']++;
                    $targetFiles[$target][] = $file;

                    // Collect unique usage types for this target
                    foreach ($info['types'] as $type) {
                        $targetTypes[$target][$type] = true;
                    }
                }
            }

            // Write summary rows
            foreach ($targetSummary as $target => $summary) {
                $targetType = strpos($target, '::') !== false ? 'method' : 'class';
                $usageTypes = implode(';', array_keys($targetTypes[$target]));
                $filesList = implode(';', array_unique($targetFiles[$target]));

                fputcsv($csvHandle, [
                    $target,
                    $targetType,
                    $summary['count'],
                    $summary['files'],
                    $usageTypes,
                    $filesList
                ]);
            }

            echo "\nCSV summary export completed: {$csvFile}\n";
            echo "Exported " . count($targetSummary) . " target summaries.\n";

        } finally {
            fclose($csvHandle);
        }
    }
}

function parseArguments(): array
{
    $options = getopt('', [
        'search:', 'path:', 'exclude:', 'cache-dir:',
        'no-cache', 'clear-cache', 'stdin', 'csv:', 'csv-summary:',
        'help', 'h'
    ]);

    // Show help if requested
    if (isset($options['help']) || isset($options['h'])) {
        displayUsage();
        exit(0);
    }

    // Handle cache clearing first
    if (isset($options['clear-cache'])) {
        $cacheDir = validateCacheDir($options['cache-dir'] ?? sys_get_temp_dir());
        $cacheManager = new CacheManager($cacheDir, [], []);
        $cacheManager->clearCache();
        exit(0);
    }

    // Validate and get search targets
    $searchTargets = validateAndGetSearchTargets($options);

    // Validate scan path
    $scanPath = validateScanPath($options['path'] ?? getcwd());

    // Validate exclude paths
    $excludePaths = validateExcludePaths($options['exclude'] ?? '');

    // Validate cache settings
    $cacheDir = validateCacheDir($options['cache-dir'] ?? sys_get_temp_dir());
    $cacheEnabled = !isset($options['no-cache']);

    // Validate CSV export paths
    $csvFile = validateCsvPath($options['csv'] ?? null, 'csv');
    $csvSummaryFile = validateCsvPath($options['csv-summary'] ?? null, 'csv-summary');

    return [$searchTargets, $scanPath, $excludePaths, $cacheDir, $cacheEnabled, $csvFile, $csvSummaryFile];
}

function validateAndGetSearchTargets(array $options): array
{
    $errors = [];

    try {
        $searchTargets = getSearchTargets($options);

        if (empty($searchTargets)) {
            $errors[] = "No search targets provided";
        } else {
            // Validate each search target format
            $validTargets = [];
            foreach ($searchTargets as $target) {
                $target = trim($target);
                if (empty($target)) {
                    continue; // Skip empty targets
                }

                if (strpos($target, '::') !== false) {
                    // Method format validation
                    $parts = explode('::', $target);
                    if (count($parts) !== 2 || empty(trim($parts[0])) || empty(trim($parts[1]))) {
                        $errors[] = "Invalid method format: '{$target}' (should be 'ClassName::methodName')";
                    } else {
                        // Validate class and method names
                        $className = trim($parts[0]);
                        $methodName = trim($parts[1]);

                        if (!isValidPhpIdentifier($className)) {
                            $errors[] = "Invalid class name in method: '{$className}' (should be a valid PHP class name)";
                        } elseif (!isValidPhpIdentifier($methodName)) {
                            $errors[] = "Invalid method name: '{$methodName}' (should be a valid PHP method name)";
                        } else {
                            $validTargets[] = $target;
                        }
                    }
                } else {
                    // Class name validation
                    if (!isValidPhpIdentifier($target)) {
                        $errors[] = "Invalid class name: '{$target}' (should be a valid PHP class name)";
                    } else {
                        $validTargets[] = $target;
                    }
                }
            }

            if (empty($validTargets) && empty($errors)) {
                $errors[] = "All search targets are empty after validation";
            } else {
                $searchTargets = $validTargets;
            }
        }

    } catch (Exception $e) {
        $errors[] = "Error getting search targets: " . $e->getMessage();
    }

    if (!empty($errors)) {
        displayArgumentErrors("Search Target Errors:", $errors);
        displayUsageHint();
        exit(1);
    }

    return $searchTargets;
}

function isValidPhpIdentifier(string $name): bool
{
    // PHP identifier rules: start with letter or underscore, followed by letters, numbers, underscores
    // Also allow backslashes for namespaced classes
    return preg_match('/^[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff\\\\]*$/', $name) === 1;
}

function validateScanPath(string $path): string
{
    $errors = [];

    if (empty($path)) {
        $errors[] = "Scan path cannot be empty";
    } else {
        $realPath = realpath($path);
        if ($realPath === false) {
            $errors[] = "Scan path does not exist: '{$path}'";
        } elseif (!is_dir($realPath)) {
            $errors[] = "Scan path is not a directory: '{$path}'";
        } elseif (!is_readable($realPath)) {
            $errors[] = "Scan path is not readable: '{$path}'";
        } else {
            return $realPath;
        }
    }

    if (!empty($errors)) {
        displayArgumentErrors("Scan Path Errors:", $errors);
        exit(1);
    }

    return $path;
}

function validateExcludePaths(string $excludeString): array
{
    if (empty($excludeString)) {
        return [];
    }

    $excludePaths = array_map('trim', explode(',', $excludeString));
    $validPaths = [];
    $warnings = [];

    foreach ($excludePaths as $path) {
        if (empty($path)) {
            continue;
        }

        // For exclude paths, we don't require them to exist (they might be patterns)
        $validPaths[] = $path;

        // But warn if they look like absolute paths that don't exist
        if (str_starts_with($path, '/') && !file_exists($path)) {
            $warnings[] = "Exclude path does not exist: '{$path}' (will be treated as pattern)";
        }
    }

    if (!empty($warnings)) {
        echo "Warnings:\n";
        foreach ($warnings as $warning) {
            echo "  - {$warning}\n";
        }
        echo "\n";
    }

    return $validPaths;
}

function validateCacheDir(string $cacheDir): string
{
    $errors = [];

    if (empty($cacheDir)) {
        $errors[] = "Cache directory cannot be empty";
    } else {
        // Expand the cache directory path to include the scanner subdirectory
        $fullCacheDir = rtrim($cacheDir, '/') . '/php-usage-scanner';

        // Check if the parent directory is writable, not the root
        $parentDir = dirname($fullCacheDir);

        // If parent directory doesn't exist, check its parent, and so on
        while (!is_dir($parentDir) && $parentDir !== '/' && $parentDir !== '.') {
            $parentDir = dirname($parentDir);
        }

        if ($parentDir === '/' || $parentDir === '.') {
            // If we get to root or current dir, check the original cacheDir
            if (!is_dir($cacheDir)) {
                if (!is_writable(dirname($cacheDir))) {
                    $errors[] = "Cache base directory is not writable: '" . dirname($cacheDir) . "'";
                }
            } elseif (!is_writable($cacheDir)) {
                $errors[] = "Cache directory is not writable: '{$cacheDir}'";
            }
        } else {
            // Check if the existing parent directory is writable
            if (!is_writable($parentDir)) {
                $errors[] = "Cache parent directory is not writable: '{$parentDir}'";
            }
        }
    }

    if (!empty($errors)) {
        displayArgumentErrors("Cache Directory Errors:", $errors);
        exit(1);
    }

    return $cacheDir;
}

function validateCsvPath(?string $csvPath, string $optionName): ?string
{
    if ($csvPath === null) {
        return null;
    }

    $errors = [];

    if (empty($csvPath)) {
        $errors[] = "CSV file path cannot be empty when --{$optionName} is specified";
    } else {
        $csvDir = dirname($csvPath);
        if ($csvDir === '.') {
            // Current directory is fine
            return $csvPath;
        }

        if (!is_dir($csvDir)) {
            $errors[] = "CSV directory does not exist: '{$csvDir}'";
        } elseif (!is_writable($csvDir)) {
            $errors[] = "CSV directory is not writable: '{$csvDir}'";
        }

        // Check if file already exists and is writable
        if (file_exists($csvPath) && !is_writable($csvPath)) {
            $errors[] = "CSV file exists but is not writable: '{$csvPath}'";
        }

        // Validate file extension
        $extension = strtolower(pathinfo($csvPath, PATHINFO_EXTENSION));
        if ($extension !== 'csv') {
            echo "Warning: CSV file '{$csvPath}' does not have .csv extension\n";
        }
    }

    if (!empty($errors)) {
        displayArgumentErrors("CSV Export Errors:", $errors);
        exit(1);
    }

    return $csvPath;
}

function getSearchTargets(array $options): array
{
    // Check if we should read from stdin
    if (isset($options['stdin']) || !isset($options['search']) || $options['search'] === '') {
        return readSearchTargetsFromStdin();
    }

    // Parse from command line argument
    return parseSearchString($options['search']);
}

function readSearchTargetsFromStdin(): array
{
    if (!isStdinAvailable()) {
        throw new Exception("stdin is not available or accessible");
    }

    echo "Reading search targets from stdin...\n";
    echo "Enter class names or methods (Class::method), one per line or comma-separated.\n";
    echo "Press Ctrl+D (Unix) or Ctrl+Z (Windows) when done.\n\n";

    $input = '';
    $hasInput = false;

    while (($line = fgets(STDIN)) !== false) {
        $input .= $line;
        $hasInput = true;
    }

    if (!$hasInput || empty(trim($input))) {
        throw new Exception("no input received from stdin");
    }

    return parseSearchString($input);
}

function parseSearchString(string $input): array
{
    if (empty(trim($input))) {
        return [];
    }

    // Handle both newline and comma-separated inputs
    $targets = [];

    // First split by newlines
    $lines = preg_split('/\r\n|\r|\n/', $input);

    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) continue;

        // Then split each line by commas
        $parts = explode(',', $line);
        foreach ($parts as $part) {
            $part = trim($part);
            if (!empty($part)) {
                $targets[] = $part;
            }
        }
    }

    // Remove duplicates and return
    return array_unique($targets);
}

function isStdinAvailable(): bool
{
    if (php_sapi_name() !== 'cli') {
        return false;
    }

    // Check if stdin is available
    if (!is_resource(STDIN)) {
        return false;
    }

    // Check if stdin has data or is interactive
    $stat = fstat(STDIN);
    return $stat !== false && ($stat['size'] > 0 || posix_isatty(STDIN));
}

function displayArgumentErrors(string $title, array $errors): void
{
    echo "Error: {$title}\n";
    foreach ($errors as $error) {
        echo "  - {$error}\n";
    }
    echo "\n";
}

function displayUsageHint(): void
{
    echo "Use --help for detailed usage information.\n";
    echo "\nQuick examples:\n";
    echo "  php usage-scanner.php --search=\"User,Logger::log\" --path=\"/app\"\n";
    echo "  echo \"User\" | php usage-scanner.php --stdin\n";
}

function displayUsage(): void
{
    echo "PHP Usage Scanner - Find method and class usage in PHP codebases\n\n";

    echo "Usage: php usage-scanner.php [OPTIONS]\n\n";

    echo "Required (one of):\n";
    echo "  --search=\"Class1,Class2::method\"  Search targets (comma-separated)\n";
    echo "  --stdin                           Read search targets from stdin\n\n";

    echo "Search Target Format:\n";
    echo "  ClassName                         Find class usage (new, instanceof, type hints)\n";
    echo "  ClassName::methodName             Find method usage (calls, static calls)\n";
    echo "  Multiple targets                  Separate with commas or newlines\n\n";

    echo "Scan Options:\n";
    echo "  --path=\"/path/to/scan\"             Directory to scan (default: current directory)\n";
    echo "  --exclude=\"vendor,tests\"          Paths to exclude (comma-separated patterns)\n\n";

    echo "Output Options:\n";
    echo "  --csv=\"results.csv\"               Export detailed results to CSV\n";
    echo "  --csv-summary=\"summary.csv\"       Export summary results to CSV\n\n";

    echo "Cache Options:\n";
    echo "  --cache-dir=\"/tmp/cache\"           Cache directory (default: system temp)\n";
    echo "  --no-cache                        Disable caching\n";
    echo "  --clear-cache                     Clear all cached results and exit\n\n";

    echo "Help:\n";
    echo "  --help, -h                        Show this help message\n\n";

    echo "Examples:\n";
    echo "  # Basic usage\n";
    echo "  php usage-scanner.php --search=\"User,Logger::log\" --path=\"/app\"\n\n";

    echo "  # With exclusions and caching\n";
    echo "  php usage-scanner.php --search=\"Database::connect\" --exclude=\"vendor,tests\" --cache-dir=\"/tmp/cache\"\n\n";

    echo "  # Export to CSV\n";
    echo "  php usage-scanner.php --search=\"User\" --csv=\"user-usage.csv\" --csv-summary=\"summary.csv\"\n\n";

    echo "  # Interactive stdin input\n";
    echo "  php usage-scanner.php --stdin\n\n";

    echo "  # Piped input from file\n";
    echo "  echo -e \"User\\nLogger::log\\nDatabase::connect\" | php usage-scanner.php --stdin --csv=\"results.csv\"\n\n";

    echo "  # From file with comprehensive output\n";
    echo "  cat targets.txt | php usage-scanner.php --stdin --path=\"/app/src\" --exclude=\"vendor,tests\" --csv=\"detailed.csv\" --csv-summary=\"summary.csv\"\n\n";

    echo "Notes:\n";
    echo "  - Search targets can be mixed (classes and methods together)\n";
    echo "  - Exclude patterns match anywhere in the file path\n";
    echo "  - Cache automatically invalidates when files are modified\n";
    echo "  - CSV exports can be imported into Excel, Google Sheets, or databases\n";
}

// Main execution
try {
    list($searchTargets, $scanPath, $excludePaths, $cacheDir, $cacheEnabled, $csvFile, $csvSummaryFile) = parseArguments();

    echo "Memory-Efficient PHP Usage Scanner with Caching\n";
    echo str_repeat("=", 50) . "\n";
    echo "Searching for: " . implode(', ', $searchTargets) . "\n";
    echo "Path: {$scanPath}\n";
    if (!empty($excludePaths)) {
        echo "Excluding: " . implode(', ', $excludePaths) . "\n";
    }
    echo "Caching: " . ($cacheEnabled ? "Enabled ({$cacheDir})" : "Disabled") . "\n";
    if ($csvFile) {
        echo "CSV Export: {$csvFile}\n";
    }
    if ($csvSummaryFile) {
        echo "CSV Summary: {$csvSummaryFile}\n";
    }
    echo "Initial memory usage: " . (function() {
            $bytes = memory_get_usage(true);
            return round($bytes / 1024 / 1024, 2) . ' MB';
        })() . "\n";
    echo "\n";

    $cacheManager = $cacheEnabled ? new CacheManager($cacheDir, $searchTargets, $excludePaths) : null;
    $scanner = new StreamingFileScanner($searchTargets, $excludePaths, $cacheManager);
    $scanner->scanDirectory($scanPath);
    $scanner->displayResults();

    // Export to CSV if requested
    if ($csvFile) {
        $scanner->exportToCsv($csvFile);
    }

    if ($csvSummaryFile) {
        $scanner->exportSummaryToCsv($csvSummaryFile);
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}