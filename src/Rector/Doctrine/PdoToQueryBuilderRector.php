<?php

declare(strict_types=1);

namespace App\Rector\Doctrine;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;
use App\Rector\Doctrine\QueryBuilder\SelectQueryBuilder;
use App\Rector\Doctrine\QueryBuilder\InsertQueryBuilder;
use App\Rector\Doctrine\QueryBuilder\UpdateQueryBuilder;
use App\Rector\Doctrine\QueryBuilder\DeleteQueryBuilder;
use App\Rector\Doctrine\Parser\SqlExtractor;

/**
 * Main Rector rule that converts PDO queries to Doctrine QueryBuilder
 */
final class PdoToQueryBuilderRector extends AbstractRector
{
    private SqlExtractor $sqlExtractor;
    private SelectQueryBuilder $selectBuilder;
    private InsertQueryBuilder $insertBuilder;
    private UpdateQueryBuilder $updateBuilder;
    private DeleteQueryBuilder $deleteBuilder;

    public function __construct()
    {
        $this->sqlExtractor = new SqlExtractor();
        $this->selectBuilder = new SelectQueryBuilder();
        $this->insertBuilder = new InsertQueryBuilder();
        $this->updateBuilder = new UpdateQueryBuilder();
        $this->deleteBuilder = new DeleteQueryBuilder();
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
                        ->from('users')
                        ->where('age > :param1')
                        ->andWhere('name = :param2')
                        ->executeQuery()
                        ->fetchAllAssociative();
                    CODE_SAMPLE
                ),
                new CodeSample(
                    <<<'CODE_SAMPLE'
                    $stmt = $pdo->prepare("INSERT INTO users (name, email, age) VALUES (?, ?, ?)");
                    $stmt->execute(['John', 'john@example.com', 25]);
                    CODE_SAMPLE
                    ,
                    <<<'CODE_SAMPLE'
                    $this->connection->createQueryBuilder()
                        ->insert('users')
                        ->setValue('name', ':param1')
                        ->setValue('email', ':param2')
                        ->setValue('age', ':param3')
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
            $executeMethod = strpos($sqlUpper, 'SELECT') === 0 ? 'executeQuery' : 'executeStatement';

            return new MethodCall($queryBuilder, new \PhpParser\Node\Identifier($executeMethod));
        }

        return null;
    }

    private function buildQueryBuilderFromSql(string $sql): ?MethodCall
    {
        $sql = trim($sql);
        $sqlUpper = strtoupper($sql);

        // Crear el QueryBuilder base
        $baseQueryBuilder = $this->createBaseQueryBuilder();

        // Delegar a los builders específicos según el tipo de consulta
        if (strpos($sqlUpper, 'SELECT') === 0) {
            return $this->selectBuilder->build($baseQueryBuilder, $sql);
        }

        if (strpos($sqlUpper, 'INSERT') === 0) {
            return $this->insertBuilder->build($baseQueryBuilder, $sql);
        }

        if (strpos($sqlUpper, 'UPDATE') === 0) {
            return $this->updateBuilder->build($baseQueryBuilder, $sql);
        }

        if (strpos($sqlUpper, 'DELETE') === 0) {
            return $this->deleteBuilder->build($baseQueryBuilder, $sql);
        }

        return null;
    }

    private function createBaseQueryBuilder(): MethodCall
    {
        return new MethodCall(
            new MethodCall(
                new Variable('this'),
                new \PhpParser\Node\Identifier('connection')
            ),
            new \PhpParser\Node\Identifier('createQueryBuilder')
        );
    }
}