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
 * Rector rule to automatically add @Route annotations to public methods
 * in classes matching a specific pattern (e.g., Controller classes)
 * Compatible with PHP 7.4 (uses docblock annotations instead of attributes)
 */
final class RouteRector extends AbstractRector implements ConfigurableRectorInterface
{
    const string ROUTE_IMPORT = 'Symfony\Component\Routing\Annotation\Route';
    private string $classPattern = 'Controller';
    private array $excludedMethods = ['__construct', '__destruct', '__clone'];
    private bool $addUseStatement = false;
    private mixed $pathTemplate = '/:controllerSlug/:methodSlug';
    private array $requirements =[];

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
            'Add #[Route] attribute to public methods in Controller classes',
            [
                new CodeSample(
                    <<<'CODE_SAMPLE'
                    class UserController
                    {
                        public function index()
                        {
                            return 'Hello World';
                        }
                    }
                    CODE_SAMPLE
                    ,
                    <<<'CODE_SAMPLE'
                    class UserController
                    {
                        #[Route('/user/index', name: 'user_index', methods: ['GET'])]
                        public function index()
                        {
                            return 'Hello World';
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

        // Generate route path based on class and method name
        $routePath = $this->generateRoutePath($className, $methodName);
        $routeName = $this->generateRouteName($className, $methodName);

        // Create Route attribute arguments
        $routeArgs = [
            // Path argument
            new ArrayItem(new String_($routePath)),

            // Name argument
            new ArrayItem(new String_($routeName), new String_('name')),

            // Methods argument (default to GET)
            new ArrayItem(
                new Array_([
                    new ArrayItem(new String_('GET')),
                    new ArrayItem(new String_('POST'))
                ]),
                new String_('methods')
            ),

        ];

        if (count($this->requirements)>0){
            $reqs=[];
            foreach($this->requirements as $rk=>$rv){
                $reqs[]=new ArrayItem(new String_($rv),new String_($rk));
            }
            $routeArgs[]=new ArrayItem(
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

    private function generateRoutePath(string $className, string $methodName): string
    {
        error_log(dirname(dirname($this->file->getFilePath())));

        // Convert class name to route prefix
        // e.g., "UserController" -> "user"
        $controllerName = str_replace($this->classPattern, '', $className);
        $controllerSlug = $this->camelCaseToKebabCase($controllerName);

        // Convert method name to route suffix
        $methodSlug = $this->camelCaseToKebabCase($methodName);

        if (is_callable($this->pathTemplate)) {
            return call_user_func($this->pathTemplate, ['controllerSlug' => $controllerSlug, 'methodSlug' => $methodSlug, 'filePath' => $this->file->getFilePath()]);
        }

        return $this->template($this->pathTemplate, ['controllerSlug' => $controllerSlug, 'methodSlug' => $methodSlug, 'filePath' => $this->file->getFilePath()]);
    }

    private function generateRouteName(string $className, string $methodName): string
    {
        // Convert to snake_case for route name
        // e.g., "UserController::showProfile" -> "user_show_profile"
        $controllerName = str_replace($this->classPattern, '', $className);
        $controllerSlug = $this->camelCaseToSnakeCase($controllerName);
        $methodSlug = $this->camelCaseToSnakeCase($methodName);

        return $controllerSlug . '_' . $methodSlug;
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
        $this->requirements = $configuration['requirements'] ?? $this->requirements;
    }

    /**
     * @param string $template
     * @param array<string,mixed> $variables
     * @return string
     */
    public static function template(string $template, array $variables = [], ?string $start = '{', ?string $end = '}'): string
    {
        $params = array_reduce(array_keys($variables), function ($stack, $key) use ($variables, $start, $end) {
            $stack[($start ?? '') . $key . ($end ?? '')] = $variables[$key];
            return $stack;
        }, []);


        return strtr($template, $params);
    }
}