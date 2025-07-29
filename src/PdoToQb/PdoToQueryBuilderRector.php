<?php

declare(strict_types=1);

namespace JDR\Rector\PdoToQb;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use Rector\Contract\Rector\ConfigurableRectorInterface;
use Rector\Rector\AbstractRector;
use JDR\Rector\PdoToQb\Parser\CommonSqlParser;
use JDR\Rector\PdoToQb\Parser\SqlExtractor;
use JDR\Rector\PdoToQb\QueryBuilder\DeleteQueryBuilder;
use JDR\Rector\PdoToQb\QueryBuilder\InsertQueryBuilder;
use JDR\Rector\PdoToQb\QueryBuilder\QueryBuilderFactory;
use JDR\Rector\PdoToQb\QueryBuilder\SelectQueryBuilder;
use JDR\Rector\PdoToQb\QueryBuilder\UpdateQueryBuilder;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * Main Rector rule that converts PDO queries to Doctrine QueryBuilder
 * Now configurable with custom variable names and connection methods
 */
final class PdoToQueryBuilderRector extends AbstractRector implements ConfigurableRectorInterface
{
    private array $pdoVariableNames = ['pdo', 'db', 'connection'];
    private string $connectionClause = 'connection';
    /**
     * @readonly
     */
    private SqlExtractor $sqlExtractor;
    /**
     * @readonly
     */
    private CommonSqlParser $commonParser;
    /**
     * @readonly
     */
    private QueryBuilderFactory $factory;
    /**
     * @readonly
     */
    private SelectQueryBuilder $selectBuilder;
    /**
     * @readonly
     */
    private InsertQueryBuilder $insertBuilder;
    /**
     * @readonly
     */
    private UpdateQueryBuilder $updateBuilder;
    /**
     * @readonly
     */
    private DeleteQueryBuilder $deleteBuilder;

    public function __construct(
        array $pdoVariableNames = ['pdo', 'db', 'connection'],
        string $connectionClause = 'connection'
    ) {
        $this->pdoVariableNames = $pdoVariableNames;
        $this->connectionClause = $connectionClause;
        $this->sqlExtractor = new SqlExtractor();
        $this->commonParser = new CommonSqlParser();
        $this->factory = new QueryBuilderFactory();

        // Initialize builders with shared dependencies
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
        $this->connectionClause = $configuration['connectionClause'] ?? 'connection';
    }

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Convierte consultas PDO a QueryBuilder de Doctrine/DBAL usando componentes refactorizados',
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
                new CodeSample(
                    <<<'CODE_SAMPLE'
                    $stmt = $pdo->prepare("
                        SELECT p.*, u.name as author_name
                        FROM posts p
                        INNER JOIN users u ON p.user_id = u.id
                        WHERE p.published = ? AND u.active = ?
                        ORDER BY p.created_at DESC
                        LIMIT 10
                    ");
                    $stmt->execute([1, 1]);
                    $posts = $stmt->fetchAll();
                    CODE_SAMPLE
                    ,
                    <<<'CODE_SAMPLE'
                    $posts = $this->connection->createQueryBuilder()
                        ->select('p.*, u.name as author_name')
                        ->from('posts', 'p')
                        ->innerJoin('p', 'users', 'u', 'p.user_id = u.id')
                        ->where('p.published = :param1')
                        ->andWhere('u.active = :param2')
                        ->addOrderBy('p.created_at', 'DESC')
                        ->setMaxResults(10)
                        ->executeQuery()
                        ->fetchAllAssociative();
                    CODE_SAMPLE
                ),
                new CodeSample(
                    <<<'CODE_SAMPLE'
                    $stmt = $pdo->prepare("UPDATE users SET status = ?, updated_at = NOW() WHERE id = ?");
                    $stmt->execute(['active', $userId]);
                    CODE_SAMPLE
                    ,
                    <<<'CODE_SAMPLE'
                    $this->connection->createQueryBuilder()
                        ->update('users')
                        ->set('status', ':param1')
                        ->set('updated_at', 'NOW()')
                        ->where('id = :param2')
                        ->executeStatement();
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

        return null;
    }

    private function isPdoVariable($var): bool
    {
        if (!$var instanceof Variable) {
            return false;
        }

        // Check against configured PDO variable names
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

        // Para query(), añadir executeQuery() al final para SELECT o executeStatement() para otros
        if ($queryBuilder instanceof MethodCall) {
            $sqlUpper = strtoupper(trim($sql));
            $executeMethod = strncmp($sqlUpper, 'SELECT', strlen('SELECT')) === 0 ? 'executeQuery' : 'executeStatement';

            return new MethodCall($queryBuilder, new Identifier($executeMethod));
        }

        return null;
    }

    private function buildQueryBuilderFromSql(string $sql): ?MethodCall
    {
        $sql = $this->commonParser->normalizeSql($sql);
        $sqlUpper = strtoupper($sql);

        // Reset parameter counter for each query to ensure consistent numbering
        $this->commonParser->resetParameterCounter();

        // Crear el QueryBuilder base
        $baseQueryBuilder = $this->createBaseQueryBuilder();
        if (strncmp($sqlUpper, 'SELECT', strlen('SELECT')) === 0) {
            return $this->selectBuilder->build($baseQueryBuilder, $sql);
        }
        if (strncmp($sqlUpper, 'INSERT', strlen('INSERT')) === 0) {
            return $this->insertBuilder->build($baseQueryBuilder, $sql);
        }
        if (strncmp($sqlUpper, 'UPDATE', strlen('UPDATE')) === 0) {
            return $this->updateBuilder->build($baseQueryBuilder, $sql);
        }
        if (strncmp($sqlUpper, 'DELETE', strlen('DELETE')) === 0) {
            return $this->deleteBuilder->build($baseQueryBuilder, $sql);
        }
        return null;
    }

    private function createBaseQueryBuilder(): MethodCall
    {
        // Detect if connectionClause is a method (has parentheses) or property
        $isMethod = strpos($this->connectionClause, '()') !== false;

        if ($isMethod) {
            // Remove parentheses to get method name
            $methodName = str_replace('()', '', $this->connectionClause);

            // Create method call: $this->getConnection()->createQueryBuilder()
            $connectionCall = new MethodCall(
                new Variable('this'),
                new Identifier($methodName)
            );
        } else {
            // Create property access: $this->connection->createQueryBuilder()
            $connectionCall = new PropertyFetch(
                new Variable('this'),
                new Identifier($this->connectionClause)
            );
        }

        return new MethodCall(
            $connectionCall,
            new Identifier('createQueryBuilder')
        );
    }
}