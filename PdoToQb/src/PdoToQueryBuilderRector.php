<?php

declare(strict_types=1);

namespace JDR\Rector\PdoToQb;

use JDR\Rector\PdoToQb\Parser\CommonSqlParser;
use JDR\Rector\PdoToQb\Parser\SqlExtractor;
use JDR\Rector\PdoToQb\QueryBuilder\DeleteQueryBuilder;
use JDR\Rector\PdoToQb\QueryBuilder\InsertQueryBuilder;
use JDR\Rector\PdoToQb\QueryBuilder\QueryBuilderFactory;
use JDR\Rector\PdoToQb\QueryBuilder\SelectQueryBuilder;
use JDR\Rector\PdoToQb\QueryBuilder\UpdateQueryBuilder;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayItem;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Expression;
use Rector\Contract\Rector\ConfigurableRectorInterface;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * Enhanced Rector rule that converts PDO queries to Doctrine QueryBuilder
 * with intelligent type tracking and variable state management
 */
final class PdoToQueryBuilderRector extends AbstractRector implements ConfigurableRectorInterface
{
    private SqlExtractor $sqlExtractor;
    private CommonSqlParser $commonParser;
    private QueryBuilderFactory $factory;
    private SelectQueryBuilder $selectBuilder;
    private InsertQueryBuilder $insertBuilder;
    private UpdateQueryBuilder $updateBuilder;
    private DeleteQueryBuilder $deleteBuilder;

    // Track variables that have been converted to QueryBuilder in current scope
    private array $queryBuilderVariables = [];

    // Track SQL type for each converted variable (for determining executeQuery vs executeStatement)
    private array $variableSqlTypes = [];

    // Track if a variable was assigned from a prepare() call
    private array $preparedStatementVariables = [];

    // Configuration properties
    private array $pdoVariableNames = ['pdo', 'db', 'connection', 'database', 'conn'];
    private array $pdoPropertyNames = ['_db', 'db', 'pdo', 'connection', 'database', 'conn', 'dbConnection'];
    private string $connectionProperty = 'connection';
    private bool $autoDetectPdoVariables = true;

    public function __construct()
    {
        $this->sqlExtractor = new SqlExtractor();
        $this->commonParser = new CommonSqlParser();
        $this->factory = new QueryBuilderFactory();

        $this->selectBuilder = new SelectQueryBuilder($this->commonParser, $this->factory);
        $this->insertBuilder = new InsertQueryBuilder($this->commonParser, null, $this->factory);
        $this->updateBuilder = new UpdateQueryBuilder($this->commonParser, null, $this->factory);
        $this->deleteBuilder = new DeleteQueryBuilder($this->commonParser, $this->factory);
    }

