<?php
/**
 * Memory-Efficient PHP Method and Class Usage Scanner with Caching and Type Inference
 *
 * Optimized for large codebases with minimal memory footprint, intelligent caching,
 * and context-aware type inference for accurate method detection.
 *
 * Usage: php UsageScanner.php --search="ClassName,AnotherClass::method" --path="/path/to/scan" [OPTIONS]
 */

error_reporting(E_ALL & ~E_DEPRECATED);

require_once 'vendor/autoload.php';

// Include our scanner classes
require_once 'lib/TypeInferenceEngine.php';
require_once 'lib/MemoryEfficientUsageScanner.php';
require_once 'lib/CacheManager.php';
require_once 'lib/StreamingFileScanner.php';



function parseArguments(): array
{
    $options = getopt('', [
        'search:', 'path:', 'exclude:', 'cache-dir:',
        'no-cache', 'clear-cache', 'stdin', 'csv:', 'csv-summary:',
        'memory-report:', // NEW: Export memory analysis to CSV
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

    // Validate memory report path
    $memoryReportFile = validateCsvPath($options['memory-report'] ?? null, 'memory-report');

    return [$searchTargets, $scanPath, $excludePaths, $cacheDir, $cacheEnabled,
        $csvFile, $csvSummaryFile, $memoryReportFile];
}

function displayUsage(): void
{
    echo "PHP Usage Scanner - Find method and class usage in PHP codebases\n\n";

    echo "Usage: php UsageScanner.php [OPTIONS]\n\n";

    // ... existing usage sections ...

    echo "Output Options:\n";
    echo "  --csv=\"results.csv\"               Export detailed results to CSV\n";
    echo "  --csv-summary=\"summary.csv\"       Export summary results to CSV\n";
    echo "  --memory-report=\"memory.csv\"      Export memory analysis to CSV\n\n";

    // ... rest of existing usage text ...

    echo "Memory Analysis Examples:\n";
    echo "  # Track memory usage and get detailed report\n";
    echo "  php UsageScanner.php --search=\"User\" --memory-report=\"memory-analysis.csv\"\n\n";

    echo "  # Find files causing memory issues\n";
    echo "  php UsageScanner.php --search=\"User\" --path=\"/large/codebase\" --memory-report=\"memory-hogs.csv\"\n\n";

    echo "  # Complete analysis with all reports\n";
    echo "  php UsageScanner.php --search=\"User\" --csv=\"results.csv\" --csv-summary=\"summary.csv\" --memory-report=\"memory.csv\"\n\n";
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
        if (strpos($path, '/') === 0 && !file_exists($path)) {
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
    echo "  php UsageScanner.php --search=\"User,Logger::log\" --path=\"/app\"\n";
    echo "  echo \"User\" | php UsageScanner.php --stdin\n";
}



// Main execution
try {
    list($searchTargets, $scanPath, $excludePaths, $cacheDir, $cacheEnabled,
        $csvFile, $csvSummaryFile, $memoryReportFile) = parseArguments();

    echo "PHP Usage Scanner with Type Inference\n";
    echo str_repeat("=", 40) . "\n";
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
    if ($memoryReportFile) {
        echo "Memory Report: {$memoryReportFile}\n";
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

    // Export memory report if requested
    if ($memoryReportFile) {
        $scanner->exportMemoryReport($memoryReportFile);
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}