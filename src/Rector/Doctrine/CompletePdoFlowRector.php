<?php

// src/Rector/Doctrine/CompletePdoFlowRector.php
declare(strict_types=1);

namespace App\Rector\Doctrine;

use PhpParser\Node;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Arg;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Scalar\LNumber;
use PhpParser\Node\Stmt\Return_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\ClassMethod;
use Rector\Rector\AbstractRector;
use Rector\Contract\Rector\RectorInterface;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

final class CompletePdoFlowRector extends AbstractRector implements RectorInterface
{
    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Convierte el flujo completo PDO (prepare + execute + fetch) a QueryBuilder',
            [
                new CodeSample(
                    <<<'CODE_SAMPLE'
$stmt = $this->pdo->prepare("SELECT * FROM users WHERE status = 'active'");
$stmt->execute();
return $stmt->fetchAll();
CODE_SAMPLE
                    ,
                    <<<'CODE_SAMPLE'
return $this->connection->createQueryBuilder()
    ->select('*')
    ->from('users', 'users')
    ->where('status = \'active\'')
    ->executeQuery()
    ->fetchAllAssociative();
CODE_SAMPLE
                ),
            ]
        );
    }

    public function getNodeTypes(): array
    {
        return [ClassMethod::class];
    }

    public function refactor(Node $node): ?Node
    {
        if (!$node instanceof ClassMethod) {
            return null;
        }

        // Buscar el patrón PDO en el método
        $pdoPattern = $this->findPdoPattern($node);
        if (!$pdoPattern) {
            return null;
        }

        // Transformar el método
        $this->transformMethod($node, $pdoPattern);

        return $node;
    }

    private function findPdoPattern(ClassMethod $method): ?array
    {
        $stmts = $method->stmts ?? [];
        $prepareStmt = null;
        $executeStmt = null;
        $returnStmt = null;
        $stmtVar = null;

        foreach ($stmts as $i => $stmt) {
            // Buscar $stmt = $pdo->prepare(...)
            if ($stmt instanceof Expression && $stmt->expr instanceof Assign) {
                $assign = $stmt->expr;
                if ($assign->expr instanceof MethodCall &&
                    $this->isName($assign->expr->name, 'prepare') &&
                    $this->isPdoVariable($assign->expr->var)) {

                    $prepareStmt = ['stmt' => $stmt, 'index' => $i, 'assign' => $assign];
                    $stmtVar = $assign->var;
                }
            }

            // Buscar $stmt->execute()
            if ($stmt instanceof Expression && $stmt->expr instanceof MethodCall) {
                $methodCall = $stmt->expr;
                if ($this->isName($methodCall->name, 'execute') &&
                    $stmtVar && $this->areVariablesEqual($methodCall->var, $stmtVar)) {

                    $executeStmt = ['stmt' => $stmt, 'index' => $i];
                }
            }

            // Buscar return $stmt->fetchAll()
            if ($stmt instanceof Return_ && $stmt->expr instanceof MethodCall) {
                $methodCall = $stmt->expr;
                if ($this->isFetchMethod($methodCall->name) &&
                    $stmtVar && $this->areVariablesEqual($methodCall->var, $stmtVar)) {

                    $returnStmt = ['stmt' => $stmt, 'index' => $i, 'method' => $methodCall];
                }
            }
        }

        if ($prepareStmt && $executeStmt && $returnStmt) {
            return [
                'prepare' => $prepareStmt,
                'execute' => $executeStmt,
                'return' => $returnStmt,
                'stmtVar' => $stmtVar
            ];
        }

        return null;
    }

    private function transformMethod(ClassMethod $method, array $pdoPattern): void
    {
        // Extraer SQL del prepare
        $prepareCall = $pdoPattern['prepare']['assign']->expr;
        $sql = $this->extractSqlFromPrepare($prepareCall);

        if (!$sql) {
            return;
        }

        // Crear QueryBuilder
        $queryBuilder = $this->buildQueryBuilderFromSql($sql);
        if (!$queryBuilder) {
            return;
        }

        // Determinar método fetch
        $fetchMethod = $this->convertFetchMethod($pdoPattern['return']['method']);

        // Crear nueva expresión completa
        $executeQuery = new MethodCall($queryBuilder, new Identifier('executeQuery'));
        $finalMethod = new MethodCall($executeQuery, new Identifier($fetchMethod));
        $newReturn = new Return_($finalMethod);

        // Eliminar statements prepare y execute, reemplazar return
        $stmts = $method->stmts ?? [];
        $newStmts = [];

        foreach ($stmts as $i => $stmt) {
            // Saltar prepare y execute
            if ($i === $pdoPattern['prepare']['index'] ||
                $i === $pdoPattern['execute']['index']) {
                continue;
            }

            // Reemplazar return
            if ($i === $pdoPattern['return']['index']) {
                $newStmts[] = $newReturn;
            } else {
                $newStmts[] = $stmt;
            }
        }

        $method->stmts = $newStmts;
    }

    private function isPdoVariable($var): bool
    {
        if (!$var instanceof MethodCall) {
            return false;
        }

        if (!$var->var instanceof Variable || !$this->isName($var->var, 'this')) {
            return false;
        }

        return $this->isName($var->name, 'pdo') ||
            $this->isName($var->name, 'db') ||
            $this->isName($var->name, 'connection');
    }

    private function areVariablesEqual($var1, $var2): bool
    {
        if (!$var1 instanceof Variable || !$var2 instanceof Variable) {
            return false;
        }

        return $this->getName($var1) === $this->getName($var2);
    }

    private function isFetchMethod($name): bool
    {
        return $this->isName($name, 'fetch') ||
            $this->isName($name, 'fetchAll') ||
            $this->isName($name, 'fetchColumn') ||
            $this->isName($name, 'fetchObject');
    }

    private function extractSqlFromPrepare(MethodCall $prepareCall): ?string
    {
        if (!isset($prepareCall->args[0])) {
            return null;
        }

        $sqlArg = $prepareCall->args[0]->value;
        if (!$sqlArg instanceof String_) {
            return null;
        }

        return $sqlArg->value;
    }

    private function convertFetchMethod(MethodCall $methodCall): string
    {
        $methodName = $this->getName($methodCall->name);

        switch ($methodName) {
            case 'fetch':
                return 'fetchAssociative';
            case 'fetchAll':
                return 'fetchAllAssociative';
            case 'fetchColumn':
                return 'fetchOne';
            case 'fetchObject':
                return 'fetchAssociative';
            default:
                return 'fetchAllAssociative';
        }
    }

    private function buildQueryBuilderFromSql(string $sql): ?MethodCall
    {
        $sql = trim($sql);
        $sql = preg_replace('/\s+/', ' ', $sql);

        // Crear QueryBuilder base
        $queryBuilder = new MethodCall(
            new MethodCall(
                new Variable('this'),
                new Identifier('connection')
            ),
            new Identifier('createQueryBuilder')
        );

        // Solo manejar SELECT por ahora
        if (stripos($sql, 'SELECT') === 0) {
            return $this->buildSelectQuery($queryBuilder, $sql);
        }

        return $queryBuilder;
    }

    private function buildSelectQuery(MethodCall $queryBuilder, string $sql): MethodCall
    {
        $parts = $this->parseSelectQuery($sql);

        // SELECT clause
        $selectClause = $parts['select'] ?? '*';
        $queryBuilder = new MethodCall(
            $queryBuilder,
            new Identifier('select'),
            [new Arg(new String_($selectClause))]
        );

        // FROM clause
        if (!empty($parts['from'])) {
            $fromParts = $this->parseFromClause($parts['from']);
            $args = [new Arg(new String_($fromParts['table']))];
            if (!empty($fromParts['alias'])) {
                $args[] = new Arg(new String_($fromParts['alias']));
            } else {
                $args[] = new Arg(new String_($fromParts['table']));
            }

            $queryBuilder = new MethodCall(
                $queryBuilder,
                new Identifier('from'),
                $args
            );
        }

        // JOIN clauses
        if (!empty($parts['joins'])) {
            foreach ($parts['joins'] as $join) {
                $queryBuilder = $this->addJoinToQueryBuilder($queryBuilder, $join);
            }
        }

        // WHERE clause
        if (!empty($parts['where'])) {
            $whereClause = $this->normalizeWhereClause($parts['where']);
            $queryBuilder = new MethodCall(
                $queryBuilder,
                new Identifier('where'),
                [new Arg(new String_($whereClause))]
            );
        }

        // GROUP BY
        if (!empty($parts['groupBy'])) {
            $groupByFields = array_map('trim', explode(',', $parts['groupBy']));
            foreach ($groupByFields as $field) {
                $queryBuilder = new MethodCall(
                    $queryBuilder,
                    new Identifier('addGroupBy'),
                    [new Arg(new String_($field))]
                );
            }
        }

        // HAVING
        if (!empty($parts['having'])) {
            $queryBuilder = new MethodCall(
                $queryBuilder,
                new Identifier('having'),
                [new Arg(new String_($parts['having']))]
            );
        }

        // ORDER BY
        if (!empty($parts['orderBy'])) {
            $orderByFields = array_map('trim', explode(',', $parts['orderBy']));
            foreach ($orderByFields as $orderField) {
                $orderParts = preg_split('/\s+/', trim($orderField));
                $field = $orderParts[0];
                $direction = strtoupper($orderParts[1] ?? 'ASC');

                $queryBuilder = new MethodCall(
                    $queryBuilder,
                    new Identifier('addOrderBy'),
                    [
                        new Arg(new String_($field)),
                        new Arg(new String_($direction))
                    ]
                );
            }
        }

        // LIMIT
        if (!empty($parts['limit'])) {
            $queryBuilder = new MethodCall(
                $queryBuilder,
                new Identifier('setMaxResults'),
                [new Arg(new LNumber((int) $parts['limit']))]
            );
        }

        // OFFSET
        if (!empty($parts['offset'])) {
            $queryBuilder = new MethodCall(
                $queryBuilder,
                new Identifier('setFirstResult'),
                [new Arg(new LNumber((int) $parts['offset']))]
            );
        }

        return $queryBuilder;
    }

    private function parseSelectQuery(string $sql): array
    {
        $parts = [];
        $sql = preg_replace('/\s+/', ' ', trim($sql));

        // SELECT
        if (preg_match('/SELECT\s+(.+?)\s+FROM/i', $sql, $matches)) {
            $parts['select'] = trim($matches[1]);
        }

        // FROM
        if (preg_match('/FROM\s+(\w+)(?:\s+(?:AS\s+)?(\w+))?(?:\s|$)/i', $sql, $matches)) {
            $parts['from'] = trim($matches[1]);
            if (!empty($matches[2])) {
                $parts['fromAlias'] = trim($matches[2]);
            }
        }

        // JOINs
        $parts['joins'] = $this->parseJoins($sql);

        // WHERE
        if (preg_match('/WHERE\s+(.+?)(?:\s+GROUP\s+BY|\s+HAVING|\s+ORDER\s+BY|\s+LIMIT|\s+OFFSET|$)/i', $sql, $matches)) {
            $parts['where'] = trim($matches[1]);
        }

        // GROUP BY
        if (preg_match('/GROUP\s+BY\s+(.+?)(?:\s+HAVING|\s+ORDER\s+BY|\s+LIMIT|\s+OFFSET|$)/i', $sql, $matches)) {
            $parts['groupBy'] = trim($matches[1]);
        }

        // HAVING
        if (preg_match('/HAVING\s+(.+?)(?:\s+ORDER\s+BY|\s+LIMIT|\s+OFFSET|$)/i', $sql, $matches)) {
            $parts['having'] = trim($matches[1]);
        }

        // ORDER BY
        if (preg_match('/ORDER\s+BY\s+(.+?)(?:\s+LIMIT|\s+OFFSET|$)/i', $sql, $matches)) {
            $parts['orderBy'] = trim($matches[1]);
        }

        // LIMIT
        if (preg_match('/LIMIT\s+(\d+)/i', $sql, $matches)) {
            $parts['limit'] = $matches[1];
        }

        // OFFSET
        if (preg_match('/OFFSET\s+(\d+)/i', $sql, $matches)) {
            $parts['offset'] = $matches[1];
        }

        return $parts;
    }

    private function parseFromClause(string $fromClause): array
    {
        $parts = [];

        if (preg_match('/(\w+)(?:\s+(?:AS\s+)?(\w+))?/i', $fromClause, $matches)) {
            $parts['table'] = trim($matches[1]);
            if (!empty($matches[2])) {
                $parts['alias'] = trim($matches[2]);
            }
        }

        return $parts;
    }

    private function parseJoins(string $sql): array
    {
        $joins = [];

        $joinPattern = '/\b((?:LEFT|RIGHT|INNER|OUTER|CROSS)?\s*JOIN)\s+(\w+)(?:\s+(?:AS\s+)?(\w+))?\s+ON\s+([^)]+?)(?=\s+(?:LEFT|RIGHT|INNER|OUTER|CROSS)?\s*JOIN|\s+WHERE|\s+GROUP\s+BY|\s+HAVING|\s+ORDER\s+BY|\s+LIMIT|\s+OFFSET|$)/i';

        if (preg_match_all($joinPattern, $sql, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $joins[] = [
                    'type' => trim($match[1]),
                    'table' => trim($match[2]),
                    'alias' => !empty($match[3]) ? trim($match[3]) : trim($match[2]),
                    'condition' => trim($match[4])
                ];
            }
        }

        return $joins;
    }

    private function addJoinToQueryBuilder(MethodCall $queryBuilder, array $join): MethodCall
    {
        $joinType = strtoupper(trim($join['type']));
        $table = $join['table'];
        $alias = $join['alias'];
        $condition = $join['condition'];

        switch (true) {
            case strpos($joinType, 'LEFT') !== false:
                $method = 'leftJoin';
                break;
            case strpos($joinType, 'RIGHT') !== false:
                $method = 'rightJoin';
                break;
            case strpos($joinType, 'INNER') !== false:
                $method = 'innerJoin';
                break;
            default:
                $method = 'join';
                break;
        }

        return new MethodCall(
            $queryBuilder,
            new Identifier($method),
            [
                new Arg(new String_($alias)),
                new Arg(new String_($table)),
                new Arg(new String_($alias)),
                new Arg(new String_($condition))
            ]
        );
    }

    private function normalizeWhereClause(string $where): string
    {
        // Convertir parámetros ? a nombrados
        $paramCount = 0;
        $where = preg_replace_callback('/\?/', function($matches) use (&$paramCount) {
            $paramCount++;
            return ":param$paramCount";
        }, $where);

        // Escapar comillas para PHP
        $where = str_replace("'", "\\'", $where);

        return $where;
    }
}