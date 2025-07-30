<?php

// Enhanced rector.php configuration with configurable parameters
declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\DowngradeLevelSetList;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/MethodsToTraits/src',
        __DIR__ . '/PdoToQb/src',
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
    ->withSets([
        DowngradeLevelSetList::DOWN_TO_PHP_74
    ])

    // Add code quality rules that help with formatting
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
        privatization: true,
        earlyReturn: true,
    );