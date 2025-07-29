<?php

// Enhanced rector.php configuration with configurable parameters
declare(strict_types=1);

use Rector\Config\RectorConfig;
use JDR\Rector\PdoToQb\PdoToQueryBuilderRector;

return RectorConfig::configure()
    ->withRules([
        // Default configuration
        PdoToQueryBuilderRector::class,

        // Or with custom configuration:
        // new PdoToQueryBuilderRector(
        //     pdoVariableNames: ['pdo', 'db', 'connection', 'database'],
        //     connectionClause: 'getConnection()'  // Method with parentheses
        // ),
    ])
    ->withConfiguredRule(
        PdoToQueryBuilderRector::class,
        [
            // Configuration for different project setups:

            // Standard Doctrine DBAL setup
            'pdoVariableNames' => ['pdo', 'db', 'connection'],
            'connectionClause' => 'connection',  // Property access

            // Alternative configurations:
            // For Symfony projects with EntityManager method
            // 'pdoVariableNames' => ['pdo', 'db', 'connection', 'database'],
            // 'connectionClause' => 'getConnection()',  // Method call

            // For projects with custom connection wrapper method
            // 'pdoVariableNames' => ['database', 'dbConn', 'sqlConnection'],
            // 'connectionClause' => 'getDatabaseManager()',  // Method call
        ]
    )
    ->withPaths([
        __DIR__ . '/src',
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
    ->withPhpSets(php74: true)
    // Add code quality rules that help with formatting
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
        privatization: true,
        earlyReturn: true,
    );