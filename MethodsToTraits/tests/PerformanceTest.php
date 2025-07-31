<?php

namespace JDR\Rector\MethodsToTraits\Tests;

use JDR\Rector\MethodsToTraits\MethodsToTraitsRector;
use PhpParser\Modifiers;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Return_;
use PHPUnit\Framework\TestCase;

class PerformanceTest extends TestCase
{
    public function testLargeClassProcessing(): void
    {
        $start = microtime(true);

        // Create a large class with many methods
        $largeClass = $this->createLargeClass(100);

        $rector = new MethodsToTraitsRector();
        $rector->configure([
            'extract_patterns' => [
                ['type' => 'prefix', 'value' => 'validate'],
                ['type' => 'prefix', 'value' => 'format'],
            ],
            'generate_files' => false, // Skip file generation for performance
        ]);

        $result = $rector->refactor($largeClass);

        $elapsed = microtime(true) - $start;

        // Should process within reasonable time (adjust threshold as needed)
        $this->assertLessThan(5.0, $elapsed, 'Processing took too long');
        $this->assertNotNull($result);
    }

    private function createLargeClass(int $methodCount): Class_
    {
        $methods = [];

        for ($i = 0; $i < $methodCount; $i++) {
            $prefix = $i % 2 === 0 ? 'validate' : 'format';
            $methods[] = new ClassMethod(
                new Identifier($prefix . 'Method' . $i),
                [
                    'flags' => Modifiers::PUBLIC,
                    'stmts' => [
                        new Return_(
                            new String_('result')
                        )
                    ]
                ]
            );
        }

        return new Class_(
            new Identifier('LargeClass'),
            ['stmts' => $methods]
        );
    }
}