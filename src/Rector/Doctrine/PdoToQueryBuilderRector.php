<?php

declare(strict_types=1);

namespace App\Rector\Doctrine;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;
use App\Rector\Doctrine\QueryBuilder\SelectQueryBuilder;
use App\Rector\Doctrine\QueryBuilder\InsertQueryBuilder;
use App\Rector\Doctrine\QueryBuilder\UpdateQueryBuilder;
use App\Rector\Doctrine\QueryBuilder\DeleteQueryBuilder;
use App\Rector\Doctrine\QueryBuilder\QueryBuilderFactory;
use App\Rector\Doctrine\Parser\SqlExtractor;
use App\Rector\Doctrine\Parser\CommonSqlParser;

/**
 * Main Rector rule that converts PDO queries to Doctrine QueryBuilder
 * Now using refactored components with shared utilities
 */
final class PdoToQueryBuilderRector extends AbstractRector
{
    private SqlExtractor $sqlExtractor;
    private CommonSqlParser $commonParser;
    private QueryBuilderFactory $factory;
    private SelectQueryBuilder $selectBuilder;
    private InsertQueryBuilder $insertBuilder;
    private UpdateQueryBuilder $updateBuilder;
    private DeleteQueryBuilder $deleteBuilder;

    public function __construct()
    {
        $this->sqlExtractor = new SqlExtractor();
        $this->commonParser = new CommonSqlParser();
        $this->factory = new QueryBuilderFactory();

        // Initialize builders with shared dependencies
        $this->selectBuilder = new SelectQueryBuilder($this->commonParser, $this->factory);
        $this->insertBuilder = new InsertQueryBuilder($this->commonParser, null, $this->factory);
        $this->updateBuilder = new UpdateQueryBuilder($this->commonParser, null, $this->factory);
        $this->deleteBuilder = new DeleteQueryBuilder($this->commonParser, $this->factory);
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

        return $this->isName($var, 'pdo') ||
            $this->isName($var, 'db') ||
            $this->isName($var, 'connection');
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
            $executeMethod = str_starts_with($sqlUpper, 'SELECT') ? 'executeQuery' : 'executeStatement';

            return new MethodCall($queryBuilder, new Identifier($executeMethod));
        }

        return null;
    }

    private function buildQueryBuilderFromSql(string $sql): ?MethodCall
    {
        $sql = $this->commonParser->normalizeSql($sql);
        $sqlUpper = strtoupper($sql);

        // Crear el QueryBuilder base
        $baseQueryBuilder = $this->createBaseQueryBuilder();

        // Delegar a los builders específicos según el tipo de consulta
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
        return new MethodCall(
            new MethodCall(
                new Variable('this'),
                new Identifier('connection')
            ),
            new Identifier('createQueryBuilder')
        );
    }
}