    /**
     * Configure the rector with custom settings
     */
    public function configure(array $configuration): void
    {
        $this->pdoVariableNames = $configuration['pdoVariableNames'] ?? $this->pdoVariableNames;
        $this->pdoPropertyNames = $configuration['pdoPropertyNames'] ?? $this->pdoPropertyNames;
        $this->connectionProperty = $configuration['connectionProperty'] ?? $this->connectionProperty;
        $this->autoDetectPdoVariables = $configuration['autoDetectPdoVariables'] ?? true;
    }

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Converts PDO queries to Doctrine QueryBuilder with intelligent type tracking',
            [
                new CodeSample(
                    <<<'CODE_SAMPLE'
                    $stmt = $pdo->prepare("SELECT * FROM users WHERE age > ? AND name = ?");
                    $stmt->execute([25, 'John']);
                    $users = $stmt->fetchAll();
                    CODE_SAMPLE
                    ,
                    <<<'CODE_SAMPLE'
                    $stmt = $this->connection->createQueryBuilder()
                        ->select('*')
                        ->from('users', 'users')
                        ->where('age > :param1')
                        ->andWhere('name = :param2');
                    $stmt->setParameters(['param1' => 25, 'param2' => 'John'])
                        ->executeQuery();
                    $users = $stmt->fetchAllAssociative();
                    CODE_SAMPLE
                ),
            ]
        );
    }

    public function getNodeTypes(): array
    {
        return [Expression::class, MethodCall::class];
    }

    public function refactor(Node $node): ?Node
    {
        // Handle Expression nodes to track assignments
        if ($node instanceof Expression) {
            return $this->processExpression($node);
        }

        // Handle MethodCall nodes
        if ($node instanceof MethodCall) {
            return $this->processMethodCall($node);
        }

        return null;
    }

    /**
     * Process Expression nodes to track variable assignments
     */
    private function processExpression(Expression $node): ?Expression
    {
        if (!$node->expr instanceof Assign) {
            return null;
        }

        $assign = $node->expr;

        // Check if this is an assignment from a prepare() call
        if ($assign->expr instanceof MethodCall &&
            $this->isName($assign->expr->name, 'prepare') &&
            $this->isPdoVariable($assign->expr->var)) {

            // Track this variable as a prepared statement
            $varName = $this->extractVariableName($assign->var);
            // Try to extract SQL
            if ($varName !== null && isset($assign->expr->args[0])) {
                $sql = $this->sqlExtractor->extractSqlFromNode($assign->expr->args[0]->value);
                if ($sql !== null) {
                    // SQL was successfully extracted - we can convert
                    $this->variableSqlTypes[$varName] = $this->determineSqlType($sql);

                    // Convert the prepare() call
                    $convertedExpr = $this->convertPdoPrepareToQueryBuilder($assign->expr);
                    if ($convertedExpr instanceof MethodCall) {
                        // Mark as QueryBuilder ONLY if conversion was successful
                        $this->queryBuilderVariables[$varName] = true;
                        $assign->expr = $convertedExpr;
                        return $node;
                    }
                } else {
                    // SQL could not be extracted (it's a variable, not a literal)
                    // Mark this as a PDO prepared statement, NOT a QueryBuilder
                    $this->preparedStatementVariables[$varName] = true;
                    // DO NOT mark as queryBuilderVariables
                    // DO NOT convert the prepare() call
                }
            }
        }

        return null;
    }

    /**
     * Process MethodCall nodes
     */
    private function processMethodCall(MethodCall $node): ?Node
    {
        // Handle chained prepare()->execute()
        if ($this->isName($node->name, 'execute') &&
            $node->var instanceof MethodCall &&
            $this->isName($node->var->name, 'prepare') &&
            $this->isPdoVariable($node->var->var)) {

            // Only convert if we can extract the SQL
            if (isset($node->var->args[0])) {
                $sql = $this->sqlExtractor->extractSqlFromNode($node->var->args[0]->value);
                if ($sql !== null) {
                    return $this->convertChainedPrepareExecute($node);
                }
            }
            // If SQL cannot be extracted, don't convert
            return null;
        }

        // Handle standalone prepare()
        if ($this->isName($node->name, 'prepare') && $this->isPdoVariable($node->var)) {
            // Only convert if SQL can be extracted
            if (isset($node->args[0])) {
                $sql = $this->sqlExtractor->extractSqlFromNode($node->args[0]->value);
                if ($sql !== null) {
                    return $this->convertPdoPrepareToQueryBuilder($node);
                }
            }
            // If SQL cannot be extracted, leave as is
            return null;
        }

        // Handle query()
        if ($this->isName($node->name, 'query') && $this->isPdoVariable($node->var)) {
            return $this->convertPdoQueryToQueryBuilder($node);
        }

        // Handle execute() on variables
        if ($this->isName($node->name, 'execute')) {
            $varName = $this->extractVariableName($node->var);
            if ($varName !== null) {
                // ONLY convert if this is a known QueryBuilder variable
                if (isset($this->queryBuilderVariables[$varName])) {
                    return $this->convertExecuteWithParametersToQueryBuilder($node, $varName);
                }
                // If it's a PDO prepared statement (not converted), leave it alone
                // DO NOT convert execute() to executeQuery()
            }
        }

        // Handle fetch methods - ONLY on known QueryBuilder variables
        if ($this->isFetchMethod($node->name)) {
            $varName = $this->extractVariableName($node->var);
            if ($varName !== null && isset($this->queryBuilderVariables[$varName])) {
                return $this->convertFetchMethod($node);
            }
            // If not a QueryBuilder, leave the fetch method as is
        }

        return null;
    }

    /**
     * Extract variable name from various node types
     */
    private function extractVariableName($node): ?string
    {
        if ($node instanceof Variable) {
            return $this->getName($node);
        }

        if ($node instanceof PropertyFetch) {
            // For property fetches like $this->stmt, create a unique identifier
            $object = $this->getName($node->var);
            $property = $this->getName($node->name);
            if ($object !== null && $property !== null) {
                return "{$object}->{$property}";
            }
        }

        return null;
    }

    /**
     * Determine SQL type from SQL string
     */
    private function determineSqlType(string $sql): string
    {
        $sql = $this->commonParser->normalizeSql($sql);
        $sqlUpper = strtoupper($sql);

        switch (true) {
            case strncmp($sqlUpper, 'SELECT', strlen('SELECT')) === 0:
                return 'SELECT';
            case strncmp($sqlUpper, 'INSERT', strlen('INSERT')) === 0:
                return 'INSERT';
            case strncmp($sqlUpper, 'UPDATE', strlen('UPDATE')) === 0:
                return 'UPDATE';
            case strncmp($sqlUpper, 'DELETE', strlen('DELETE')) === 0:
                return 'DELETE';
            default:
                return 'UNKNOWN';
        }
    }

    /**
     * Check if a variable is a PDO instance
     */
    private function isPdoVariable($var): bool
    {
        // Handle direct variables: $pdo, $db, $connection
        if ($var instanceof Variable) {
            $varName = $this->getName($var);
            if ($varName !== null) {
                // Check configured names
                foreach ($this->pdoVariableNames as $pdoName) {
                    if ($varName === $pdoName) {
                        return true;
                    }
                }

                // Auto-detect if enabled
                if ($this->autoDetectPdoVariables) {
                    $lowerVarName = strtolower($varName);
                    if (preg_match('/\b(pdo|db|database|conn|connection)\b/i', $lowerVarName)) {
                        return true;
                    }
                }
            }
        }

        // Handle property access: $this->_db, $this->pdo, etc.
        if ($var instanceof PropertyFetch &&
            $var->var instanceof Variable &&
            $this->isName($var->var, 'this')) {

            $propertyName = $this->getName($var->name);
            if ($propertyName !== null) {
                // Check configured property names
                foreach ($this->pdoPropertyNames as $pdoProperty) {
                    if ($propertyName === $pdoProperty) {
                        return true;
                    }
                }

                // Auto-detect if enabled
                if ($this->autoDetectPdoVariables) {
                    $lowerPropertyName = strtolower($propertyName);
                    if (preg_match('/\b(pdo|db|database|conn|connection)\b/i', $lowerPropertyName)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Convert chained prepare()->execute() calls
     */
    private function convertChainedPrepareExecute(MethodCall $node): ?MethodCall
    {
        $prepareCall = $node->var;

        if (!isset($prepareCall->args[0])) {
            return null;
        }

        $sql = $this->sqlExtractor->extractSqlFromNode($prepareCall->args[0]->value);
        if ($sql === null) {
            return null;
        }

        $queryBuilder = $this->buildQueryBuilderFromSql($sql);
        if (!$queryBuilder instanceof MethodCall) {
            return null;
        }

        // Handle parameters if present
        if (isset($node->args[0])) {
            $parametersArg = $node->args[0]->value;
            $convertedParameters = $this->convertParametersArray($parametersArg);

            if ($convertedParameters instanceof Array_) {
                $queryBuilder = new MethodCall(
                    $queryBuilder,
                    new Identifier('setParameters'),
                    [new Arg($convertedParameters)]
                );
            }
        }

        // Determine execute method based on SQL type
        $sqlType = $this->determineSqlType($sql);
        $executeMethod = $sqlType === 'SELECT' ? 'executeQuery' : 'executeStatement';

        return new MethodCall(
            $queryBuilder,
            new Identifier($executeMethod)
        );
    }

    /**
     * Convert PDO prepare() to QueryBuilder
     * Returns null if SQL cannot be extracted (e.g., it's a variable)
     */
    private function convertPdoPrepareToQueryBuilder(MethodCall $node): ?MethodCall
    {
        if (!isset($node->args[0])) {
            return null;
        }

        $sql = $this->sqlExtractor->extractSqlFromNode($node->args[0]->value);
        if ($sql === null) {
            // Cannot extract SQL (it's likely a variable), don't convert
            return null;
        }

        return $this->buildQueryBuilderFromSql($sql);
    }

    /**
     * Convert PDO query() to QueryBuilder
     */
    private function convertPdoQueryToQueryBuilder(MethodCall $node): ?MethodCall
    {
        if (!isset($node->args[0])) {
            return null;
        }

        $sql = $this->sqlExtractor->extractSqlFromNode($node->args[0]->value);
        if ($sql === null) {
            return null;
        }

        $queryBuilder = $this->buildQueryBuilderFromSql($sql);
        if (!$queryBuilder instanceof MethodCall) {
            return null;
        }

        $sqlType = $this->determineSqlType($sql);
        $executeMethod = $sqlType === 'SELECT' ? 'executeQuery' : 'executeStatement';

        return new MethodCall($queryBuilder, new Identifier($executeMethod));
    }

    /**
     * Convert execute() with parameters on QueryBuilder
     */
    private function convertExecuteWithParametersToQueryBuilder(MethodCall $node, string $varName): ?MethodCall
    {
        // If no parameters, just convert to appropriate execute method
        if (!isset($node->args[0])) {
            $sqlType = $this->variableSqlTypes[$varName] ?? 'UNKNOWN';
            $executeMethod = $sqlType === 'SELECT' ? 'executeQuery' : 'executeStatement';

            return new MethodCall(
                $node->var,
                new Identifier($executeMethod)
            );
        }

        // Convert parameters
        $parametersArg = $node->args[0]->value;
        $convertedParameters = $this->convertParametersArray($parametersArg);

        if (!$convertedParameters instanceof Array_) {
            // Fallback to basic execute
            $sqlType = $this->variableSqlTypes[$varName] ?? 'UNKNOWN';
            $executeMethod = $sqlType === 'SELECT' ? 'executeQuery' : 'executeStatement';

            return new MethodCall(
                $node->var,
                new Identifier($executeMethod)
            );
        }

        // Chain setParameters()->executeQuery/Statement()
        $setParametersCall = new MethodCall(
            $node->var,
            new Identifier('setParameters'),
            [new Arg($convertedParameters)]
        );

        $sqlType = $this->variableSqlTypes[$varName] ?? 'UNKNOWN';
        $executeMethod = $sqlType === 'SELECT' ? 'executeQuery' : 'executeStatement';

        return new MethodCall(
            $setParametersCall,
            new Identifier($executeMethod)
        );
    }

    /**
     * Check if this is a fetch method
     */
    private function isFetchMethod(Node $methodName): bool
    {
        $name = $this->getName($methodName);
        return in_array($name, ['fetch', 'fetchAll', 'fetchAssoc', 'fetchColumn'], true);
    }

    /**
     * Convert fetch methods to QueryBuilder equivalents
     */
    private function convertFetchMethod(MethodCall $node): ?MethodCall
    {
        $methodName = $this->getName($node->name);

        switch ($methodName) {
            case 'fetch':
                return new MethodCall($node->var, new Identifier('fetchAssociative'));
            case 'fetchAll':
                return new MethodCall($node->var, new Identifier('fetchAllAssociative'));
            case 'fetchAssoc':
                return new MethodCall($node->var, new Identifier('fetchAssociative'));
            case 'fetchColumn':
                return new MethodCall($node->var, new Identifier('fetchOne'));
            default:
                return null;
        }
    }

    /**
     * Convert parameters array from PDO format to QueryBuilder format
     */
    private function convertParametersArray(Node $parametersNode): ?Array_
    {
        if (!$parametersNode instanceof Array_) {
            return null;
        }

        $convertedItems = [];
        $positionalIndex = 1;

        foreach ($parametersNode->items as $item) {
            if (!$item instanceof ArrayItem) {
                continue;
            }

            if ($item->key === null) {
                // Positional parameter
                $convertedItems[] = new ArrayItem(
                    $item->value,
                    new String_("param{$positionalIndex}")
                );
                $positionalIndex++;
            } else {
                // Named parameter - remove leading colon if present
                $key = $this->extractStringValue($item->key);
                if ($key !== null) {
                    $cleanKey = ltrim($key, ':');
                    $convertedItems[] = new ArrayItem(
                        $item->value,
                        new String_($cleanKey)
                    );
                } else {
                    $convertedItems[] = $item;
                }
            }
        }

        return new Array_($convertedItems);
    }

    /**
     * Extract string value from a node
     */
    private function extractStringValue(Node $node): ?string
    {
        if ($node instanceof String_) {
            return $node->value;
        }
        return null;
    }

    /**
     * Build QueryBuilder from SQL string
     */
    private function buildQueryBuilderFromSql(string $sql): ?MethodCall
    {
        $sql = $this->commonParser->normalizeSql($sql);
        $sqlType = $this->determineSqlType($sql);

        // Reset parameter counter for each query
        $this->commonParser->resetParameterCounter();

        $baseQueryBuilder = $this->createBaseQueryBuilder();

        switch ($sqlType) {
            case 'SELECT':
                return $this->selectBuilder->build($baseQueryBuilder, $sql);
            case 'INSERT':
                return $this->insertBuilder->build($baseQueryBuilder, $sql);
            case 'UPDATE':
                return $this->updateBuilder->build($baseQueryBuilder, $sql);
            case 'DELETE':
                return $this->deleteBuilder->build($baseQueryBuilder, $sql);
            default:
                return null;
        }
    }

    /**
     * Create base QueryBuilder instance
     */
    private function createBaseQueryBuilder(): MethodCall
    {
        $isMethod = strpos($this->connectionProperty, '()') !== false;

        if ($isMethod) {
            $methodName = str_replace('()', '', $this->connectionProperty);
            $connectionCall = new MethodCall(
                new Variable('this'),
                new Identifier($methodName)
            );
        } else {
            $connectionCall = new PropertyFetch(
                new Variable('this'),
                new Identifier($this->connectionProperty)
            );
        }

        return new MethodCall(
            $connectionCall,
            new Identifier('createQueryBuilder')
        );
    }

    /**
     * Clear tracked variables (call this between files/classes if needed)
     */
    public function clearTrackedVariables(): void
    {
        $this->queryBuilderVariables = [];
        $this->variableSqlTypes = [];
        $this->preparedStatementVariables = [];
    }
}