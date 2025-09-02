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
use PhpParser\Node\Arg;
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

        // Generate single route with all parameters (including optional ones)
        $routePath = $this->generateRoutePath($className, $methodName, $methodParams);
        $routeName = $this->generateRouteName($className, $methodName);
        $paramRequirements = $this->generateParameterRequirements($methodParams);
        $paramDefaults = $this->generateParameterDefaults($methodParams);

        // Create Route attribute arguments using named parameters syntax
        $routeArgs = [
            // Path argument (positional)
            new Arg(new String_($routePath))
        ];

        // Add named arguments
        $routeArgs[] = new Arg(
            new String_($routeName),
            false, false, [],
            new \PhpParser\Node\Identifier('name')
        );

        $routeArgs[] = new Arg(
            new Array_([
                new ArrayItem(new String_('GET')),
                new ArrayItem(new String_('POST'))
            ]),
            false, false, [],
            new \PhpParser\Node\Identifier('methods')
        );

        // Merge parameter requirements with configured requirements
        $allRequirements = array_merge($this->requirements, $paramRequirements);

        if (count($allRequirements) > 0) {
            $reqs = [];
            foreach ($allRequirements as $rk => $rv) {
                $reqs[] = new ArrayItem(new String_($rv), new String_($rk));
            }

            $routeArgs[] = new Arg(
                new Array_($reqs),
                false, false, [],
                new \PhpParser\Node\Identifier('requirements')
            );
        }

        // Add defaults if any parameters have default values
        if (count($paramDefaults) > 0) {
            $defaults = [];
            foreach ($paramDefaults as $dk => $dv) {
                $defaultValue = $this->createDefaultValueNode($dv);
                $defaults[] = new ArrayItem($defaultValue, new String_($dk));
            }

            $routeArgs[] = new Arg(
                new Array_($defaults),
                false, false, [],
                new \PhpParser\Node\Identifier('defaults')
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
     * Extract method parameters with their types and default values
     */
    private function extractMethodParameters(ClassMethod $classMethod): array
    {
        $parameters = [];

        foreach ($classMethod->params as $param) {
            $paramName = $this->getName($param->var);
            $paramType = null;
            $defaultValue = null;

            // Get parameter type if specified - handle different AST type representations
            if ($param->type !== null) {
                if ($param->type instanceof \PhpParser\Node\Name) {
                    $paramType = $param->type->toString();
                } elseif ($param->type instanceof \PhpParser\Node\Identifier) {
                    $paramType = $param->type->name;
                } elseif ($param->type instanceof \PhpParser\Node\NullableType) {
                    $paramType = $this->getName($param->type->type);
                } elseif ($param->type instanceof \PhpParser\Node\UnionType) {
                    foreach ($param->type->types as $unionType) {
                        if (!($unionType instanceof \PhpParser\Node\Name && $unionType->toString() === 'null')) {
                            $paramType = $this->getName($unionType);
                            break;
                        }
                    }
                } else {
                    $paramType = $this->getName($param->type);
                }
            }

            // Extract default value if present
            if ($param->default !== null) {
                $defaultValue = $this->extractDefaultValue($param->default);
            }

            $parameters[] = [
                'name' => $paramName,
                'type' => $paramType,
                'hasDefault' => $param->default !== null,
                'defaultValue' => $defaultValue,
                'isOptional' => $param->default !== null
            ];
        }

        return $parameters;
    }

    /**
     * Extract default value from AST node
     */
    private function extractDefaultValue(\PhpParser\Node\Expr $defaultExpr): mixed
    {
        if ($defaultExpr instanceof \PhpParser\Node\Scalar\String_) {
            return $defaultExpr->value;
        } elseif ($defaultExpr instanceof \PhpParser\Node\Scalar\LNumber) {
            return $defaultExpr->value; // This is already an int
        } elseif ($defaultExpr instanceof \PhpParser\Node\Scalar\DNumber) {
            return $defaultExpr->value; // This is already a float
        } elseif ($defaultExpr instanceof \PhpParser\Node\Expr\ConstFetch) {
            $constName = $defaultExpr->name->toString();
            switch (strtolower($constName)) {
                case 'true':
                    return true;
                case 'false':
                    return false;
                case 'null':
                    return null;
                default:
                    return $constName; // Return as string for other constants
            }
        } elseif ($defaultExpr instanceof \PhpParser\Node\Expr\Array_) {
            // Handle arrays
            if (empty($defaultExpr->items)) {
                return []; // Empty array
            } else {
                $result = [];
                foreach ($defaultExpr->items as $item) {
                    if ($item === null) continue; // Skip null items

                    $key = null;
                    if ($item->key !== null) {
                        $key = $this->extractDefaultValue($item->key);
                    }

                    $value = $this->extractDefaultValue($item->value);

                    if ($key !== null) {
                        $result[$key] = $value;
                    } else {
                        $result[] = $value;
                    }
                }
                return $result;
            }
        } elseif ($defaultExpr instanceof \PhpParser\Node\Expr\UnaryMinus) {
            // Handle negative numbers
            $operand = $this->extractDefaultValue($defaultExpr->expr);
            if (is_numeric($operand)) {
                return -$operand;
            }
            return "-$operand";
        } elseif ($defaultExpr instanceof \PhpParser\Node\Expr\UnaryPlus) {
            // Handle positive numbers (rarely used)
            return $this->extractDefaultValue($defaultExpr->expr);
        }

        // For complex expressions, return a string representation
        // This is a fallback and might not always be perfect
        try {
            return $this->printPhpParserNode($defaultExpr);
        } catch (\Exception $e) {
            // If we can't print the node, return a generic string
            return 'default_value';
        }
    }

    /**
     * Generate defaults for parameters that have default values
     */
    private function generateParameterDefaults(array $methodParams): array
    {
        $defaults = [];

        foreach ($methodParams as $param) {
            if ($param['hasDefault'] && isset($param['defaultValue'])) {
                $defaults[$param['name']] = $param['defaultValue'];
            }
        }

        return $defaults;
    }

    /**
     * Create the appropriate AST node for a default value
     */
    private function createDefaultValueNode($value): \PhpParser\Node\Expr
    {
        // Handle null values
        if ($value === null) {
            return new \PhpParser\Node\Expr\ConstFetch(new \PhpParser\Node\Name('null'));
        }

        // Handle boolean values
        if (is_bool($value)) {
            return new \PhpParser\Node\Expr\ConstFetch(new \PhpParser\Node\Name($value ? 'true' : 'false'));
        }

        // Handle numeric values - ensure proper type conversion
        if (is_numeric($value)) {
            if (is_int($value)) {
                return new \PhpParser\Node\Scalar\LNumber((int)$value);
            } elseif (is_float($value)) {
                return new \PhpParser\Node\Scalar\DNumber((float)$value);
            } else {
                // If it's a numeric string, try to convert it properly
                if (strpos((string)$value, '.') !== false) {
                    return new \PhpParser\Node\Scalar\DNumber((float)$value);
                } else {
                    return new \PhpParser\Node\Scalar\LNumber((int)$value);
                }
            }
        }

        // Handle string values (including non-numeric strings)
        if (is_string($value)) {
            return new String_($value);
        }

        // Handle arrays (empty arrays are common defaults)
        if (is_array($value)) {
            if (empty($value)) {
                return new Array_([]);
            } else {
                // For non-empty arrays, convert each element
                $items = [];
                foreach ($value as $k => $v) {
                    $items[] = new ArrayItem(
                        $this->createDefaultValueNode($v),
                        is_string($k) ? new String_($k) : null
                    );
                }
                return new Array_($items);
            }
        }

        // Fallback: convert to string
        return new String_((string)$value);
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

            // Skip parameters without names
            if (empty($paramName)) {
                continue;
            }

            // Generate requirements based on parameter type
            switch (strtolower($paramType ?? '')) {
                case 'int':
                case 'integer':
                    $requirements[$paramName] = '\d+';
                    break;
                case 'string':
                    $requirements[$paramName] = '[^/]+';
                    break;
                case 'float':
                case 'double':
                    $requirements[$paramName] = '\d+(\.\d+)?';
                    break;
                case 'bool':
                case 'boolean':
                    $requirements[$paramName] = '0|1|true|false';
                    break;
                default:
                    // For custom types, no type hint, or unknown types, use a general pattern
                    if (!empty($paramType)) {
                        $requirements[$paramName] = '[^/]+';
                    }
                    break;
            }
        }

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