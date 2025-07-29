<?php

declare(strict_types=1);

namespace App\Rector\Doctrine;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar\LNumber;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Return_;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * Convierte el patrón completo PDO (prepare + execute + fetch) a QueryBuilder
 */
final class PdoExecuteToQueryBuilderRector extends AbstractRector
{
    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Convierte el patrón completo PDO a QueryBuilder de Doctrine/DBAL',
            [
                new CodeSample(
                    <<<'CODE_SAMPLE'
$stmt = $this->pdo->prepare("SELECT * FROM users WHERE status = 'active' OR status = 'pending'");
$stmt->execute();
return $stmt->fetchAll();
CODE_SAMPLE
                    ,
                    <<<'CODE_SAMPLE'
return $this->connection->createQueryBuilder()
    ->select('*')
    ->from('users', 'users')
    ->where('(status = \'active\' OR status = \'pending\')')
    ->executeQuery()
    ->fetchAllAssociative();
CODE_SAMPLE
                ),
            ]
        );
    }

    public function getNodeTypes(): array
    {
        return [Return_::class];
    }

    public function refactor(Node $node): ?Node
    {
        if (!$node instanceof Return_) {
            return null;
        }

        // Verificar si es un return $stmt->fetchAll() o similar
        if (!$node->expr instanceof MethodCall) {
            return null;
        }

        $methodCall = $node->expr;
        if (!$this->isFetchMethod($methodCall->name)) {
            return null;
        }

        if (!$methodCall->var instanceof Variable) {
            return null;
        }

        // Buscar hacia atrás el prepare() correspondiente
        $prepareNode = $this->findPrepareStatement($methodCall->var);
        if (!$prepareNode) {
            return null;
        }

        // Extraer el SQL del prepare()
        $sql = $this->extractSqlFromPrepare($prepareNode);
        if (!$sql) {
            return null;
        }

        // Construir el QueryBuilder
        $queryBuilder = $this->buildQueryBuilderFromSql($sql);
        if (!$queryBuilder) {
            return null;
        }

        // Añadir executeQuery() y fetch method
        $executeQuery = new MethodCall($queryBuilder, new Identifier('executeQuery'));
        $fetchMethod = $this->convertFetchMethod($methodCall);
        $finalMethod = new MethodCall($executeQuery, new Identifier($fetchMethod));

        return new Return_($finalMethod);
    }

    private function isFetchMethod($name): bool
    {
        return $this->isName($name, 'fetch') ||
            $this->isName($name, 'fetchAll') ||
            $this->isName($name, 'fetchColumn') ||
            $this->isName($name, 'fetchObject');
    }

    private function findPrepareStatement(Variable $stmtVar): ?MethodCall
    {
        $stmtName = $this->getName($stmtVar);
        if (!$stmtName) {
            return null;
        }

        // Buscar en el método actual hacia atrás
        $currentMethod = $this->betterNodeFinder->findParentType($stmtVar, \PhpParser\Node\Stmt\ClassMethod::class);
        if (!$currentMethod) {
            return null;
        }

        // Buscar asignaciones a la variable $stmt
        $assigns = $this->betterNodeFinder->findInstanceOf($currentMethod, Assign::class);

        foreach ($assigns as $assign) {
            if (!$assign->var instanceof Variable) {
                continue;
            }

            if (!$this->isName($assign->var, $stmtName)) {
                continue;
            }

            if (!$assign->expr instanceof MethodCall) {
                continue;
            }

            if ($this->isName($assign->expr->name, 'prepare')) {
                return $assign->expr;
            }
        }

        return null;
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
        $sql = preg_replace('/\s+/', ' ', $sql); // Normalizar espacios

        // Crear el QueryBuilder base
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

        return null;
    }

    private function buildSelectQuery(MethodCall $queryBuilder, string $sql): MethodCall
    {
        // Parsear la consulta SQL
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
                // Si no hay alias, usar el nombre de la tabla como alias
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

        // WHERE clause - simplificado para que funcione
        if (!empty($parts['where'])) {
            $whereClause = $this->normalizeWhereClause($parts['where']);
            $queryBuilder = new MethodCall(
                $queryBuilder,
                new Identifier('where'),
                [new Arg(new String_($whereClause))]
            );
        }

        // GROUP BY clause
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

        // HAVING clause
        if (!empty($parts['having'])) {
            $queryBuilder = new MethodCall(
                $queryBuilder,
                new Identifier('having'),
                [new Arg(new String_($parts['having']))]
            );
        }

        // ORDER BY clause
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

        // LIMIT clause
        if (!empty($parts['limit'])) {
            $queryBuilder = new MethodCall(
                $queryBuilder,
                new Identifier('setMaxResults'),
                [new Arg(new LNumber((int)$parts['limit']))]
            );
        }

        // OFFSET clause
        if (!empty($parts['offset'])) {
            $queryBuilder = new MethodCall(
                $queryBuilder,
                new Identifier('setFirstResult'),
                [new Arg(new LNumber((int)$parts['offset']))]
            );
        }

        return $queryBuilder;
    }

    private function parseSelectQuery(string $sql): array
    {
        $parts = [];
        $sql = preg_replace('/\s+/', ' ', trim($sql));

        // SELECT - mejorado para capturar hasta FROM
        if (preg_match('/SELECT\s+(.+?)\s+FROM/i', $sql, $matches)) {
            $parts['select'] = trim($matches[1]);
        }

        // FROM con posible alias
        if (preg_match('/FROM\s+(\w+)(?:\s+(?:AS\s+)?(\w+))?(?:\s|$)/i', $sql, $matches)) {
            $parts['from'] = trim($matches[1]);
            if (!empty($matches[2])) {
                $parts['fromAlias'] = trim($matches[2]);
            }
        }

        // JOINs
        $parts['joins'] = $this->parseJoins($sql);

        // WHERE - capturar hasta la siguiente cláusula
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

        // Regex mejorado para JOINs
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

        // Determinar el método según el tipo de JOIN
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

        // Para Doctrine QueryBuilder: fromAlias, joinTable, joinAlias, condition
        return new MethodCall(
            $queryBuilder,
            new Identifier($method),
            [
                new Arg(new String_($alias)), // from alias (será reemplazado por el alias real)
                new Arg(new String_($table)), // join table
                new Arg(new String_($alias)), // join alias
                new Arg(new String_($condition)) // join condition
            ]
        );
    }

    private function normalizeWhereClause(string $where): string
    {
        // Convertir parámetros posicionales ? a nombrados
        $paramCount = 0;
        $where = preg_replace_callback('/\?/', function ($matches) use (&$paramCount) {
            $paramCount++;
            return ":param$paramCount";
        }, $where);

        // Escapar comillas simples en el string para PHP
        $where = str_replace("'", "\\'", $where);

        return $where;
    }
}

