<?php

class CacheManager
{
    private string $cacheDir;
    private string $searchHash;
    private bool $cacheEnabled;
    private array $cacheStats = [
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

    public function isEnabled(): bool
    {
        return $this->cacheEnabled;
    }

    public function getSearchHash(): string
    {
        return $this->searchHash;
    }
}