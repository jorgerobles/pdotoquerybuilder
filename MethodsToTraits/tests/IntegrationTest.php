<?php

namespace JDR\Rector\MethodsToTraits\Tests;


use Closure;
use JDR\Rector\MethodsToTraits\MethodsToTraitsRector;
use PhpParser\Lexer;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\Parser\Php7;
use PHPUnit\Framework\TestCase;

class IntegrationTest extends TestCase
{
    private string $tempDir;
    private object $rector;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/rector_traits_test_' . uniqid();
        mkdir($this->tempDir, 0777, true);

        $this->rector = $this->proxy(new MethodsToTraitsRector());
        $this->rector->configure([
            'trait_namespace' => 'Test\\Traits',
            'output_directory' => $this->tempDir,
            'generate_files' => true,
        ]);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    public function testFullExtractionWorkflow(): void
    {
        $sourceCode = <<<'EOF'
            <?php
            class UserService
            {
                public function validateEmail(string $email): bool
                {
                    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
                }
            
                public function validatePhone(string $phone): bool
                {
                    return preg_match("/^\+?[1-9]\d{1,14}$/", $phone);
                }
            
                public function formatEmail(string $email): string
                {
                    return strtolower(trim($email));
                }
            
                public function saveUser(array $data): void
                {
                    // Core logic
                }
            }
        EOF;

        $parser = new Php7(new Lexer());
        $ast = $parser->parse($sourceCode);
        $classNode = $ast[0];

        $modifiedClass = $this->rector->refactor($classNode);

        // Verify traits were added
        $this->assertNotNull($modifiedClass);
        $this->assertContains('ValidationTrait', $this->getTraitNames($modifiedClass));

        // Verify trait files were generated
        $this->assertFileExists($this->tempDir . '/ValidationTrait.php');
    }

    public function testTraitFileGeneration(): void
    {
        $traitName = 'TestTrait';
        $methods = [
            [
                'method' => $this->createMethodNode('testMethod'),
                'name' => 'testMethod',
                'dependencies' => ['properties' => [], 'constants' => [], 'methods' => []]
            ]
        ];

        $traitNode = $this->rector->createTraitNode($traitName, $methods, $this->createClassNode());
        $this->rector->generateTraitFile($traitName, $traitNode);

        $this->assertFileExists($this->tempDir . '/TestTrait.php');

        $content = file_get_contents($this->tempDir . '/TestTrait.php');
        $this->assertStringContainsString('trait TestTrait', $content);
        $this->assertStringContainsString('public function testMethod', $content);
    }

    private function getTraitNames(Class_ $class): array
    {
        $traitNames = [];
        foreach ($class->stmts as $stmt) {
            if ($stmt instanceof TraitUse) {
                foreach ($stmt->traits as $trait) {
                    $traitNames[] = $trait->toString();
                }
            }
        }
        return $traitNames;
    }

    private function createClassNode(): Class_
    {
        return new Class_(
            new Identifier('TestClass')
        );
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }


    private function proxy(object $instance): object
    {
        return new class($instance){
            private object $instance;

            public function __construct($instance){
                $this->instance = $instance;
            }
            public function __call(string $name, array $arguments): mixed
            {
                return Closure::bind(fn()=>$name(...$arguments), $this->instance);
            }
        };
    }

    private function createMethodNode(string $string): ClassMethod
    {
        return new ClassMethod($string);
    }


}