// Rector adicional para manejar statements individuales
class PdoStatementToQueryBuilderRector extends AbstractRector
{
    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Convierte statements PDO individuales a QueryBuilder',
            [
                new CodeSample(
                    '$stmt = $this->pdo->prepare("SELECT * FROM users");',
                    '$stmt = $this->connection->createQueryBuilder()->select("*")->from("users");'
                ),
            ]
        );
    }

    public function getNodeTypes(): array
    {
        return [Assign::class];
    }

    public function refactor(Node $node): ?Node
    {
        if (!$node instanceof Assign) {
            return null;
        }

        if (!$node->expr instanceof MethodCall) {
            return null;
        }

        $methodCall = $node->expr;
        if (!$this->isName($methodCall->name, 'prepare')) {
            return null;
        }

        if (!$this->isPdoVariable($methodCall->var)) {
            return null;
        }

        // Extraer SQL
        if (!isset($methodCall->args[0])) {
            return null;
        }

        $sqlArg = $methodCall->args[0]->value;
        if (!$sqlArg instanceof String_) {
            return null;
        }

        $sql = $sqlArg->value;

        // Por ahora solo marcamos para debug - no transformamos assignments individuales
        // porque causarían problemas con execute() y fetch()
        return null;
    }

    private function isPdoVariable($var): bool
    {
        if (!$var instanceof Variable) {
            return false;
        }

        return $this->isName($var, 'pdo') || $this->isName($var, 'db') || $this->isName($var, 'connection');
    }
}