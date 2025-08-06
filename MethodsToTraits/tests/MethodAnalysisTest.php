<?php

namespace JDR\Rector\MethodsToTraits\Tests;

use Closure;
use JDR\Rector\MethodsToTraits\MethodsToTraitsRector;
use PhpParser\Node\Const_;
use PhpParser\Node\Expr\BinaryOp\Mul;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\DNumber;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\PropertyProperty;
use PhpParser\Node\Stmt\Return_;
use PHPUnit\Framework\TestCase;

class MethodAnalysisTest extends TestCase
{
    private object $rector;

    protected function setUp(): void
    {
        $this->rector = TestingHelper::proxy(new MethodsToTraitsRector());
    }

    public function testMethodPatternMatching(): void
    {
        $this->rector->configure([
            'extract_patterns' => [
                ['type' => 'prefix', 'value' => 'validate']
            ]
        ]);

        // Test that validateEmail matches the pattern
        $method = $this->createMethodNode('validateEmail');
        $this->assertTrue($this->rector->shouldExtractMethod($method));

        // Test that saveUser doesn't match the pattern
        $method = $this->createMethodNode('saveUser');
        $this->assertFalse($this->rector->shouldExtractMethod($method));
    }

    public function testGroupingByFunctionality(): void
    {
        $this->rector->configure(['group_by' => 'functionality']);

        $this->assertEquals('Validation', $this->rector->determineMethodGroup('validateEmail'));
        $this->assertEquals('Formatting', $this->rector->determineMethodGroup('formatName'));
        $this->assertEquals('Calculation', $this->rector->determineMethodGroup('calculateTax'));
        $this->assertEquals('Generation', $this->rector->determineMethodGroup('generateId'));
        $this->assertEquals('Parsing', $this->rector->determineMethodGroup('parseJson'));
        $this->assertEquals('Utility', $this->rector->determineMethodGroup('getId'));
    }

    public function testGroupingByPrefix(): void
    {
        $this->rector->configure(['group_by' => 'prefix']);

        $this->assertEquals('Validate', $this->rector->determineMethodGroup('validateEmail'));
        $this->assertEquals('Format', $this->rector->determineMethodGroup('formatName'));
        $this->assertEquals('Calculate', $this->rector->determineMethodGroup('calculateTax'));
        $this->assertEquals('Utility', $this->rector->determineMethodGroup('getData'));
    }

    public function testDependencyAnalysis(): void
    {
        $classNode = $this->createClassWithDependencies();
        $methodNode = $this->createMethodWithDependencies();

        $dependencies = $this->rector->findMethodDependencies($methodNode, $classNode);

        $this->assertContains('validator', $dependencies['properties']);
        $this->assertContains('TAX_RATE', $dependencies['constants']);
    }

    public function testMinimumMethodsFilter(): void
    {
        $this->rector->configure(['min_methods_per_trait' => 3]);

        $methods = [
            ['group' => 'Validation', 'method' => 'validateEmail'],
            ['group' => 'Validation', 'method' => 'validatePhone'],
            ['group' => 'Formatting', 'method' => 'formatEmail'],
        ];

        $grouped = $this->rector->groupMethodsForExtraction($methods);

        // Should be empty because no group has 3+ methods
        $this->assertEmpty($grouped);
    }

    public function testExcludedMethods(): void
    {
        $this->rector->configure([
            'exclude_methods' => ['__construct', '__destruct', 'main']
        ]);

        $constructorMethod = $this->createMethodNode('__construct');
        $this->assertFalse($this->rector->shouldExtractMethod($constructorMethod));

        $regularMethod = $this->createMethodNode('validateEmail');
        $this->assertTrue($this->rector->shouldExtractMethod($regularMethod));
    }

    private function createMethodNode(string $name): ClassMethod
    {
        return new ClassMethod(
            new Identifier($name),
            [
                'flags' => Class_::MODIFIER_PUBLIC,
                'stmts' => []
            ]
        );
    }

    private function createClassWithDependencies(): Class_
    {
        return new Class_(
            new Identifier('TestClass'),
            [
                'stmts' => [
                    new Property(
                        Class_::MODIFIER_PRIVATE,
                        [new PropertyProperty('validator')]
                    ),
                    new ClassConst(
                        [new Const_('TAX_RATE', new DNumber(0.21))]
                    )
                ]
            ]
        );
    }

    private function createMethodWithDependencies(): ClassMethod
    {
        return new ClassMethod(
            new Identifier('testMethod'),
            [
                'flags' => Class_::MODIFIER_PUBLIC,
                'stmts' => [
                    new Return_(
                        new Mul(
                            new Variable('amount'),
                            new ClassConstFetch(
                                new Name('self'),
                                new Identifier('TAX_RATE')
                            )
                        )
                    )
                ]
            ]
        );
    }



}