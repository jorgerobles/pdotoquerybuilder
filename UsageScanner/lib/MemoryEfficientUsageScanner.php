<?php

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

class MemoryEfficientUsageScanner extends NodeVisitorAbstract
{
    private array $searchTargets;
    private string $currentFile;
    private array $currentUsages = [];
    private TypeInferenceEngine $typeInference;

    public function __construct(array $searchTargets)
    {
        $this->searchTargets = $this->parseSearchTargets($searchTargets);
        $this->typeInference = new TypeInferenceEngine();
    }

    public function setCurrentFile(string $file): void
    {
        $this->currentFile = $file;
        $this->currentUsages = []; // Reset for each file
        $this->typeInference->reset(); // Reset type inference for each file
    }

    public function getCurrentUsages(): array
    {
        return $this->currentUsages;
    }

    private function parseSearchTargets(array $targets): array
    {
        $parsed = [
            'classes' => [],
            'methods' => [],
        ];

        foreach ($targets as $target) {
            if (strpos($target, '::') !== false) {
                list($class, $method) = explode('::', $target, 2);
                $parsed['methods'][$target] = [
                    'class' => trim($class),
                    'method' => trim($method),
                ];
            } else {
                $parsed['classes'][trim($target)] = true;
            }
        }

        return $parsed;
    }

    public function enterNode(Node $node): null
    {
        // Handle namespace declarations
        if ($node instanceof Node\Stmt\Namespace_) {
            $namespace = $node->name ? $node->name->toString() : null;
            $this->typeInference->setCurrentNamespace($namespace);
        }

        // Handle use statements
        elseif ($node instanceof Node\Stmt\Use_) {
            foreach ($node->uses as $useUse) {
                $alias = $useUse->getAlias() ? $useUse->getAlias()->toString() : $useUse->name->getLast();
                $fullName = $useUse->name->toString();
                $this->typeInference->addUseStatement($alias, $fullName);
            }
        }

        // Handle class declarations
        elseif ($node instanceof Node\Stmt\Class_) {
            $className = $node->name->toString();
            $this->typeInference->setCurrentClass($className);
        }

        // Handle class properties
        elseif ($node instanceof Node\Stmt\Property) {
            $this->handlePropertyDeclaration($node);
        }

        // Handle variable assignments for type inference
        elseif ($node instanceof Node\Expr\Assign) {
            $this->handleAssignment($node);
        }

        // Check for new ClassName() usage
        elseif ($node instanceof Node\Expr\New_ && $node->class instanceof Node\Name) {
            $className = $node->class->toString();
            $resolvedClass = $this->typeInference->resolveType($className);

            if (isset($this->searchTargets['classes'][$className]) ||
                ($resolvedClass && isset($this->searchTargets['classes'][$resolvedClass]))) {
                $targetName = isset($this->searchTargets['classes'][$className]) ? $className : $resolvedClass;
                $this->recordUsage($targetName, 'new', $node->getLine());
            }
        }

        // Check for $obj->method() usage (with type inference)
        elseif ($node instanceof Node\Expr\MethodCall && $node->name instanceof Node\Identifier) {
            $this->handleInstanceMethodCall($node);
        }

        // Check for ClassName::method() static calls
        elseif ($node instanceof Node\Expr\StaticCall) {
            if ($node->class instanceof Node\Name && $node->name instanceof Node\Identifier) {
                $className = $node->class->toString();
                $methodName = $node->name->toString();
                $resolvedClass = $this->typeInference->resolveType($className);

                $fullName = "{$className}::{$methodName}";
                $resolvedFullName = $resolvedClass ? "{$resolvedClass}::{$methodName}" : null;

                if (isset($this->searchTargets['methods'][$fullName]) ||
                    ($resolvedFullName && isset($this->searchTargets['methods'][$resolvedFullName]))) {
                    $targetName = isset($this->searchTargets['methods'][$fullName]) ? $fullName : $resolvedFullName;
                    $this->recordUsage($targetName, 'static_call', $node->getLine());
                }
            }
        }

        // Check for class name usage in type hints, instanceof, etc.
        elseif ($node instanceof Node\Name) {
            $className = $node->toString();
            $resolvedClass = $this->typeInference->resolveType($className);

            if (!$this->isClassDefinition($node)) {
                if (isset($this->searchTargets['classes'][$className]) ||
                    ($resolvedClass && isset($this->searchTargets['classes'][$resolvedClass]))) {
                    $targetName = isset($this->searchTargets['classes'][$className]) ? $className : $resolvedClass;
                    $this->recordUsage($targetName, 'reference', $node->getLine());
                }
            }
        }

        return null;
    }

