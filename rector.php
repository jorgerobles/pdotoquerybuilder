<?php

// Enhanced rector.php configuration for better formatting
declare(strict_types=1);

use App\Rector\Doctrine\PdoToQueryBuilderRector;
use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withRules([
        PdoToQueryBuilderRector::class,
    ])
    ->withPaths([
        __DIR__ . '/temp',
    ])
    // Enable proper imports and formatting
    ->withImportNames(
        importShortClasses: true,
        removeUnusedImports: true,
    )
    // Enable parallel processing for better performance
    ->withParallel()
    // Set up caching for faster subsequent runs
    ->withCache(__DIR__ . '/var/cache/rector')
    // Configure formatting options
    ->withPhpSets(php81: true)
    // Add code quality rules that help with formatting
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
        privatization: true,
        earlyReturn: true,
    );