<?php

declare(strict_types=1);

namespace JDR\Rector\RouteAnnotator;

use PhpParser\Node;
use PhpParser\Node\Attribute;
use PhpParser\Node\AttributeGroup;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayItem;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use Rector\Contract\Rector\ConfigurableRectorInterface;
use Rector\Rector\AbstractRector;
use Rector\StaticTypeMapper\ValueObject\Type\FullyQualifiedObjectType;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * Rector rule to automatically add #[Route] attributes to public methods
 * in classes matching a specific pattern (e.g., Controller classes)
 * Detects method parameters as positional route parameters
 */
final class RouteRector extends AbstractRector implements ConfigurableRectorInterface
{
    const string ROUTE_IMPORT = 'Symfony\Component\Routing\Annotation\Route';
    private string $classPattern = 'Controller';
    private array $excludedMethods = ['__construct', '__destruct', '__clone'];
    private bool $addUseStatement = false;
    private mixed $pathTemplate = '/:controllerSlug/:methodSlug';
    private mixed $nameTemplate = ':controllerSlug_:methodSlug';
    private array $requirements = [];

    public function __construct(
        string $classPattern = 'Controller',
        array  $excludedMethods = [],
        bool   $addUseStatement = false,
    )
    {
        $this->classPattern = $classPattern;
        $this->excludedMethods = array_merge($this->excludedMethods, $excludedMethods);
        $this->addUseStatement = $addUseStatement;
    }

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Add #[Route] attribute to public methods in Controller classes with parameter detection',
            [
                new CodeSample(
                    <<<'CODE_SAMPLE'
                    class UserController
                    {
                        public function show(int $id)
                        {
                            return 'User: ' . $id;
                        }
                    }
                    CODE_SAMPLE
                    ,
                    <<<'CODE_SAMPLE'
                    class UserController
                    {
                        #[Route('/user/show/{id}', name: 'user_show', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
                        public function show(int $id)
                        {
                            return 'User: ' . $id;
                        }
                    }
                    CODE_SAMPLE
                ),
            ]
        );
    }

    /**
     * @return array<class-string<Node>>
     */
    public function getNodeTypes(): array
    {
        return [Class_::class];
    }

    /**
     * @param Class_ $node
     */
    public function refactor(Node $node): ?Node
    {
        // Check if class matches the pattern (e.g., ends with "Controller")
        if (!$this->matchesClassPattern($node)) {
            return null;
        }

        $hasChanges = false;
        $className = $this->getName($node);

        foreach ($node->getMethods() as $classMethod) {
            if ($this->shouldAddRouteAttribute($classMethod)) {
                $this->addRouteAttribute($classMethod, $className);
                $hasChanges = true;
            }
        }

        // Add use statement for Route attribute only if explicitly requested
        if ($hasChanges && $this->addUseStatement) {
            $this->addUseImport(self::ROUTE_IMPORT);
        }

        return $hasChanges ? $node : null;
    }

    private function matchesClassPattern(Class_ $class): bool
    {
        $className = $this->getName($class);

        if ($className === null) {
            return false;
        }

        return str_contains($className, $this->classPattern);
    }

    private function shouldAddRouteAttribute(ClassMethod $classMethod): bool
    {
        // Only process public methods
        if (!$classMethod->isPublic()) {
            return false;
        }

        // Skip excluded methods
        $methodName = $this->getName($classMethod);
        if (in_array($methodName, $this->excludedMethods, true)) {
            return false;
        }

        // Skip if Route attribute already exists
        if ($this->hasRouteAttribute($classMethod)) {
            return false;
        }

        return true;
    }

    private function hasRouteAttribute(ClassMethod $classMethod): bool
    {
        // Check for existing PHP 8 attribute-like expressions in method metadata/comments
        $docComment = $classMethod->getDocComment();
        if ($docComment !== null) {
            $docText = $docComment->getText();

            // Escape backslashes for regex pattern
            $escapedRouteImport = preg_quote(self::ROUTE_IMPORT, '/');
            $escapedRouteImport = str_replace('\\\\', '\\\\\\\\', $escapedRouteImport);

            // Look for PHP 8 attribute-like expressions: #[Route(...)] or #[Symfony\Component\Routing\Annotation\Route(...)]
            if (preg_match('/#\[Route\s*\(/i', $docText) === 1 ||
                preg_match('/#\[' . $escapedRouteImport . '\s*\(/i', $docText) === 1) {
                return true;
            }
        }

        // Also check actual PHP 8.0+ attributes in AST
        foreach ($classMethod->attrGroups as $attrGroup) {
            foreach ($attrGroup->attrs as $attribute) {
                $attributeName = $this->getName($attribute->name);
                if ($attributeName === 'Route' ||
                    $attributeName === self::ROUTE_IMPORT) {
                    return true;
                }
            }
        }

        return false;
    }

    private function addRouteAttribute(ClassMethod $classMethod, string $className): void
    {
        $methodName = $this->getName($classMethod);

        // Detect method parameters for route generation
        $methodParams = $this->extractMethodParameters($classMethod);

        // Generate route path based on class, method name, and parameters
        $routePath = $this->generateRoutePath($className, $methodName, $methodParams);
        $routeName = $this->generateRouteName($className, $methodName);
        $paramRequirements = $this->generateParameterRequirements($methodParams);

        // Create Route attribute arguments
        $routeArgs = [
            // Path argument
            new ArrayItem(new String_($routePath)),

            // Name argument
            new ArrayItem(new String_($routeName), new String_('name')),

            // Methods argument (default to GET and POST)
            new ArrayItem(
                new Array_([
                    new ArrayItem(new String_('GET')),
                    new ArrayItem(new String_('POST'))
                ]),
                new String_('methods')
            ),
        ];

        // Merge parameter requirements with configured requirements
        $allRequirements = array_merge($this->requirements, $paramRequirements);

        if (count($allRequirements) > 0) {
            $reqs = [];
            foreach ($allRequirements as $rk => $rv) {
                $reqs[] = new ArrayItem(new String_($rv), new String_($rk));
            }
            $routeArgs[] = new ArrayItem(
                new Array_($reqs),
                new String_('requirements')
            );
        }

        // Create the Route attribute
        $routeAttribute = new Attribute(
            new Name('Route'),
            $routeArgs
        );

        // Create attribute group and add to method
        $attributeGroup = new AttributeGroup([$routeAttribute]);
        array_unshift($classMethod->attrGroups, $attributeGroup);
    }

    /**
     * Extract method parameters with their types for route generation
     */
    private function extractMethodParameters(ClassMethod $classMethod): array
    {
        $parameters = [];

        foreach ($classMethod->params as $param) {
            $paramName = $this->getName($param->var);
            $paramType = null;

            // Get parameter type if specified - handle different AST type representations
            if ($param->type !== null) {
                if ($param->type instanceof \PhpParser\Node\Name) {
                    // Simple type like 'int', 'string', etc.
                    $paramType = $param->type->toString();
                } elseif ($param->type instanceof \PhpParser\Node\Identifier) {
                    // Built-in types
                    $paramType = $param->type->name;
                } elseif ($param->type instanceof \PhpParser\Node\NullableType) {
                    // Nullable types like ?int, ?string
                    $paramType = $this->getName($param->type->type);
                } elseif ($param->type instanceof \PhpParser\Node\UnionType) {
                    // Union types - take the first non-null type
                    foreach ($param->type->types as $unionType) {
                        if (!($unionType instanceof \PhpParser\Node\Name && $unionType->toString() === 'null')) {
                            $paramType = $this->getName($unionType);
                            break;
                        }
                    }
                } else {
                    // Fallback to getName
                    $paramType = $this->getName($param->type);
                }

                // Debug: Log the parameter type we found
                error_log("DEBUG: Parameter '{$paramName}' has type: " . ($paramType ?? 'null') . " (AST node: " . get_class($param->type) . ")");
            }

            $parameters[] = [
                'name' => $paramName,
                'type' => $paramType,
                'hasDefault' => $param->default !== null,
                'isOptional' => $param->default !== null
            ];
        }

        return $parameters;
    }

    /**
     * Generate route requirements based on method parameters
     */
    private function generateParameterRequirements(array $methodParams): array
    {
        $requirements = [];

        foreach ($methodParams as $param) {
            $paramName = $param['name'];
            $paramType = $param['type'];

            // Debug: Log what we're processing
            error_log("DEBUG: Processing param '{$paramName}' with type '" . ($paramType ?? 'null') . "'");

            // Skip parameters without names
            if (empty($paramName)) {
                continue;
            }

            // Generate requirements based on parameter type
            switch (strtolower($paramType ?? '')) {
                case 'int':
                case 'integer':
                    $requirements[$paramName] = '\d+';
                    error_log("DEBUG: Added int requirement for {$paramName}");
                    break;
                case 'string':
                    $requirements[$paramName] = '[^/]+';
                    error_log("DEBUG: Added string requirement for {$paramName}");
                    break;
                case 'float':
                case 'double':
                    $requirements[$paramName] = '\d+(\.\d+)?';
                    error_log("DEBUG: Added float requirement for {$paramName}");
                    break;
                case 'bool':
                case 'boolean':
                    $requirements[$paramName] = '0|1|true|false';
                    error_log("DEBUG: Added bool requirement for {$paramName}");
                    break;
                default:
                    // For custom types, no type hint, or unknown types, use a general pattern
                    if (!empty($paramType)) {
                        $requirements[$paramName] = '[^/]+';
                        error_log("DEBUG: Added default requirement for {$paramName} (type: {$paramType})");
                    } else {
                        error_log("DEBUG: No requirement added for {$paramName} (no type)");
                    }
                    break;
            }
        }

        error_log("DEBUG: Final requirements: " . json_encode($requirements));
        return $requirements;
    }

    private function generateRoutePath(string $className, string $methodName, array $methodParams = []): string
    {
        // Convert class name to route prefix
        // e.g., "UserController" -> "user"
        $controllerName = str_replace($this->classPattern, '', basename(str_replace('\\', '/', $className)));
        $controllerSlug = $this->camelCaseToKebabCase($controllerName);

        // Convert method name to route suffix
        $methodSlug = $this->camelCaseToKebabCase($methodName);

        $variables = [
            'controllerSlug' => $controllerSlug,
            'methodSlug' => $methodSlug,
            'filePath' => $this->file->getFilePath()
        ];

        if (is_callable($this->pathTemplate)) {
            $basePath = call_user_func($this->pathTemplate, $variables);
        } else {
            $basePath = $this->template($this->pathTemplate, $variables);
        }

        // Add parameters as positional route parameters
        if (!empty($methodParams)) {
            foreach ($methodParams as $param) {
                $basePath .= '/{' . $param['name'] . '}';
            }
        }

        return $basePath;
    }

    private function generateRouteName(string $className, string $methodName): string
    {
        // Convert to snake_case for route name
        // e.g., "UserController::showProfile" -> "user_show_profile"
        $controllerName = str_replace($this->classPattern, '', basename(str_replace('\\', '/', $className)));
        $controllerSlug = $this->camelCaseToSnakeCase($controllerName);
        $methodSlug = $this->camelCaseToSnakeCase($methodName);

        $variables = [
            'controllerSlug' => $controllerSlug,
            'methodSlug' => $methodSlug,
            'filePath' => $this->file->getFilePath()
        ];

        if (is_callable($this->nameTemplate)) {
            return call_user_func($this->nameTemplate, $variables);
        }

        return $this->template($this->nameTemplate, $variables);
    }

    private function camelCaseToKebabCase(string $input): string
    {
        return strtolower(preg_replace('/([a-z])([A-Z])/', '$1-$2', $input));
    }

    private function camelCaseToSnakeCase(string $input): string
    {
        return strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $input));
    }

    private function addUseImport(string $fullyQualifiedName): void
    {
        $this->useNodesToAddCollector->addUseImport(new FullyQualifiedObjectType($fullyQualifiedName));
    }

    public function configure(array $configuration): void
    {
        $this->classPattern = $configuration['classPattern'] ?? $this->classPattern;
        $this->excludedMethods = $configuration['excludedMethods'] ?? $this->excludedMethods;
        $this->addUseStatement = $configuration['addUseStatement'] ?? $this->addUseStatement;
        $this->pathTemplate = $configuration['pathTemplate'] ?? $this->pathTemplate;
        $this->nameTemplate = $configuration['nameTemplate'] ?? $this->nameTemplate;
        $this->requirements = $configuration['requirements'] ?? $this->requirements;
    }

    /**
     * @param string $template
     * @param array<string,mixed> $variables
     * @param string|null $start
     * @param string|null $end
     * @return string
     */
    public static function template(string $template, array $variables = [], ?string $start = ':', ?string $end = null): string
    {
        $params = array_reduce(array_keys($variables), function ($stack, $key) use ($variables, $start, $end) {
            $stack[($start ?? '') . $key . ($end ?? '')] = $variables[$key];
            return $stack;
        }, []);

        return strtr($template, $params);
    }
}