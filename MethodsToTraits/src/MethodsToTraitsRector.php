<?php

declare(strict_types=1);

namespace JDR\Rector\MethodsToTraits;

use PhpParser\Node;
use PhpParser\Node\Expr\BinaryOp\LogicalAnd;
use PhpParser\Node\Expr\BinaryOp\LogicalOr;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Scalar\LNumber;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Case_;
use PhpParser\Node\Stmt\Catch_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Do_;
use PhpParser\Node\Stmt\ElseIf_;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\Switch_;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\Node\Name;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\While_;
use PhpParser\PrettyPrinter\Standard;
use Rector\Contract\Rector\ConfigurableRectorInterface;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * Rector rule that extracts public methods to traits
 *
 * Features:
 * - Extract methods by pattern (name, annotation, etc.)
 * - Group related methods into traits
 * - Handle method dependencies
 * - Generate trait files automatically
 * - Add trait usage to original classes
 */
final class MethodsToTraitsRector extends AbstractRector implements ConfigurableRectorInterface
{
    private array $config = [
        'extract_patterns' => [],
        'trait_namespace' => 'App\\Traits',
        'output_directory' => 'src/Traits',
        'group_by' => 'functionality', // 'functionality', 'prefix', 'annotation'
        'min_methods_per_trait' => 2,
        'exclude_methods' => ['__construct', '__destruct', '__call', '__get', '__set'],
        'preserve_visibility' => true,
        'add_trait_use' => true,
        'generate_files' => true,
        // NEW: 1:1 extraction mode
        'method_to_trait_map' => [], // ['methodName' => 'TraitName'] for direct mapping
        'use_direct_mapping' => false, // Enable 1:1 extraction mode
    ];

    private array $extractedTraits = [];
    private array $methodAnalysis = [];

