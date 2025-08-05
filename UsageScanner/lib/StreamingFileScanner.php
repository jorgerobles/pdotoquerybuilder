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

    private int $memoryWarningThreshold = 200 * 1024 * 1024; // 200MB
    private int $memoryCriticalThreshold = 500 * 1024 * 1024; // 500MB
    private int $maxFileSize = 50 * 1024 * 1024; // 50MB
    private int $largeFileThreshold = 5 * 1024 * 1024; // 5MB
    private int $memoryChecksInterval = 10; // Check memory every N files

    private array $memoryHogFiles = []; // Track files that use lots of memory
    private int $memoryTrackingThreshold = 10 * 1024 * 1024; // 10MB
    private int $topMemoryFiles = 10; // Track top N memory-consuming files
    private int $currentPeakMemory = 0;
    private ?string $peakMemoryFile = null;


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



    private function recordMemoryHogFile(string $filePath, int $fileSize, int $memoryUsed, int $peakIncrease, int $totalMemoryAfter): void
    {
        $this->memoryHogFiles[] = [
            'file' => $filePath,
            'file_size' => $fileSize,
            'memory_used' => $memoryUsed,
            'peak_increase' => $peakIncrease,
            'total_memory_after' => $totalMemoryAfter,
            'timestamp' => microtime(true)
        ];

        // Keep only the top memory-consuming files
        if (count($this->memoryHogFiles) > $this->topMemoryFiles * 2) {
            // Sort by memory used (descending) and keep top entries
            usort($this->memoryHogFiles, function($a, $b) {
                return $b['memory_used'] <=> $a['memory_used'];
            });
            $this->memoryHogFiles = array_slice($this->memoryHogFiles, 0, $this->topMemoryFiles);
        }
    }

    private function updateProgress(): void
    {
        $now = microtime(true);

        // Update progress every 0.1 seconds or every 10 files, whichever comes first
        if ($now - $this->lastProgressUpdate >= 0.1 || $this->processedFiles % 10 === 0) {
            $percentage = $this->totalFiles > 0 ? ($this->processedFiles / $this->totalFiles) * 100 : 0;
            $currentMemory = memory_get_usage(true);

            // Print progress dots and percentage
            echo ".";

            if ($this->processedFiles % 50 === 0 || $this->processedFiles === $this->totalFiles) {
                $memoryUsage = $this->formatBytes($currentMemory);
                $cacheInfo = $this->skippedFiles > 0 ? " (Cached: {$this->skippedFiles})" : "";

                // Add memory warning if high
                $memoryWarning = $this->getMemoryWarning($currentMemory);

                echo sprintf(" [%d/%d] %.1f%% (Memory: %s)%s%s\n",
                    $this->processedFiles, $this->totalFiles, $percentage, $memoryUsage, $cacheInfo, $memoryWarning);
            }

            $this->lastProgressUpdate = $now;
        }
    }

    private function getMemoryWarning(int $currentMemory): string
    {
        if ($currentMemory >= 500 * 1024 * 1024) { // 500MB
            return " 🔥 CRITICAL";
        } elseif ($currentMemory >= 300 * 1024 * 1024) { // 300MB
            return " ⚠️  HIGH";
        } elseif ($currentMemory >= 200 * 1024 * 1024) { // 200MB
            return " ⚠️  ELEVATED";
        }
        return "";
    }

    public function displayResults(): void
    {
        if ($this->filesWithUsages === 0) {
            echo "No usages found.\n";
        } else {
            // ... existing results display code ...
        }

        $peakMemory = memory_get_peak_usage(true);
        $currentMemory = memory_get_usage(true);

        echo "\n" . str_repeat("=", 80) . "\n";
        echo "MEMORY ANALYSIS\n";
        echo str_repeat("=", 80) . "\n";

        echo "Current memory usage: {$this->formatBytes($currentMemory)}\n";
        echo "Peak memory usage: {$this->formatBytes($peakMemory)}\n";

        if ($this->peakMemoryFile) {
            echo "Peak memory caused by: {$this->peakMemoryFile}\n";
        }

        if (!empty($this->memoryHogFiles)) {
            echo "\nTop Memory-Consuming Files:\n";
            echo str_repeat("-", 80) . "\n";

            // Sort by memory used
            usort($this->memoryHogFiles, function($a, $b) {
                return $b['memory_used'] <=> $a['memory_used'];
            });

            $rank = 1;
            foreach (array_slice($this->memoryHogFiles, 0, $this->topMemoryFiles) as $fileInfo) {
                $relativePath = $this->getRelativePath($fileInfo['file']);
                echo sprintf("%2d. %s\n", $rank, $relativePath);
                echo sprintf("    File size: %s\n", $this->formatBytes($fileInfo['file_size']));
                echo sprintf("    Memory used: %s\n", $this->formatBytes($fileInfo['memory_used']));
                echo sprintf("    Peak increase: %s\n", $this->formatBytes($fileInfo['peak_increase']));
                echo sprintf("    Total memory after: %s\n", $this->formatBytes($fileInfo['total_memory_after']));
                echo "\n";
                $rank++;
            }

            // Analysis and recommendations
            $totalMemoryFromFiles = array_sum(array_column($this->memoryHogFiles, 'memory_used'));
            $avgFileSize = array_sum(array_column($this->memoryHogFiles, 'file_size')) / count($this->memoryHogFiles);

            echo "Analysis:\n";
            echo "- Files tracked: " . count($this->memoryHogFiles) . "\n";
            echo "- Total memory from tracked files: {$this->formatBytes($totalMemoryFromFiles)}\n";
            echo "- Average size of memory-heavy files: {$this->formatBytes($avgFileSize)}\n";

            // Recommendations
            echo "\nRecommendations:\n";
            if ($avgFileSize > 1024 * 1024) { // > 1MB average
                echo "- Consider excluding large auto-generated files\n";
            }
            if ($totalMemoryFromFiles > 100 * 1024 * 1024) { // > 100MB total
                echo "- Use --exclude to skip directories with large files\n";
                echo "- Consider processing smaller directory chunks\n";
            }

            // Show specific exclusion suggestions
            $directories = [];
            foreach ($this->memoryHogFiles as $fileInfo) {
                $dir = dirname($fileInfo['file']);
                $directories[$dir] = ($directories[$dir] ?? 0) + 1;
            }

            if (count($directories) > 0) {
                arsort($directories);
                echo "- Directories with many large files:\n";
                foreach (array_slice($directories, 0, 5, true) as $dir => $count) {
                    $relativeDir = $this->getRelativePath($dir);
                    echo "  * {$relativeDir} ({$count} files)\n";
                }
            }
        }

        echo "\n" . str_repeat("=", 80) . "\n";
        echo "SUMMARY\n";
        echo str_repeat("=", 80) . "\n";
        // ... existing summary code ...
    }

    private function getRelativePath(string $fullPath): string
    {
        // Try to make path relative to current working directory for cleaner output
        $cwd = getcwd();
        if ($cwd && strpos($fullPath, $cwd) === 0) {
            return '.' . substr($fullPath, strlen($cwd));
        }
        return $fullPath;
    }

    public function exportMemoryReport(string $csvFile): void
    {
        if (empty($this->memoryHogFiles)) {
            echo "No memory-intensive files to export.\n";
            return;
        }

        $csvHandle = fopen($csvFile, 'w');
        if (!$csvHandle) {
            throw new RuntimeException("Cannot create memory report CSV: {$csvFile}");
        }

        try {
            // Write CSV headers
            fputcsv($csvHandle, [
                'Rank',
                'File_Path',
                'File_Size_Bytes',
                'File_Size_Human',
                'Memory_Used_Bytes',
                'Memory_Used_Human',
                'Peak_Increase_Bytes',
                'Peak_Increase_Human',
                'Total_Memory_After_Bytes',
                'Total_Memory_After_Human'
            ]);

            // Sort by memory used
            usort($this->memoryHogFiles, function($a, $b) {
                return $b['memory_used'] <=> $a['memory_used'];
            });

            $rank = 1;
            foreach ($this->memoryHogFiles as $fileInfo) {
                fputcsv($csvHandle, [
                    $rank,
                    $fileInfo['file'],
                    $fileInfo['file_size'],
                    $this->formatBytes($fileInfo['file_size']),
                    $fileInfo['memory_used'],
                    $this->formatBytes($fileInfo['memory_used']),
                    $fileInfo['peak_increase'],
                    $this->formatBytes($fileInfo['peak_increase']),
                    $fileInfo['total_memory_after'],
                    $this->formatBytes($fileInfo['total_memory_after'])
                ]);
                $rank++;
            }

            echo "\nMemory report exported: {$csvFile}\n";
            echo "Exported " . count($this->memoryHogFiles) . " memory-intensive files.\n";

        } finally {
            fclose($csvHandle);
        }
    }



    private function checkMemoryUsage(): void
    {
        $currentMemory = memory_get_usage(true);
        $peakMemory = memory_get_peak_usage(true);

        if ($currentMemory >= $this->memoryCriticalThreshold) {
            echo "\n⚠️  CRITICAL: Memory usage is very high ({$this->formatBytes($currentMemory)})!\n";
            echo "   Consider using --exclude to skip large directories or files.\n";
            echo "   Peak memory: {$this->formatBytes($peakMemory)}\n\n";

            // Force aggressive garbage collection
            $this->forceMemoryCleanup();
        } elseif ($currentMemory >= $this->memoryWarningThreshold) {
            echo "\n⚠️  WARNING: High memory usage ({$this->formatBytes($currentMemory)})\n";
            echo "   Peak memory: {$this->formatBytes($peakMemory)}\n\n";

            // Standard garbage collection
            gc_collect_cycles();
        }
    }

    private function forceMemoryCleanup(): void
    {
        // Force garbage collection multiple times
        for ($i = 0; $i < 3; $i++) {
            gc_collect_cycles();
        }

        // Clear any internal caches that might be holding references
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
    }

    private function scanFile(string $filePath): void
    {
        $this->processedFiles++;

        try {
            $fileSize = filesize($filePath);

            // Skip extremely large files
            if ($fileSize > $this->maxFileSize) {
                echo "\n  [SKIPPED] File too large: {$filePath} ({$this->formatBytes($fileSize)}) - exceeds {$this->formatBytes($this->maxFileSize)} limit\n";
                return;
            }

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
            if ($fileSize > $this->largeFileThreshold) {
                echo "\n  [LARGE FILE] Processing {$filePath} ({$this->formatBytes($fileSize)})...";

                // Check memory before processing large file
                $memoryBefore = memory_get_usage(true);
                if ($memoryBefore > $this->memoryWarningThreshold) {
                    echo " (Memory before: {$this->formatBytes($memoryBefore)})";
                    $this->forceMemoryCleanup();
                }
            }

            $memoryBeforeFile = memory_get_usage(true);

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

            // Check memory usage after processing
            $memoryAfterFile = memory_get_usage(true);
            $memoryDiff = $memoryAfterFile - $memoryBeforeFile;

            if ($fileSize > $this->largeFileThreshold) {
                echo " Memory used: {$this->formatBytes($memoryDiff)}";

                if ($memoryDiff > 50 * 1024 * 1024) { // 50MB increase
                    echo " ⚠️  HIGH MEMORY USAGE!";
                }
                echo "\n";
            }

            // Unset large variables to free memory immediately
            unset($code, $ast, $usages);

            // Force garbage collection for large files
            if ($fileSize > $this->largeFileThreshold || $memoryDiff > 10 * 1024 * 1024) {
                gc_collect_cycles();
            }

        } catch (Error $e) {
            if ($fileSize > $this->largeFileThreshold) {
                echo "\n  [PARSE ERROR] Failed to parse large file: {$filePath} - {$e->getMessage()}\n";
            }
            // Continue silently for parse errors to avoid cluttering output
        } catch (Exception $e) {
            echo "\n  [ERROR] Exception processing {$filePath}: {$e->getMessage()}\n";
        }
    }

    public function setMemoryLimits(int $warningMB = 200, int $criticalMB = 500, int $maxFileMB = 50): void
    {
        $this->memoryWarningThreshold = $warningMB * 1024 * 1024;
        $this->memoryCriticalThreshold = $criticalMB * 1024 * 1024;
        $this->maxFileSize = $maxFileMB * 1024 * 1024;
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



    private function writeUsagesToFile(string $filePath, array $usages): void
    {
        $data = json_encode([
                'file' => $filePath,
                'usages' => $usages
            ]) . "\n";

        fwrite($this->outputHandle, $data);
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