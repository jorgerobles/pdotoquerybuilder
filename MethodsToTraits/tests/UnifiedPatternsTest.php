<?php

namespace JDR\Rector\MethodsToTraits\Tests;

use InvalidArgumentException;
use JDR\Rector\MethodsToTraits\MethodsToTraitsRector;
use PhpParser\Modifiers;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PHPUnit\Framework\TestCase;

class UnifiedPatternsTest extends TestCase
{
    private object $rector;

    protected function setUp(): void
    {
        $this->rector = TestingHelper::proxy(new MethodsToTraitsRector());
    }

    public function testSingleMethodMapping(): void
    {
        $this->rector->configure([
            'extract_patterns' => [
                // 1:1 mapping with auto trait name
                ['type' => 'methods', 'methods' => ['validateEmail']]
            ]
        ]);

        // Should create validateEmailTrait
        $this->assertTrue($this->rector->shouldExtractMethod($this->createMethodNode('validateEmail')));
        $this->assertEquals('ValidateEmail', $this->rector->determineMethodGroup($this->createMethodNode('validateEmail')));
    }

    public function testSingleMethodMappingWithExplicitName(): void
    {
        $this->rector->configure([
            'extract_patterns' => [
                // 1:1 mapping with explicit trait name
                [
                    'type' => 'methods',
                    'methods' => ['hashPassword'],
                    'trait_name' => 'PasswordHasherTrait'
                ]
            ]
        ]);

        $this->assertTrue($this->rector->shouldExtractMethod($this->createMethodNode('hashPassword')));
        $this->assertEquals('PasswordHasher', $this->rector->determineMethodGroup($this->createMethodNode('hashPassword')));
    }

    public function testMultipleMethodMapping(): void
    {
        $this->rector->configure([
            'extract_patterns' => [
                // N:1 mapping
                [
                    'type' => 'methods',
                    'trait_name' => 'UserValidationTrait',
                    'methods' => ['validateEmail', 'validatePhone', 'validateAge']
                ]
            ]
        ]);

        $this->assertTrue($this->rector->shouldExtractMethod($this->createMethodNode('validateEmail')));
        $this->assertTrue($this->rector->shouldExtractMethod($this->createMethodNode('validatePhone')));
        $this->assertTrue($this->rector->shouldExtractMethod($this->createMethodNode('validateAge')));

        // All should map to the same group
        $this->assertEquals('UserValidation', $this->rector->determineMethodGroup($this->createMethodNode('validateEmail')));
        $this->assertEquals('UserValidation', $this->rector->determineMethodGroup($this->createMethodNode('validatePhone')));
        $this->assertEquals('UserValidation', $this->rector->determineMethodGroup($this->createMethodNode('validateAge')));
    }

    public function testMixedPatterns(): void
    {
        $this->rector->configure([
            'extract_patterns' => [
                // Pattern-based
                ['type' => 'prefix', 'value' => 'format'],

                // 1:1 explicit methods
                ['type' => 'methods', 'methods' => ['generateUuid']],

                // N:1 explicit methods
                [
                    'type' => 'methods',
                    'trait_name' => 'CalculatorTrait',
                    'methods' => ['calculateTax', 'calculateDiscount']
                ]
            ]
        ]);

        // Pattern-based should work
        $this->assertTrue($this->rector->shouldExtractMethod($this->createMethodNode('formatEmail')));

        // 1:1 should work
        $this->assertTrue($this->rector->shouldExtractMethod($this->createMethodNode('generateUuid')));

        // N:1 should work
        $this->assertTrue($this->rector->shouldExtractMethod($this->createMethodNode('calculateTax')));
        $this->assertTrue($this->rector->shouldExtractMethod($this->createMethodNode('calculateDiscount')));

        // Should map to correct groups
        $this->assertEquals('Calculator', $this->rector->determineMethodGroup($this->createMethodNode('calculateTax')));
        $this->assertEquals('Calculator', $this->rector->determineMethodGroup($this->createMethodNode('calculateDiscount')));
    }

    public function testConfigurationValidation(): void
    {
        // Should throw exception for multiple methods without trait_name
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Multiple methods [validateEmail, validatePhone] require explicit 'trait_name'");

        $this->rector->configure([
            'extract_patterns' => [
                [
                    'type' => 'methods',
                    'methods' => ['validateEmail', 'validatePhone']
                    // Missing trait_name for multiple methods
                ]
            ]
        ]);
    }

    public function testEmptyMethodsArray(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("'methods' type requires a non-empty 'methods' array");

        $this->rector->configure([
            'extract_patterns' => [
                [
                    'type' => 'methods',
                    'methods' => []
                ]
            ]
        ]);
    }

    public function testMethodNotInPattern(): void
    {
        $this->rector->configure([
            'extract_patterns' => [
                ['type' => 'methods', 'methods' => ['validateEmail']]
            ]
        ]);

        // Method not in pattern should not be extracted
        $this->assertFalse($this->rector->shouldExtractMethod($this->createMethodNode('saveUser')));
    }

    public function testMinimumMethodsFilterSkippedForExplicitGroups(): void
    {
        $this->rector->configure([
            'extract_patterns' => [
                [
                    'type' => 'methods',
                    'trait_name' => 'SingleMethodTrait',
                    'methods' => ['specialMethod']
                ]
            ],
            'min_methods_per_trait' => 3  // Normally would block single method
        ]);

        $methods = [
            [
                'name' => 'specialMethod',
                'group' => 'SingleMethod',
                'method' => $this->createMethodNode('specialMethod'),
                'dependencies' => ['properties' => [], 'constants' => [], 'methods' => []]
            ]
        ];

        $grouped = $this->rector->groupMethodsForExtraction($methods);

        // Should not be filtered out despite min_methods_per_trait = 3
        $this->assertArrayHasKey('SingleMethod', $grouped);
        $this->assertCount(1, $grouped['SingleMethod']);
    }

    private function createMethodNode(string $name): ClassMethod
    {
        return new ClassMethod(
            new Identifier($name),
            [
                'flags' => Modifiers::PUBLIC,
                'stmts' => []
            ]
        );
    }
}