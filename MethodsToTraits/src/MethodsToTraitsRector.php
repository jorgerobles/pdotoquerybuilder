<?php

declare(strict_types=1);

namespace JDR\Rector\MethodsToTraits;

use InvalidArgumentException;
use Exception;
use PhpParser\Node;
use PhpParser\Node\Expr\BinaryOp\LogicalAnd;
use PhpParser\Node\Expr\BinaryOp\LogicalOr;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
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
        // Progress and dependency options
        'show_progress' => true,
        'auto_move_dependencies' => true,
        'move_private_methods' => true,
        'move_private_properties' => true,
        'move_method_scopes' => ['private'],
        'move_property_scopes' => ['private'],
    ];

    private array $extractedTraits = [];
    private array $methodAnalysis = [];
    private array $methodUsageMap = [];
    private array $propertyUsageMap = [];
    private array $explicitMethodGroups = []; // Store explicit method to group mappings

    public function configure(array $configuration): void
    {
        $this->config = array_merge($this->config, $configuration);
        $this->validateConfiguration();
        $this->buildExplicitMethodGroups();
    }

    /**
     * Validate the configuration and build explicit method group mappings
     */
    private function validateConfiguration(): void
    {
        foreach ($this->config['extract_patterns'] as $index => $pattern) {
            if ($pattern['type'] === 'methods') {
                if (!isset($pattern['methods']) || !is_array($pattern['methods']) || empty($pattern['methods'])) {
                    throw new InvalidArgumentException("Pattern at index {$index}: 'methods' type requires a non-empty 'methods' array");
                }

                // If multiple methods but no trait_name, throw error
                if (count($pattern['methods']) > 1 && !isset($pattern['trait_name'])) {
                    $methodList = implode(', ', $pattern['methods']);
                    throw new InvalidArgumentException("Pattern at index {$index}: Multiple methods [{$methodList}] require explicit 'trait_name'");
                }
            }
        }
    }

    /**
     * Build explicit method group mappings from patterns
     */
    private function buildExplicitMethodGroups(): void
    {
        $this->explicitMethodGroups = [];

        foreach ($this->config['extract_patterns'] as $pattern) {
            if ($pattern['type'] === 'methods') {
                $methods = $pattern['methods'];

                if (count($methods) === 1) {
                    // 1:1 mapping - auto-generate trait name
                    $methodName = $methods[0];
                    $traitName = isset($pattern['trait_name']) ? $pattern['trait_name'] : ucfirst($methodName) . 'Trait';
                    $this->explicitMethodGroups[$methodName] = $traitName;
                } else {
                    // N:1 mapping - use explicit trait name
                    $traitName = $pattern['trait_name'];
                    foreach ($methods as $methodName) {
                        $this->explicitMethodGroups[$methodName] = $traitName;
                    }
                }
            }
        }
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
        return $this->modifyOriginalClass($node, $traitsGenerated);
    }

    private function analyzeClassMethods(Class_ $class): array
    {
        $extractableMethods = [];
        $methods = $class->getMethods();
        $totalMethods = count($methods);

        if ($this->config['show_progress'] && $totalMethods > 0) {
            echo "\nAnalyzing {$totalMethods} methods: ";
        }

        // Build usage maps for smart dependency detection
        if ($this->config['auto_move_dependencies']) {
            $this->buildUsageMaps($class);
        }

        foreach ($methods as $index => $method) {
            if ($this->config['show_progress']) {
                echo '.';
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
                case 'methods':
                    // Check if method is in the explicit methods list
                    if (in_array($methodName, $pattern['methods'], true)) {
                        return true;
                    }
                    break;

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

        try {
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
        } catch (Exception $e) {
            // If dependency analysis fails, continue without dependencies
        }

        return $dependencies;
    }

    private function determineMethodGroup(ClassMethod $method): string
    {
        $methodName = $method->name->toString();

        // Check if method has explicit group mapping from 'methods' patterns
        if (isset($this->explicitMethodGroups[$methodName])) {
            $traitName = $this->explicitMethodGroups[$methodName];
            // Remove 'Trait' suffix if present to avoid 'TraitTrait'
            return substr_compare($traitName, 'Trait', -strlen('Trait')) === 0 ? substr($traitName, 0, -5) : $traitName;
        }

        switch ($this->config['group_by']) {
            case 'prefix':
                // Group by common prefixes
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

        // For explicit method groups, skip minimum method filter
        $explicitGroups = array_unique(array_values($this->explicitMethodGroups));
        $filteredGroups = [];

        foreach ($groups as $groupName => $groupMethods) {
            $traitName = $groupName . 'Trait';
            if (in_array($groupName, $explicitGroups, true) || in_array($traitName, $explicitGroups, true)) {
                // This is an explicit group, don't apply minimum filter
                $filteredGroups[$groupName] = $groupMethods;
            } elseif (count($groupMethods) >= $this->config['min_methods_per_trait']) {
                // Apply minimum filter for automatic groups
                $filteredGroups[$groupName] = $groupMethods;
            }
        }

        return $filteredGroups;
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
        $extractedMethodNames = array_column($methods, 'name');

        foreach ($methods as $methodInfo) {
            // Ensure we have a valid method
            if (!isset($methodInfo['method'])) {
                continue;
            }
            if (!($methodInfo['method'] instanceof ClassMethod)) {
                continue;
            }
            // Clone the method and add to trait methods
            $method = clone $methodInfo['method'];
            $traitMethods[] = $method;

            // Collect dependencies
            if (isset($methodInfo['dependencies']['properties']) && !empty($methodInfo['dependencies']['properties'])) {
                foreach ($methodInfo['dependencies']['properties'] as $propertyName) {
                    $property = $this->getProperty($originalClass, $propertyName);
                    if ($property && !$this->arrayContainsProperty($requiredProperties, $property) && $this->shouldMovePropertyToTrait($propertyName, $extractedMethodNames)) {
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

            // Check for internal method dependencies and move them if appropriate
            if (isset($methodInfo['dependencies']['methods']) && !empty($methodInfo['dependencies']['methods'])) {
                foreach ($methodInfo['dependencies']['methods'] as $dependentMethodName) {
                    if ($this->shouldMoveMethodToTrait($dependentMethodName, $extractedMethodNames)) {
                        $dependentMethod = $this->getMethod($originalClass, $dependentMethodName);
                        if ($dependentMethod && !$this->arrayContainsMethod($traitMethods, $dependentMethod)) {
                            $traitMethods[] = clone $dependentMethod;
                        }
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

        // Create the trait using direct assignment
        $trait = new Trait_(new Identifier($traitName));
        $trait->stmts = $traitStmts;

        return $trait;
    }

    // Helper methods to avoid duplicates
    private function arrayContainsProperty(array $properties, Property $property): bool
    {
        foreach ($properties as $existingProperty) {
            if ($existingProperty instanceof Property) {
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

    private function arrayContainsConstant(array $constants, ClassConst $constant): bool
    {
        foreach ($constants as $existingConstant) {
            if ($existingConstant instanceof ClassConst) {
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

    private function modifyOriginalClass(Class_ $class, array $generatedTraits): Class_
    {
        $modifiedClass = clone $class;
        $movedMethods = [];
        $movedProperties = [];

        // Collect all moved dependencies from traits
        foreach ($generatedTraits as $trait) {
            foreach ($trait['node']->stmts as $stmt) {
                if ($stmt instanceof ClassMethod) {
                    $movedMethods[] = $stmt->name->toString();
                } elseif ($stmt instanceof Property) {
                    foreach ($stmt->props as $prop) {
                        $movedProperties[] = $prop->name->toString();
                    }
                }
            }
        }

        // Remove extracted methods AND moved dependency methods
        $modifiedClass->stmts = array_filter($modifiedClass->stmts, function ($stmt) use ($movedMethods, $movedProperties): bool {
            // Remove methods that were moved to traits
            if ($stmt instanceof ClassMethod) {
                return !in_array($stmt->name->toString(), $movedMethods, true);
            }

            // Remove properties that were moved to traits
            if ($stmt instanceof Property) {
                $stmt->props = array_filter($stmt->props, function ($prop) use ($movedProperties): bool {
                    return !in_array($prop->name->toString(), $movedProperties, true);
                });
                // Remove the property statement if no properties left
                return $stmt->props !== [];
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
        if ($namespaceParts !== []) {
            $statements[] = new Namespace_(
                new Name($namespaceParts),
                [$traitNode]
            );
        } else {
            // No namespace, just add the trait directly
            $statements[] = $traitNode;
        }

        // Use the standard pretty printer with proper formatting
        $printer = new Standard([
            'shortArraySyntax' => true,
        ]);

        // Generate the complete file content
        $content = "<?php\n\ndeclare(strict_types=1);\n\n";

        try {
            $generatedCode = $printer->prettyPrint($statements);
            $content .= $generatedCode;
        } catch (Exception $e) {
            // Fallback: create a simple trait manually
            $content .= $this->createFallbackTraitContent($traitName, $traitNode, $namespace);
        }

        return $content;
    }

    private function createFallbackTraitContent(string $traitName, Trait_ $traitNode, string $namespace): string
    {
        $content = "";

        // Add namespace if provided
        if (!in_array(trim($namespace, '\\'), ['', '0'], true)) {
            $content .= "namespace " . trim($namespace, '\\') . ";\n\n";
        }

        // Start trait
        $content .= "trait $traitName\n{\n";

        // Add methods manually
        foreach ($traitNode->stmts as $stmt) {
            if ($stmt instanceof ClassMethod) {
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

    /**
     * Build comprehensive usage maps for smart dependency migration
     */
    private function buildUsageMaps(Class_ $class): void
    {
        $this->methodUsageMap = [];
        $this->propertyUsageMap = [];

        // Initialize maps for all methods and properties
        foreach ($class->getMethods() as $method) {
            $methodName = $method->name->toString();
            $this->methodUsageMap[$methodName] = [
                'usedBy' => [],
                'isPublic' => $method->isPublic(),
                'isPrivate' => $method->isPrivate(),
                'isProtected' => $method->isProtected(),
            ];
        }

        foreach ($class->stmts as $stmt) {
            if ($stmt instanceof Property) {
                foreach ($stmt->props as $prop) {
                    $propertyName = $prop->name->toString();
                    $this->propertyUsageMap[$propertyName] = [
                        'usedBy' => [],
                        'isPublic' => $stmt->isPublic(),
                        'isPrivate' => $stmt->isPrivate(),
                        'isProtected' => $stmt->isProtected(),
                    ];
                }
            }
        }

        // Analyze usage patterns across all methods
        foreach ($class->getMethods() as $method) {
            $methodName = $method->name->toString();
            $this->analyzeMethodUsage($method, $methodName);
        }
    }

    /**
     * Analyze what methods and properties a specific method uses
     */
    private function analyzeMethodUsage(ClassMethod $method, string $callerMethodName): void
    {
        try {
            $this->traverseNodesOfType($method, function (Node $node) use ($callerMethodName): void {
                // Track method calls
                if ($node instanceof MethodCall && $this->isName($node->var, 'this')) {
                    $calledMethodName = $this->getName($node->name);
                    if ($calledMethodName && isset($this->methodUsageMap[$calledMethodName])) {
                        $this->methodUsageMap[$calledMethodName]['usedBy'][] = $callerMethodName;
                    }
                }

                // Track property access
                if ($node instanceof PropertyFetch && $this->isName($node->var, 'this')) {
                    $propertyName = $this->getName($node->name);
                    if ($propertyName && isset($this->propertyUsageMap[$propertyName])) {
                        $this->propertyUsageMap[$propertyName]['usedBy'][] = $callerMethodName;
                    }
                }
            });
        } catch (Exception $e) {
            // Continue if analysis fails
        }
    }

    /**
     * Determine if a method should be moved to the trait
     */
    private function shouldMoveMethodToTrait(string $methodName, array $extractedMethodNames): bool
    {
        if (!$this->config['auto_move_dependencies']) {
            return false;
        }

        // Don't move if method doesn't exist in usage map
        if (!isset($this->methodUsageMap[$methodName])) {
            return false;
        }

        $usage = $this->methodUsageMap[$methodName];

        // Check if method visibility is in allowed scopes
        $allowedScopes = $this->config['move_method_scopes'] ?? ['private'];
        $methodScope = $usage['isPrivate'] ? 'private' : ($usage['isProtected'] ? 'protected' : 'public');

        if (!in_array($methodScope, $allowedScopes, true)) {
            return false;
        }

        // Don't move if it's in the excluded methods list
        if (in_array($methodName, $this->config['exclude_methods'], true)) {
            return false;
        }

        // Check if method is ONLY used by methods being extracted
        $usedBy = array_unique($usage['usedBy']);
        $usedByExtracted = array_intersect($usedBy, $extractedMethodNames);

        // Move if ALL usages are from extracted methods
        return $usedBy !== [] && count($usedBy) === count($usedByExtracted);
    }

    /**
     * Determine if a property should be moved to the trait
     */
    private function shouldMovePropertyToTrait(string $propertyName, array $extractedMethodNames): bool
    {
        if (!$this->config['auto_move_dependencies']) {
            return false;
        }

        // Don't move if property doesn't exist in usage map
        if (!isset($this->propertyUsageMap[$propertyName])) {
            return false;
        }

        $usage = $this->propertyUsageMap[$propertyName];

        // Check if property visibility is in allowed scopes
        $allowedScopes = $this->config['move_property_scopes'] ?? ['private'];
        $propertyScope = $usage['isPrivate'] ? 'private' : ($usage['isProtected'] ? 'protected' : 'public');

        if (!in_array($propertyScope, $allowedScopes, true)) {
            return false;
        }

        // Check if property is ONLY used by methods being extracted
        $usedBy = array_unique($usage['usedBy']);
        $usedByExtracted = array_intersect($usedBy, $extractedMethodNames);

        // Move if ALL usages are from extracted methods
        return $usedBy !== [] && count($usedBy) === count($usedByExtracted);
    }

    /**
     * Get a method by name from class
     */
    private function getMethod(Class_ $class, string $methodName): ?ClassMethod
    {
        foreach ($class->getMethods() as $method) {
            if ($this->isName($method->name, $methodName)) {
                return $method;
            }
        }
        return null;
    }

    /**
     * Check if method array already contains a specific method
     */
    private function arrayContainsMethod(array $methods, ClassMethod $targetMethod): bool
    {
        $targetName = $targetMethod->name->toString();
        foreach ($methods as $method) {
            if ($method instanceof ClassMethod &&
                $method->name->toString() === $targetName) {
                return true;
            }
        }
        return false;
    }
}