    public function configure(array $configuration): void
    {
        $this->config = array_merge($this->config, $configuration);
    }

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Extract public methods to traits based on patterns and grouping strategies',
            [
                new CodeSample(
                    <<<'CODE_SAMPLE'
                    class UserService
                    {
                        public function validateEmail(string $email): bool
                        {
                            return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
                        }

                        public function validatePhone(string $phone): bool
                        {
                            return preg_match('/^\+?[1-9]\d{1,14}$/', $phone);
                        }

                        public function formatEmail(string $email): string
                        {
                            return strtolower(trim($email));
                        }

                        public function formatPhone(string $phone): string
                        {
                            return preg_replace('/[^\d+]/', '', $phone);
                        }

                        public function saveUser(array $data): void
                        {
                            // Business logic here
                        }
                    }
                    CODE_SAMPLE,
                    <<<'CODE_SAMPLE'
                    class UserService
                    {
                        use ValidationTrait;
                        use FormattingTrait;

                        public function saveUser(array $data): void
                        {
                            // Business logic here
                        }
                    }

                    // Generated: src/Traits/ValidationTrait.php
                    trait ValidationTrait
                    {
                        public function validateEmail(string $email): bool
                        {
                            return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
                        }

                        public function validatePhone(string $phone): bool
                        {
                            return preg_match('/^\+?[1-9]\d{1,14}$/', $phone);
                        }
                    }

                    // Generated: src/Traits/FormattingTrait.php
                    trait FormattingTrait
                    {
                        public function formatEmail(string $email): string
                        {
                            return strtolower(trim($email));
                        }

                        public function formatPhone(string $phone): string
                        {
                            return preg_replace('/[^\d+]/', '', $phone);
                        }
                    }
                    CODE_SAMPLE
                ),
            ]
        );
    }

    public function getNodeTypes(): array
    {
        return [Class_::class];
    }

    public function refactor(Node $node): ?Node
    {
        if (!$node instanceof Class_) {
            return null;
        }

        // Skip if class is abstract, interface, or trait
        if ($node->isAbstract() || $this->isInterface() || $this->isTrait()) {
            return null;
        }

        // Analyze methods in the class
        $methodsToExtract = $this->analyzeClassMethods($node);

        if ($methodsToExtract === []) {
            return null;
        }

        // Group methods by extraction strategy
        $groupedMethods = $this->groupMethodsForExtraction($methodsToExtract);

        if ($groupedMethods === []) {
            return null;
        }

        // Extract methods to traits
        $traitsGenerated = $this->extractMethodsToTraits($groupedMethods, $node);

        if ($traitsGenerated === []) {
            return null;
        }

        // Modify the original class
        return $this->modifyOriginalClass($node, $methodsToExtract, $traitsGenerated);
    }

    private function analyzeClassMethods(Class_ $class): array
    {
        $extractableMethods = [];

        foreach ($class->getMethods() as $method) {
            if (!$this->shouldExtractMethod($method)) {
                continue;
            }

            $methodInfo = $this->analyzeMethod($method, $class);
            if ($methodInfo['extractable']) {
                $extractableMethods[] = $methodInfo;
            }
        }

        return $extractableMethods;
    }

    private function shouldExtractMethod(ClassMethod $method): bool
    {
        // Check basic conditions
        if (!$method->isPublic()) {
            return false;
        }

        // Skip excluded methods
        $methodName = $method->name->toString();
        if (in_array($methodName, $this->config['exclude_methods'], true)) {
            return false;
        }

        // NEW: Check direct mapping mode first
        if ($this->config['use_direct_mapping']) {
            return isset($this->config['method_to_trait_map'][$methodName]);
        }

        // Check extract patterns
        if (!empty($this->config['extract_patterns'])) {
            return $this->matchesExtractionPattern($method);
        }

        return true;
    }

    private function matchesExtractionPattern(ClassMethod $method): bool
    {
        $methodName = $method->name->toString();

        foreach ($this->config['extract_patterns'] as $pattern) {
            switch ($pattern['type']) {
                case 'prefix':
                    if (strncmp($methodName, $pattern['value'], strlen($pattern['value'])) === 0) {
                        return true;
                    }
                    break;

                case 'suffix':
                    if (substr_compare($methodName, $pattern['value'], -strlen($pattern['value'])) === 0) {
                        return true;
                    }
                    break;

                case 'regex':
                    if (preg_match($pattern['value'], $methodName)) {
                        return true;
                    }
                    break;

                case 'annotation':
                    if ($this->hasAnnotation($method, $pattern['value'])) {
                        return true;
                    }
                    break;

                case 'attribute':
                    if ($this->hasAttribute($method, $pattern['value'])) {
                        return true;
                    }
                    break;
            }
        }

        return false;
    }

    private function analyzeMethod(ClassMethod $method, Class_ $class): array
    {
        $methodName = $method->name->toString();

        return [
            'method' => $method,
            'name' => $methodName,
            'extractable' => true,
            'dependencies' => $this->findMethodDependencies($method, $class),
            'group' => $this->determineMethodGroup($method),
            'complexity' => $this->calculateMethodComplexity($method),
            'annotations' => $this->extractMethodAnnotations($method),
            'attributes' => $this->extractMethodAttributes($method)
        ];
    }

    private function findMethodDependencies(ClassMethod $method, Class_ $class): array
    {
        $dependencies = [
            'properties' => [],
            'methods' => [],
            'constants' => []
        ];

        // Analyze method body for dependencies
        $this->traverseNodesOfType($method, function (Node $node) use (&$dependencies, $class): void {
            // Find property access
            if ($node instanceof PropertyFetch && $this->isName($node->var, 'this')) {
                $propertyName = $this->getName($node->name);
                if ($propertyName && $this->hasProperty($class, $propertyName)) {
                    $dependencies['properties'][] = $propertyName;
                }
            }

            // Find method calls
            if ($node instanceof MethodCall && $this->isName($node->var, 'this')) {
                $methodName = $this->getName($node->name);
                if ($methodName && $this->hasMethod($class, $methodName)) {
                    $dependencies['methods'][] = $methodName;
                }
            }

            // Find constant access
            if ($node instanceof ClassConstFetch && ($this->isName($node->class, 'self') || $this->isName($node->class, 'static'))) {
                $constantName = $this->getName($node->name);
                if ($constantName && $this->hasConstant($class, $constantName)) {
                    $dependencies['constants'][] = $constantName;
                }
            }
        });

        // Remove duplicates
        foreach ($dependencies as &$depList) {
            $depList = array_unique($depList);
        }

        return $dependencies;
    }

    private function determineMethodGroup(ClassMethod $method): string
    {
        $methodName = $method->name->toString();

        // NEW: Check direct mapping first
        if ($this->config['use_direct_mapping'] && isset($this->config['method_to_trait_map'][$methodName])) {
            return $this->config['method_to_trait_map'][$methodName];
        }

        switch ($this->config['group_by']) {
            case 'prefix':
                // Group by common prefixes (validate, format, calculate, etc.)
                $commonPrefixes = ['validate', 'format', 'calculate', 'convert', 'transform', 'parse', 'generate'];
                foreach ($commonPrefixes as $prefix) {
                    if (strncmp($methodName, $prefix, strlen($prefix)) === 0) {
                        return ucfirst($prefix);
                    }
                }
                break;

            case 'annotation':
                // Group by @group annotation
                $group = $this->getAnnotationValue($method, 'group');
                if ($group) {
                    return ucfirst($group);
                }
                break;

            case 'attribute':
                // Group by PHP 8 attributes
                $group = $this->getAttributeValue($method, 'Group');
                if ($group) {
                    return ucfirst($group);
                }
                break;

            case 'functionality':
            default:
                // Intelligent grouping based on method name patterns
                return $this->detectFunctionalityGroup($methodName);
        }

        return 'Utility';
    }

    private function detectFunctionalityGroup(string $methodName): string
    {
        $patterns = [
            'Validation' => ['validate', 'check', 'verify', 'ensure', 'assert'],
            'Formatting' => ['format', 'transform', 'convert', 'normalize'],
            'Calculation' => ['calculate', 'compute', 'sum', 'count', 'measure'],
            'Generation' => ['generate', 'create', 'build', 'make', 'produce'],
            'Parsing' => ['parse', 'extract', 'decode', 'analyze'],
            'Utility' => ['get', 'set', 'is', 'has', 'find', 'search']
        ];

        foreach ($patterns as $group => $keywords) {
            foreach ($keywords as $keyword) {
                if (strpos(strtolower($methodName), $keyword) !== false) {
                    return $group;
                }
            }
        }

        return 'Utility';
    }

    private function groupMethodsForExtraction(array $methods): array
    {
        $groups = [];

        foreach ($methods as $methodInfo) {
            $group = $methodInfo['group'];
            $groups[$group][] = $methodInfo;
        }

        // NEW: Skip minimum method filter for direct mapping mode
        if ($this->config['use_direct_mapping']) {
            return $groups;
        }

        // Filter groups that have minimum required methods
        return array_filter($groups, function ($group): bool {
            return count($group) >= $this->config['min_methods_per_trait'];
        });
    }

    private function extractMethodsToTraits(array $groupedMethods, Class_ $originalClass): array
    {
        $generatedTraits = [];

        foreach ($groupedMethods as $groupName => $methods) {
            $traitName = $groupName . 'Trait';
            $traitNode = $this->createTraitNode($traitName, $methods, $originalClass);

            if ($this->config['generate_files']) {
                $this->generateTraitFile($traitName, $traitNode);
            }

            $generatedTraits[] = [
                'name' => $traitName,
                'node' => $traitNode,
                'methods' => array_column($methods, 'name')
            ];
        }

        return $generatedTraits;
    }

    private function createTraitNode(string $traitName, array $methods, Class_ $originalClass): Trait_
    {
        $traitMethods = [];
        $requiredProperties = [];
        $requiredConstants = [];

        foreach ($methods as $methodInfo) {
            $method = clone $methodInfo['method'];

            // Collect dependencies
            foreach ($methodInfo['dependencies']['properties'] as $property) {
                $requiredProperties[] = $this->getProperty($originalClass, $property);
            }

            foreach ($methodInfo['dependencies']['constants'] as $constant) {
                $requiredConstants[] = $this->getConstant($originalClass, $constant);
            }

            $traitMethods[] = $method;
        }

        $traitStmts = array_merge(
            array_filter($requiredConstants),
            array_filter($requiredProperties),
            $traitMethods
        );

        return new Trait_(new Identifier($traitName), $traitStmts);
    }

    private function modifyOriginalClass(Class_ $class, array $extractedMethods, array $generatedTraits): Class_
    {
        $modifiedClass = clone $class;

        // Remove extracted methods
        $extractedMethodNames = array_column($extractedMethods, 'name');
        $modifiedClass->stmts = array_filter($modifiedClass->stmts, function ($stmt) use ($extractedMethodNames): bool {
            if ($stmt instanceof ClassMethod) {
                return !in_array($stmt->name->toString(), $extractedMethodNames, true);
            }
            return true;
        });

        // Add trait uses
        if ($this->config['add_trait_use']) {
            $traitUses = [];
            foreach ($generatedTraits as $trait) {
                $traitUses[] = new TraitUse([new Name($trait['name'])]);
            }

            // Insert trait uses at the beginning of class body
            $modifiedClass->stmts = array_merge($traitUses, $modifiedClass->stmts);
        }

        return $modifiedClass;
    }

    private function generateTraitFile(string $traitName, Trait_ $traitNode): void
    {
        if (!$this->config['generate_files']) {
            return;
        }

        $outputDir = $this->config['output_directory'];
        $namespace = $this->config['trait_namespace'];

        // Ensure output directory exists
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $filePath = $outputDir . '/' . $traitName . '.php';

        // Generate the file content
        $content = $this->generateTraitFileContent($traitNode, $namespace);

        file_put_contents($filePath, $content);
    }

    private function generateTraitFileContent(Trait_ $traitNode, string $namespace): string
    {
        $printer = new Standard();

        $namespaceNode = new Namespace_(
            new Name(explode('\\', $namespace)),
            [$traitNode]
        );

        $content = "<?php\n\ndeclare(strict_types=1);\n\n";

        return $content . $printer->prettyPrint([$namespaceNode]);
    }

    // Utility methods
    private function hasProperty(Class_ $class, string $propertyName): bool
    {
        foreach ($class->stmts as $stmt) {
            if ($stmt instanceof Property) {
                foreach ($stmt->props as $prop) {
                    if ($this->isName($prop->name, $propertyName)) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    private function hasMethod(Class_ $class, string $methodName): bool
    {
        foreach ($class->getMethods() as $method) {
            if ($this->isName($method->name, $methodName)) {
                return true;
            }
        }
        return false;
    }

    private function hasConstant(Class_ $class, string $constantName): bool
    {
        foreach ($class->stmts as $stmt) {
            if ($stmt instanceof ClassConst) {
                foreach ($stmt->consts as $const) {
                    if ($this->isName($const->name, $constantName)) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    private function getProperty(Class_ $class, string $propertyName): ?Property
    {
        foreach ($class->stmts as $stmt) {
            if ($stmt instanceof Property) {
                foreach ($stmt->props as $prop) {
                    if ($this->isName($prop->name, $propertyName)) {
                        return clone $stmt;
                    }
                }
            }
        }
        return null;
    }

    private function getConstant(Class_ $class, string $constantName): ?ClassConst
    {
        foreach ($class->stmts as $stmt) {
            if ($stmt instanceof ClassConst) {
                foreach ($stmt->consts as $const) {
                    if ($this->isName($const->name, $constantName)) {
                        return clone $stmt;
                    }
                }
            }
        }
        return null;
    }

    private function hasAnnotation(ClassMethod $method, string $annotation): bool
    {
        $docComment = $method->getDocComment();
        if (!$docComment) {
            return false;
        }

        return strpos($docComment->getText(), "@{$annotation}") !== false;
    }

    private function hasAttribute(ClassMethod $method, string $attributeName): bool
    {
        foreach ($method->attrGroups as $attrGroup) {
            foreach ($attrGroup->attrs as $attr) {
                if ($this->isName($attr->name, $attributeName)) {
                    return true;
                }
            }
        }
        return false;
    }

    private function getAnnotationValue(ClassMethod $method, string $annotation): ?string
    {
        $docComment = $method->getDocComment();
        if (!$docComment) {
            return null;
        }

        $pattern = "/@{$annotation}\s+([^\s\*]+)/";
        if (preg_match($pattern, $docComment->getText(), $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function getAttributeValue(ClassMethod $method, string $attributeName): ?string
    {
        foreach ($method->attrGroups as $attrGroup) {
            foreach ($attrGroup->attrs as $attr) {
                if ($this->isName($attr->name, $attributeName) && $attr->args !== []) {
                    $firstArg = $attr->args[0]->value;
                    if ($firstArg instanceof String_) {
                        return $firstArg->value;
                    }
                }
            }
        }
        return null;
    }

    private function calculateMethodComplexity(ClassMethod $method): int
    {
        $complexity = 1; // Base complexity

        $this->traverseNodesOfType($method, function (Node $node) use (&$complexity): void {
            // Increase complexity for control structures
            if ($node instanceof If_ ||
                $node instanceof ElseIf_ ||
                $node instanceof For_ ||
                $node instanceof Foreach_ ||
                $node instanceof While_ ||
                $node instanceof Do_ ||
                $node instanceof Switch_ ||
                $node instanceof Case_ ||
                $node instanceof Catch_ ||
                $node instanceof Ternary ||
                $node instanceof LogicalAnd ||
                $node instanceof LogicalOr) {
                $complexity++;
            }
        });

        return $complexity;
    }

    private function extractMethodAnnotations(ClassMethod $method): array
    {
        $annotations = [];
        $docComment = $method->getDocComment();

        if ($docComment) {
            $text = $docComment->getText();
            if (preg_match_all('/@(\w+)(?:\s+([^\n\*]+))?/', $text, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $annotations[$match[1]] = $match[2] ?? true;
                }
            }
        }

        return $annotations;
    }

    private function extractMethodAttributes(ClassMethod $method): array
    {
        $attributes = [];

        foreach ($method->attrGroups as $attrGroup) {
            foreach ($attrGroup->attrs as $attr) {
                $name = $this->getName($attr->name);
                $args = [];

                foreach ($attr->args as $arg) {
                    if ($arg->value instanceof String_) {
                        $args[] = $arg->value->value;
                    } elseif ($arg->value instanceof LNumber) {
                        $args[] = $arg->value->value;
                    }
                }

                $attributes[$name] = $args;
            }
        }

        return $attributes;
    }

    private function isInterface(): bool
    {
        // This is a simplification - in real implementation,
        // you'd check if the node is actually an Interface_ node
        return false;
    }

    private function isTrait(): bool
    {
        // This is a simplification - in real implementation,
        // you'd check if the node is actually a Trait_ node
        return false;
    }

    private function traverseNodesOfType(Node $node, callable $callback): void
    {
        $callback($node);

        foreach ($node->getSubNodeNames() as $name) {
            $subNode = $node->$name;

            if ($subNode instanceof Node) {
                $this->traverseNodesOfType($subNode, $callback);
            } elseif (is_array($subNode)) {
                foreach ($subNode as $subSubNode) {
                    if ($subSubNode instanceof Node) {
                        $this->traverseNodesOfType($subSubNode, $callback);
                    }
                }
            }
        }
    }
}