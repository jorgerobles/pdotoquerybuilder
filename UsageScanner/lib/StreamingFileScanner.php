<?php

use PhpParser\Error;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PhpParser\PhpVersion;


class StreamingFileScanner
{
    private \PhpParser\Parser $parser;
    private NodeTraverser $traverser;
    private MemoryEfficientUsageScanner $visitor;
    private array $excludePaths;
    private $outputHandle;
    private string|false $tempFile;
    private int $totalFiles = 0;
    private int $processedFiles = 0;
    private int $totalUsages = 0;
    private int $filesWithUsages = 0;
    private int $lastProgressUpdate = 0;
    private ?CacheManager $cacheManager;
    private int $skippedFiles = 0;


    public function __construct(array $searchTargets, array $excludePaths = [], ?CacheManager $cacheManager = null, ?PhpVersion $version = null)
    {

        $this->parser = $this->createParser($version);
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

    public function __destruct()
    {
        if ($this->outputHandle) {
            fclose($this->outputHandle);
        }
        if ($this->tempFile && file_exists($this->tempFile)) {
            unlink($this->tempFile);
        }
    }

    private function createParser(?string $version = null): \PhpParser\Parser
    {
        return (new ParserFactory)->createForVersion($version ?? PhpVersion::fromString('7.4'));
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

    public function getStats(): array
    {
        return [
            'totalFiles' => $this->totalFiles,
            'processedFiles' => $this->processedFiles,
            'filesWithUsages' => $this->filesWithUsages,
            'totalUsages' => $this->totalUsages,
            'skippedFiles' => $this->skippedFiles
        ];
    }
}