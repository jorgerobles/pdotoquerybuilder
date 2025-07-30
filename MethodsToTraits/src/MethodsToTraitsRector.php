<?php

declare(strict_types=1);

namespace JDR\Rector\MethodsToTraits;

use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\Node\Name;
use PhpParser\Node\Identifier;
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
        // NEW: Progress indicator
        'show_progress' => true, // Show progress dots
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
        if ($node->isAbstract() || $this->isInterface($node) || $this->isTrait($node)) {
            return null;
        }

        // Analyze methods in the class
        $methodsToExtract = $this->analyzeClassMethods($node);

        if (empty($methodsToExtract)) {
            return null;
        }

        // Group methods by extraction strategy
        $groupedMethods = $this->groupMethodsForExtraction($methodsToExtract);

        if (empty($groupedMethods)) {
            return null;
        }

        // Extract methods to traits
        $traitsGenerated = $this->extractMethodsToTraits($groupedMethods, $node);

        if (empty($traitsGenerated)) {
            return null;
        }

        // Modify the original class
        return $this->modifyOriginalClass($node, $methodsToExtract, $traitsGenerated);
    }

    private function analyzeClassMethods(Class_ $class): array
    {
        $extractableMethods = [];
        $methods = $class->getMethods();
        $totalMethods = count($methods);

        if ($this->config['show_progress'] && $totalMethods > 0) {
            echo "\nAnalyzing {$totalMethods} methods: ";
        }

        foreach ($methods as $index => $method) {
            if ($this->config['show_progress']) {
                echo '.';
                // Add newline every 50 dots for readability
                if (($index + 1) % 50 === 0) {
                    echo " (" . ($index + 1) . "/{$totalMethods})\n                           ";
                }
            }

            if (!$this->shouldExtractMethod($method)) {
                continue;
            }

            $methodInfo = $this->analyzeMethod($method, $class);
            if ($methodInfo['extractable']) {
                $extractableMethods[] = $methodInfo;
            }
        }

        if ($this->config['show_progress'] && $totalMethods > 0) {
            echo " (" . count($extractableMethods) . " extractable)\n";
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
                    if (str_starts_with($methodName, $pattern['value'])) {
                        return true;
                    }
                    break;

                case 'suffix':
                    if (str_ends_with($methodName, $pattern['value'])) {
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

        $methodInfo = [
            'method' => $method,
            'name' => $methodName,
            'extractable' => true,
            'dependencies' => $this->findMethodDependencies($method, $class),
            'group' => $this->determineMethodGroup($method),
            'complexity' => $this->calculateMethodComplexity($method),
            'annotations' => $this->extractMethodAnnotations($method),
            'attributes' => $this->extractMethodAttributes($method)
        ];

        return $methodInfo;
    }

    private function findMethodDependencies(ClassMethod $method, Class_ $class): array
    {
        $dependencies = [
            'properties' => [],
            'methods' => [],
            'constants' => []
        ];

        try {
            // Analyze method body for dependencies
            $this->traverseNodesOfType($method, function (Node $node) use (&$dependencies, $class) {
                // Find property access
                if ($node instanceof \PhpParser\Node\Expr\PropertyFetch) {
                    if ($this->isName($node->var, 'this')) {
                        $propertyName = $this->getName($node->name);
                        if ($propertyName && $this->hasProperty($class, $propertyName)) {
                            $dependencies['properties'][] = $propertyName;
                        }
                    }
                }

                // Find method calls
                if ($node instanceof \PhpParser\Node\Expr\MethodCall) {
                    if ($this->isName($node->var, 'this')) {
                        $methodName = $this->getName($node->name);
                        if ($methodName && $this->hasMethod($class, $methodName)) {
                            $dependencies['methods'][] = $methodName;
                        }
                    }
                }

                // Find constant access
                if ($node instanceof \PhpParser\Node\Expr\ClassConstFetch) {
                    if ($this->isName($node->class, 'self') || $this->isName($node->class, 'static')) {
                        $constantName = $this->getName($node->name);
                        if ($constantName && $this->hasConstant($class, $constantName)) {
                            $dependencies['constants'][] = $constantName;
                        }
                    }
                }
            });

            // Remove duplicates
            foreach ($dependencies as &$depList) {
                $depList = array_unique($depList);
            }
        } catch (\Exception $e) {
            // If dependency analysis fails, continue without dependencies
        }

        return $dependencies;
    }

    private function determineMethodGroup(ClassMethod $method): string
    {
        $methodName = $method->name->toString();

        // NEW: Check direct mapping first
        if ($this->config['use_direct_mapping'] && isset($this->config['method_to_trait_map'][$methodName])) {
            $traitName = $this->config['method_to_trait_map'][$methodName];
            // Remove 'Trait' suffix if present to avoid 'TraitTrait'
            return str_ends_with($traitName, 'Trait') ? substr($traitName, 0, -5) : $traitName;
        }

        switch ($this->config['group_by']) {
            case 'prefix':
                // Group by common prefixes (validate, format, calculate, etc.)
                $commonPrefixes = ['validate', 'format', 'calculate', 'convert', 'transform', 'parse', 'generate'];
                foreach ($commonPrefixes as $prefix) {
                    if (str_starts_with($methodName, $prefix)) {
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
                if (str_contains(strtolower($methodName), $keyword)) {
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
        return array_filter($groups, function ($group) {
            return count($group) >= $this->config['min_methods_per_trait'];
        });
    }

    private function extractMethodsToTraits(array $groupedMethods, Class_ $originalClass): array
    {
        $generatedTraits = [];
        $totalGroups = count($groupedMethods);

        if ($this->config['show_progress'] && $totalGroups > 0) {
            echo "Generating {$totalGroups} traits: ";
        }

        foreach ($groupedMethods as $groupName => $methods) {
            if ($this->config['show_progress']) {
                echo '.';
            }

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

        if ($this->config['show_progress'] && $totalGroups > 0) {
            echo " (done)\n";
        }

        return $generatedTraits;
    }

    private function createTraitNode(string $traitName, array $methods, Class_ $originalClass): Trait_
    {
        $traitMethods = [];
        $requiredProperties = [];
        $requiredConstants = [];

        foreach ($methods as $methodInfo) {
            // Ensure we have a valid method
            if (!isset($methodInfo['method']) || !($methodInfo['method'] instanceof \PhpParser\Node\Stmt\ClassMethod)) {
                continue;
            }

            // Clone the method and add to trait methods
            $method = clone $methodInfo['method'];
            $traitMethods[] = $method;

            // Collect dependencies only if they exist
            if (isset($methodInfo['dependencies']['properties']) && !empty($methodInfo['dependencies']['properties'])) {
                foreach ($methodInfo['dependencies']['properties'] as $propertyName) {
                    $property = $this->getProperty($originalClass, $propertyName);
                    if ($property && !$this->arrayContainsProperty($requiredProperties, $property)) {
                        $requiredProperties[] = clone $property;
                    }
                }
            }

            if (isset($methodInfo['dependencies']['constants']) && !empty($methodInfo['dependencies']['constants'])) {
                foreach ($methodInfo['dependencies']['constants'] as $constantName) {
                    $constant = $this->getConstant($originalClass, $constantName);
                    if ($constant && !$this->arrayContainsConstant($requiredConstants, $constant)) {
                        $requiredConstants[] = clone $constant;
                    }
                }
            }
        }

        // Build trait statements: constants first, then properties, then methods
        $traitStmts = array_merge(
            $requiredConstants,
            $requiredProperties,
            $traitMethods
        );

        // Create the trait using direct assignment (fixes PhpParser constructor issue)
        $trait = new Trait_(new Identifier($traitName));
        $trait->stmts = $traitStmts;

        return $trait;
    }

    // Helper methods to avoid duplicates
    private function arrayContainsProperty(array $properties, \PhpParser\Node\Stmt\Property $property): bool
    {
        foreach ($properties as $existingProperty) {
            if ($existingProperty instanceof \PhpParser\Node\Stmt\Property) {
                foreach ($existingProperty->props as $prop) {
                    foreach ($property->props as $newProp) {
                        if ($prop->name->toString() === $newProp->name->toString()) {
                            return true;
                        }
                    }
                }
            }
        }
        return false;
    }

    private function arrayContainsConstant(array $constants, \PhpParser\Node\Stmt\ClassConst $constant): bool
    {
        foreach ($constants as $existingConstant) {
            if ($existingConstant instanceof \PhpParser\Node\Stmt\ClassConst) {
                foreach ($existingConstant->consts as $const) {
                    foreach ($constant->consts as $newConst) {
                        if ($const->name->toString() === $newConst->name->toString()) {
                            return true;
                        }
                    }
                }
            }
        }
        return false;
    }

    private function modifyOriginalClass(Class_ $class, array $extractedMethods, array $generatedTraits): Class_
    {
        $modifiedClass = clone $class;

        // Remove extracted methods
        $extractedMethodNames = array_column($extractedMethods, 'name');
        $modifiedClass->stmts = array_filter($modifiedClass->stmts, function ($stmt) use ($extractedMethodNames) {
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
        $content = $this->generateTraitFileContent($traitName, $traitNode, $namespace);

        file_put_contents($filePath, $content);
    }

    private function generateTraitFileContent(string $traitName, Trait_ $traitNode, string $namespace): string
    {
        // Create namespace parts properly
        $namespaceParts = array_filter(explode('\\', trim($namespace, '\\')));

        // Create the complete file structure
        $statements = [];

        // Add namespace if provided
        if (!empty($namespaceParts)) {
            $statements[] = new \PhpParser\Node\Stmt\Namespace_(
                new Name($namespaceParts),
                [$traitNode]
            );
        } else {
            // No namespace, just add the trait directly
            $statements[] = $traitNode;
        }

        // Use the standard pretty printer with proper formatting
        $printer = new \PhpParser\PrettyPrinter\Standard([
            'shortArraySyntax' => true,
        ]);

        // Generate the complete file content
        $content = "<?php\n\ndeclare(strict_types=1);\n\n";

        try {
            $generatedCode = $printer->prettyPrint($statements);
            $content .= $generatedCode;
        } catch (\Exception $e) {
            // Fallback: create a simple trait manually
            $content .= $this->createFallbackTraitContent($traitName, $traitNode, $namespace);
        }

        return $content;
    }

    private function createFallbackTraitContent(string $traitName, Trait_ $traitNode, string $namespace): string
    {
        $content = "";

        // Add namespace if provided
        if (!empty(trim($namespace, '\\'))) {
            $content .= "namespace " . trim($namespace, '\\') . ";\n\n";
        }

        // Start trait
        $content .= "trait $traitName\n{\n";

        // Add methods manually
        foreach ($traitNode->stmts as $stmt) {
            if ($stmt instanceof \PhpParser\Node\Stmt\ClassMethod) {
                $methodName = $stmt->name->toString();
                $content .= "    public function $methodName()\n    {\n";
                $content .= "        // TODO: Implement method body\n";
                $content .= "        // Original method: $methodName\n";
                $content .= "    }\n\n";
            }
        }

        $content .= "}\n";

        if ($this->config['show_progress']) {
            echo "[FALLBACK-GENERATED]";
        }

        return $content;
    }

    // Utility methods
    private function hasProperty(Class_ $class, string $propertyName): bool
    {
        foreach ($class->stmts as $stmt) {
            if ($stmt instanceof \PhpParser\Node\Stmt\Property) {
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
            if ($stmt instanceof \PhpParser\Node\Stmt\ClassConst) {
                foreach ($stmt->consts as $const) {
                    if ($this->isName($const->name, $constantName)) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    private function getProperty(Class_ $class, string $propertyName): ?\PhpParser\Node\Stmt\Property
    {
        foreach ($class->stmts as $stmt) {
            if ($stmt instanceof \PhpParser\Node\Stmt\Property) {
                foreach ($stmt->props as $prop) {
                    if ($this->isName($prop->name, $propertyName)) {
                        return clone $stmt;
                    }
                }
            }
        }
        return null;
    }

    private function getConstant(Class_ $class, string $constantName): ?\PhpParser\Node\Stmt\ClassConst
    {
        foreach ($class->stmts as $stmt) {
            if ($stmt instanceof \PhpParser\Node\Stmt\ClassConst) {
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

        return str_contains($docComment->getText(), "@{$annotation}");
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
                if ($this->isName($attr->name, $attributeName) && !empty($attr->args)) {
                    $firstArg = $attr->args[0]->value;
                    if ($firstArg instanceof \PhpParser\Node\Scalar\String_) {
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

        $this->traverseNodesOfType($method, function (Node $node) use (&$complexity) {
            // Increase complexity for control structures
            if ($node instanceof \PhpParser\Node\Stmt\If_ ||
                $node instanceof \PhpParser\Node\Stmt\ElseIf_ ||
                $node instanceof \PhpParser\Node\Stmt\For_ ||
                $node instanceof \PhpParser\Node\Stmt\Foreach_ ||
                $node instanceof \PhpParser\Node\Stmt\While_ ||
                $node instanceof \PhpParser\Node\Stmt\Do_ ||
                $node instanceof \PhpParser\Node\Stmt\Switch_ ||
                $node instanceof \PhpParser\Node\Stmt\Case_ ||
                $node instanceof \PhpParser\Node\Stmt\Catch_ ||
                $node instanceof \PhpParser\Node\Expr\Ternary ||
                $node instanceof \PhpParser\Node\Expr\BinaryOp\LogicalAnd ||
                $node instanceof \PhpParser\Node\Expr\BinaryOp\LogicalOr) {
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
                    if ($arg->value instanceof \PhpParser\Node\Scalar\String_) {
                        $args[] = $arg->value->value;
                    } elseif ($arg->value instanceof \PhpParser\Node\Scalar\LNumber) {
                        $args[] = $arg->value->value;
                    }
                }

                $attributes[$name] = $args;
            }
        }

        return $attributes;
    }

    private function isInterface(Class_ $class): bool
    {
        // This is a simplification - in real implementation,
        // you'd check if the node is actually an Interface_ node
        return false;
    }

    private function isTrait(Class_ $class): bool
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