    private function handlePropertyDeclaration(Node\Stmt\Property $node): void
    {
        $type = null;
        $docType = null;

        // Get type from type hint
        if ($node->type) {
            if ($node->type instanceof Node\Name) {
                $type = $node->type->toString();
            } elseif ($node->type instanceof Node\Identifier) {
                $type = $node->type->toString();
            }
        }

        // Try to extract type from docblock
        $comments = $node->getComments();
        foreach ($comments as $comment) {
            if (preg_match('/@var\s+([^\s]+)/', $comment->getText(), $matches)) {
                $docType = trim($matches[1]);
                break;
            }
        }

        // Add property type for each property in this declaration
        foreach ($node->props as $prop) {
            $propertyName = $prop->name->toString();
            $this->typeInference->addClassProperty($propertyName, $type, $docType);
        }
    }

    private function handleAssignment(Node\Expr\Assign $node): void
    {
        if ($node->var instanceof Node\Expr\Variable && is_string($node->var->name)) {
            $varName = $node->var->name;

            if ($node->expr instanceof Node\Expr\New_ && $node->expr->class instanceof Node\Name) {
                $className = $node->expr->class->toString();
                $resolvedClass = $this->typeInference->resolveType($className);
                if ($resolvedClass) {
                    $this->typeInference->addVariableType($varName, $resolvedClass);
                }
            }
        }
    }

    private function handleInstanceMethodCall(Node\Expr\MethodCall $node): void
    {
        $methodName = $node->name->toString();
        $inferredType = null;

        // Try to infer the type of the object being called
        if ($node->var instanceof Node\Expr\PropertyFetch) {
            // $this->property->method() case
            if ($node->var->var instanceof Node\Expr\Variable &&
                $node->var->var->name === 'this' &&
                $node->var->name instanceof Node\Identifier) {

                $propertyName = $node->var->name->toString();
                $inferredType = $this->typeInference->getPropertyType($propertyName);
            }
        } elseif ($node->var instanceof Node\Expr\Variable && is_string($node->var->name)) {
            // $variable->method() case
            $varName = $node->var->name;
            $inferredType = $this->typeInference->getVariableType($varName);
        }

        if ($inferredType) {
            // Check if this method belongs to any of our target methods
            foreach ($this->searchTargets['methods'] as $fullTarget => $targetInfo) {
                if ($targetInfo['method'] === $methodName) {
                    $targetClass = $targetInfo['class'];

                    // Match if the inferred type matches the target class
                    if ($this->typeInference->matchesTargetClass($inferredType, $targetClass)) {
                        $shortClassName = $this->getShortClassName($inferredType);
                        $this->recordUsage($fullTarget, 'method_call', $node->getLine(),
                            "{$shortClassName}::{$methodName}");
                    }
                }
            }
        }
    }

    private function getShortClassName(string $fullClassName): string
    {
        $parts = explode('\\', $fullClassName);
        return end($parts);
    }

    private function isClassDefinition(Node\Name $node): bool
    {
        $parent = $node->getAttribute('parent');
        return $parent instanceof Node\Stmt\Class_ ||
            $parent instanceof Node\Stmt\Interface_ ||
            $parent instanceof Node\Stmt\Trait_;
    }

    private function recordUsage(string $target, string $type, int $line, ?string $display = null): void
    {
        if (!isset($this->currentUsages[$target])) {
            $this->currentUsages[$target] = [
                'count' => 0,
                'lines' => [],
                'types' => []
            ];
        }

        $this->currentUsages[$target]['count']++;
        $this->currentUsages[$target]['lines'][] = $line;
        $this->currentUsages[$target]['types'][] = $type;
    }

    public function getTypeInference(): TypeInferenceEngine
    {
        return $this->typeInference;
    }
}