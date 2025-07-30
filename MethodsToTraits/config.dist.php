<?php

declare(strict_types=1);

use JDR\Rector\MethodsToTraits\MethodsToTraitsRector;
use Rector\Config\RectorConfig;

return static function (RectorConfig $rectorConfig): void {
    // Basic configuration
    $rectorConfig->paths([
        __DIR__ . '/src',
        __DIR__ . '/app',
    ]);

    // Configure the PublicMethodsToTraitsRector with custom settings
    $rectorConfig->ruleWithConfiguration(MethodsToTraitsRector::class, [
        // Define extraction patterns
        'extract_patterns' => [
            [
                'type' => 'prefix',
                'value' => 'validate'
            ],
            [
                'type' => 'prefix',
                'value' => 'format'
            ],
            [
                'type' => 'prefix',
                'value' => 'calculate'
            ],
            [
                'type' => 'annotation',
                'value' => 'extractable'
            ],
            [
                'type' => 'attribute',
                'value' => 'Extractable'
            ]
        ],

        // Trait configuration
        'trait_namespace' => 'App\\Traits',
        'output_directory' => __DIR__ . '/src/Traits',

        // Grouping strategy: 'functionality', 'prefix', 'annotation', 'attribute'
        'group_by' => 'functionality',

        // Minimum methods required to create a trait
        'min_methods_per_trait' => 2,

        // Methods to exclude from extraction
        'exclude_methods' => [
            '__construct',
            '__destruct',
            '__call',
            '__get',
            '__set',
            '__toString',
            'main',
            'execute',
            'handle'
        ],

        // Configuration options
        'preserve_visibility' => true,
        'add_trait_use' => true,
        'generate_files' => true
    ]);

    // NEW: Direct 1:1 method to trait mapping example
    $rectorConfig->ruleWithConfiguration(MethodsToTraitsRector::class, [
        // Enable direct mapping mode
        'use_direct_mapping' => true,

        // Map specific methods to specific traits
        'method_to_trait_map' => [
            'validateEmail' => 'EmailValidationTrait',
            'validatePhone' => 'PhoneValidationTrait',
            'formatCurrency' => 'CurrencyFormatterTrait',
            'calculateTax' => 'TaxCalculatorTrait',
            'generateInvoiceNumber' => 'InvoiceGeneratorTrait',
        ],

        // Trait configuration
        'trait_namespace' => 'App\\Traits\\Specific',
        'output_directory' => __DIR__ . '/src/Traits/Specific',

        // Other options still work
        'preserve_visibility' => true,
        'add_trait_use' => true,
        'generate_files' => true,
    ]);

    // Enable imports
    $rectorConfig->importNames();
    $rectorConfig->removeUnusedImports();
};