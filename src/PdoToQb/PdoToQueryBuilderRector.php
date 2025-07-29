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
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use Rector\Contract\Rector\ConfigurableRectorInterface;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * Main Rector rule that converts PDO queries to Doctrine QueryBuilder
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

    // Configuration properties
    private array $pdoVariableNames = ['pdo', 'db', 'connection'];
    private string $connectionProperty = 'connection';
    private array $commonVarNames = ['query', 'stmt', 'statement', 'result', 'q'];

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
        $this->pdoVariableNames = $configuration['pdoVariableNames'] ?? ['pdo', 'db', 'connection'];
        $this->connectionProperty = $configuration['connectionProperty'] ?? 'connection';
        $this->commonVarNames = $configuration['commonVarNames'] ?? ['query', 'stmt', 'statement', 'result', 'q'];
    }

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Convierte consultas PDO a QueryBuilder de Doctrine/DBAL',
            [
                new CodeSample(
                    <<<'CODE_SAMPLE'
                    $stmt = $pdo->prepare("SELECT * FROM users WHERE age > ? AND name = ?");
                    $stmt->execute([25, 'John']);
                    $users = $stmt->fetchAll();
                    CODE_SAMPLE
                    ,
                    <<<'CODE_SAMPLE'
                    $users = $this->connection->createQueryBuilder()
                        ->select('*')
                        ->from('users', 'users')
                        ->where('age > :param1')
                        ->andWhere('name = :param2')
                        ->executeQuery()
                        ->fetchAllAssociative();
                    CODE_SAMPLE
                ),
            ]
        );
    }

    public function getNodeTypes(): array
    {
        return [MethodCall::class];
    }

    public function refactor(Node $node): ?Node
    {
        if (!$node instanceof MethodCall) {
            return null;
        }

        // Detectar prepare() de PDO
        if ($this->isName($node->name, 'prepare') && $this->isPdoVariable($node->var)) {
            return $this->convertPdoPrepareToQueryBuilder($node);
        }

        // Detectar query() de PDO
        if ($this->isName($node->name, 'query') && $this->isPdoVariable($node->var)) {
            return $this->convertPdoQueryToQueryBuilder($node);
        }

        // Detectar métodos en variables que fueron convertidas de PDO prepare()
        if ($this->isPotentialQueryBuilderMethod($node)) {
            return $this->convertQueryBuilderMethod($node);
        }

        return null;
    }


    private function isPdoVariable($var): bool
    {
        if (!$var instanceof Variable) {
            return false;
        }

        foreach ($this->pdoVariableNames as $variableName) {
            if ($this->isName($var, $variableName)) {
                return true;
            }
        }

        return false;
    }

    private function convertPdoPrepareToQueryBuilder(MethodCall $node): ?Node
    {
        if (!isset($node->args[0])) {
            return null;
        }

        $sql = $this->sqlExtractor->extractSqlFromNode($node->args[0]->value);
        if ($sql === null) {
            return null;
        }

        return $this->buildQueryBuilderFromSql($sql);
    }

    private function convertPdoQueryToQueryBuilder(MethodCall $node): ?Node
    {
        if (!isset($node->args[0])) {
            return null;
        }

        $sql = $this->sqlExtractor->extractSqlFromNode($node->args[0]->value);
        if ($sql === null) {
            return null;
        }

        $queryBuilder = $this->buildQueryBuilderFromSql($sql);

        if ($queryBuilder instanceof MethodCall) {
            $sqlUpper = strtoupper(trim($sql));
            $executeMethod = str_starts_with($sqlUpper, 'SELECT') ? 'executeQuery' : 'executeStatement';

            return new MethodCall($queryBuilder, new Identifier($executeMethod));
        }

        return null;
    }

    private function buildQueryBuilderFromSql(string $sql): ?MethodCall
    {
        $sql = $this->commonParser->normalizeSql($sql);
        $sqlUpper = strtoupper($sql);

        // Reset parameter counter for each query
        $this->commonParser->resetParameterCounter();

        $baseQueryBuilder = $this->createBaseQueryBuilder();

        return match (true) {
            str_starts_with($sqlUpper, 'SELECT') => $this->selectBuilder->build($baseQueryBuilder, $sql),
            str_starts_with($sqlUpper, 'INSERT') => $this->insertBuilder->build($baseQueryBuilder, $sql),
            str_starts_with($sqlUpper, 'UPDATE') => $this->updateBuilder->build($baseQueryBuilder, $sql),
            str_starts_with($sqlUpper, 'DELETE') => $this->deleteBuilder->build($baseQueryBuilder, $sql),
            default => null,
        };
    }

    private function createBaseQueryBuilder(): MethodCall
    {
        // Detect if connectionProperty is a method (has parentheses) or property
        $isMethod = str_contains($this->connectionProperty, '()');

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
     * Check if this method call is potentially on a QueryBuilder variable
     */
    private function isPotentialQueryBuilderMethod(MethodCall $node): bool
    {
        if (!$node->var instanceof Variable) {
            return false;
        }

        $commonQueryVarNames = $this->commonVarNames;

        foreach ($commonQueryVarNames as $varName) {
            if ($this->isName($node->var, $varName)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Convert QueryBuilder method calls (execute, fetch, etc.)
     */
    private function convertQueryBuilderMethod(MethodCall $node): ?MethodCall
    {
        $methodName = $this->getName($node->name);

        return match ($methodName) {
            'execute' => $this->convertExecuteMethod($node),
            'fetch' => $this->convertFetchMethod($node),
            'fetchAll' => $this->convertFetchAllMethod($node),
            'fetchAssoc' => $this->convertFetchAssocMethod($node),
            'fetchColumn' => $this->convertFetchColumnMethod($node),
            default => null,
        };
    }

    /**
     * Convert $query->execute() to $query->executeQuery()
     */
    private function convertExecuteMethod(MethodCall $node): MethodCall
    {
        return new MethodCall(
            $node->var,
            new Identifier('executeQuery'),
            $node->args
        );
    }

    /**
     * Convert $query->fetch() to $query->fetchAssociative()
     */
    private function convertFetchMethod(MethodCall $node): MethodCall
    {
        return new MethodCall(
            $node->var,
            new Identifier('fetchAssociative')
        );
    }

    /**
     * Convert $query->fetchAll() to $query->fetchAllAssociative()
     */
    private function convertFetchAllMethod(MethodCall $node): MethodCall
    {
        return new MethodCall(
            $node->var,
            new Identifier('fetchAllAssociative')
        );
    }

    /**
     * Convert $query->fetchAssoc() to $query->fetchAssociative()
     */
    private function convertFetchAssocMethod(MethodCall $node): MethodCall
    {
        return new MethodCall(
            $node->var,
            new Identifier('fetchAssociative')
        );
    }

    /**
     * Convert $query->fetchColumn() to $query->fetchOne()
     */
    private function convertFetchColumnMethod(MethodCall $node): MethodCall
    {
        return new MethodCall(
            $node->var,
            new Identifier('fetchOne')
        );
    }
}
