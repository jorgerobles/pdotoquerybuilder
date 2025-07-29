<?php

declare(strict_types=1);

namespace App\Rector\Doctrine;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar\String_;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * Convierte consultas PDO a QueryBuilder de Doctrine/DBAL
 */
final class PdoToQueryBuilderRector extends AbstractRector
{
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
                        ->where('age > :age')
                        ->andWhere('name = :name')
                        ->setParameter('age', 25)
                        ->setParameter('name', 'John')
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

        // Verificar si la variable es $pdo o similar
        return $this->isName($var, 'pdo') || $this->isName($var, 'db') || $this->isName($var, 'connection');
    }

    private function convertPdoPrepareToQueryBuilder(MethodCall $node): ?Node
    {
        if (!isset($node->args[0])) {
            return null;
        }

        $sqlString = $node->args[0]->value;
        if (!$sqlString instanceof String_) {
            return null;
        }

        $sql = $sqlString->value;
        $queryBuilder = $this->buildQueryBuilderFromSql($sql);

        return $queryBuilder;
    }

    private function convertPdoQueryToQueryBuilder(MethodCall $node): ?Node
    {
        if (!isset($node->args[0])) {
            return null;
        }

        $sqlString = $node->args[0]->value;
        if (!$sqlString instanceof String_) {
            return null;
        }

        $sql = $sqlString->value;
        $queryBuilder = $this->buildQueryBuilderFromSql($sql);

        // Para query(), añadir executeQuery() al final
        if ($queryBuilder) {
            return new MethodCall($queryBuilder, new Identifier('executeQuery'));
        }

        return null;
    }

    private function buildQueryBuilderFromSql(string $sql): ?MethodCall
    {
        $sql = trim($sql);
        $sqlUpper = strtoupper($sql);

        // Crear el QueryBuilder base
        $queryBuilder = new MethodCall(
            new MethodCall(
                new Variable('this'),
                new Identifier('connection')
            ),
            new Identifier('createQueryBuilder')
        );

        // Parsear diferentes tipos de consultas
        if (strpos($sqlUpper, 'SELECT') === 0) {
            return $this->buildSelectQuery($queryBuilder, $sql);
        } elseif (strpos($sqlUpper, 'INSERT') === 0) {
            return $this->buildInsertQuery($queryBuilder, $sql);
        } elseif (strpos($sqlUpper, 'UPDATE') === 0) {
            return $this->buildUpdateQuery($queryBuilder, $sql);
        } elseif (strpos($sqlUpper, 'DELETE') === 0) {
            return $this->buildDeleteQuery($queryBuilder, $sql);
        }

        return null;
    }

    private function buildSelectQuery(MethodCall $queryBuilder, string $sql): MethodCall
    {
        // Extraer partes de la consulta SELECT
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
            $queryBuilder = new MethodCall(
                $queryBuilder,
                new Identifier('from'),
                [
                    new Arg(new String_($fromParts['table'])),
                    new Arg(new String_($fromParts['alias'] ?? $fromParts['table']))
                ]
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
            $queryBuilder = $this->buildWhereClause($queryBuilder, $parts['where']);
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
                $orderParts = explode(' ', trim($orderField));
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
                [new Arg($this->nodeFactory->createNumber((int)$parts['limit']))]
            );
        }

        // OFFSET clause
        if (!empty($parts['offset'])) {
            $queryBuilder = new MethodCall(
                $queryBuilder,
                new Identifier('setFirstResult'),
                [new Arg($this->nodeFactory->createNumber((int)$parts['offset']))]
            );
        }

        return $queryBuilder;
    }

    private function buildInsertQuery(MethodCall $queryBuilder, string $sql): MethodCall
    {
        $parts = $this->parseInsertQuery($sql);

        // INSERT INTO
        $queryBuilder = new MethodCall(
            $queryBuilder,
            new Identifier('insert'),
            [new Arg(new String_($parts['table']))]
        );

        // VALUES
        if (!empty($parts['columns']) && !empty($parts['values'])) {
            foreach ($parts['columns'] as $index => $column) {
                $queryBuilder = new MethodCall(
                    $queryBuilder,
                    new Identifier('setValue'),
                    [
                        new Arg(new String_($column)),
                        new Arg(new String_('?'))
                    ]
                );
            }
        }

        return $queryBuilder;
    }

    private function buildUpdateQuery(MethodCall $queryBuilder, string $sql): MethodCall
    {
        $parts = $this->parseUpdateQuery($sql);

        // UPDATE table
        $queryBuilder = new MethodCall(
            $queryBuilder,
            new Identifier('update'),
            [new Arg(new String_($parts['table']))]
        );

        // SET clause
        if (!empty($parts['set'])) {
            $setPairs = explode(',', $parts['set']);
            foreach ($setPairs as $pair) {
                $pair = trim($pair);
                if (strpos($pair, '=') !== false) {
                    list($column, $value) = explode('=', $pair, 2);
                    $queryBuilder = new MethodCall(
                        $queryBuilder,
                        new Identifier('set'),
                        [
                            new Arg(new String_(trim($column))),
                            new Arg(new String_(trim($value)))
                        ]
                    );
                }
            }
        }

        // WHERE clause
        if (!empty($parts['where'])) {
            $queryBuilder = new MethodCall(
                $queryBuilder,
                new Identifier('where'),
                [new Arg(new String_($parts['where']))]
            );
        }

        return $queryBuilder;
    }

    private function buildDeleteQuery(MethodCall $queryBuilder, string $sql): MethodCall
    {
        $parts = $this->parseDeleteQuery($sql);

        // DELETE FROM
        $queryBuilder = new MethodCall(
            $queryBuilder,
            new Identifier('delete'),
            [new Arg(new String_($parts['table']))]
        );

        // WHERE clause
        if (!empty($parts['where'])) {
            $queryBuilder = new MethodCall(
                $queryBuilder,
                new Identifier('where'),
                [new Arg(new String_($parts['where']))]
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

        // FROM with possible alias
        if (preg_match('/FROM\s+(\w+)(?:\s+(?:AS\s+)?(\w+))?/i', $sql, $matches)) {
            $parts['from'] = trim($matches[1]);
            if (!empty($matches[2])) {
                $parts['fromAlias'] = trim($matches[2]);
            }
        }

        // JOINs - Capturar todos los tipos de JOIN
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

        // Regex para capturar diferentes tipos de JOIN
        $joinPattern = '/\b((?:LEFT|RIGHT|INNER|OUTER|CROSS)?\s*JOIN)\s+(\w+)(?:\s+(?:AS\s+)?(\w+))?\s+ON\s+([^)]+?)(?=\s+(?:LEFT|RIGHT|INNER|OUTER|CROSS)?\s*JOIN|\s+WHERE|\s+GROUP\s+BY|\s+HAVING|\s+ORDER\s+BY|\s+LIMIT|\s+OFFSET|$)/i';

        if (preg_match_all($joinPattern, $sql, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $joins[] = [
                    'type' => trim($match[1]),
                    'table' => trim($match[2]),
                    'alias' => !empty($match[3]) ? trim($match[3]) : null,
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
        $alias = $join['alias'] ?? $table;
        $condition = $join['condition'];

        // Determinar el método del QueryBuilder según el tipo de JOIN
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
            case strpos($joinType, 'CROSS') !== false:
                $method = 'join'; // Doctrine no tiene crossJoin específico
                break;
            default:
                $method = 'join'; // JOIN por defecto
                break;
        }

        return new MethodCall(
            $queryBuilder,
            new Identifier($method),
            [
                new Arg(new String_($alias)), // from alias
                new Arg(new String_($table)), // join table
                new Arg(new String_($alias)), // join alias
                new Arg(new String_($condition)) // join condition
            ]
        );
    }

    private function parseInsertQuery(string $sql): array
    {
        $parts = [];

        // INSERT INTO table
        if (preg_match('/INSERT\s+INTO\s+(\w+)/i', $sql, $matches)) {
            $parts['table'] = $matches[1];
        }

        // Columns
        if (preg_match('/\(([^)]+)\)\s+VALUES/i', $sql, $matches)) {
            $parts['columns'] = array_map('trim', explode(',', $matches[1]));
        }

        // Values
        if (preg_match('/VALUES\s+\(([^)]+)\)/i', $sql, $matches)) {
            $parts['values'] = array_map('trim', explode(',', $matches[1]));
        }

        return $parts;
    }

    private function parseUpdateQuery(string $sql): array
    {
        $parts = [];

        // UPDATE table
        if (preg_match('/UPDATE\s+(\w+)/i', $sql, $matches)) {
            $parts['table'] = $matches[1];
        }

        // SET clause
        if (preg_match('/SET\s+(.+?)(?:\s+WHERE|$)/i', $sql, $matches)) {
            $parts['set'] = trim($matches[1]);
        }

        // WHERE clause
        if (preg_match('/WHERE\s+(.+)$/i', $sql, $matches)) {
            $parts['where'] = trim($matches[1]);
        }

        return $parts;
    }

    private function parseDeleteQuery(string $sql): array
    {
        $parts = [];

        // DELETE FROM table
        if (preg_match('/DELETE\s+FROM\s+(\w+)/i', $sql, $matches)) {
            $parts['table'] = $matches[1];
        }

        // WHERE clause
        if (preg_match('/WHERE\s+(.+)$/i', $sql, $matches)) {
            $parts['where'] = trim($matches[1]);
        }

        return $parts;
    }

    private function parseWhereClause(string $where): array
    {
        // Dividir por AND/OR (simplificado)
        $conditions = preg_split('/\s+AND\s+/i', $where);

        // Convertir parámetros posicionales (?) a nombrados
        $paramCount = 0;
        foreach ($conditions as &$condition) {
            $condition = preg_replace_callback('/\?/', function ($matches) use (&$paramCount) {
                $paramCount++;
                return ":param$paramCount";
            }, $condition);
        }

        return $conditions;
    }

    private function buildWhereClause(MethodCall $queryBuilder, string $whereClause): MethodCall
    {
        $whereExpression = $this->parseComplexWhereClause($whereClause);
        return $this->buildWhereFromExpression($queryBuilder, $whereExpression);
    }

    private function parseComplexWhereClause(string $where): array
    {
        // Convertir parámetros posicionales a nombrados primero
        $paramCount = 0;
        $where = preg_replace_callback('/\?/', function ($matches) use (&$paramCount) {
            $paramCount++;
            return ":param$paramCount";
        }, $where);

        return $this->parseLogicalExpression(trim($where));
    }

    private function parseLogicalExpression(string $expression): array
    {
        // Tokenizar la expresión respetando paréntesis
        $tokens = $this->tokenizeExpression($expression);
        return $this->parseTokensToTree($tokens);
    }

    private function tokenizeExpression(string $expression): array
    {
        $tokens = [];
        $current = '';
        $depth = 0;
        $inQuotes = false;
        $quoteChar = '';

        for ($i = 0; $i < strlen($expression); $i++) {
            $char = $expression[$i];

            // Manejo de comillas
            if (($char === '"' || $char === "'") && !$inQuotes) {
                $inQuotes = true;
                $quoteChar = $char;
                $current .= $char;
                continue;
            } elseif ($char === $quoteChar && $inQuotes) {
                $inQuotes = false;
                $quoteChar = '';
                $current .= $char;
                continue;
            }

            if ($inQuotes) {
                $current .= $char;
                continue;
            }

            // Manejo de paréntesis
            if ($char === '(') {
                if ($depth === 0 && trim($current) !== '') {
                    $tokens[] = ['type' => 'condition', 'value' => trim($current)];
                    $current = '';
                }
                $depth++;
                $current .= $char;
            } elseif ($char === ')') {
                $depth--;
                $current .= $char;

                if ($depth === 0) {
                    // Extraer contenido entre paréntesis
                    $parenthesisContent = substr($current, 1, -1); // Quitar ( y )
                    $tokens[] = [
                        'type' => 'group',
                        'value' => $this->parseLogicalExpression($parenthesisContent)
                    ];
                    $current = '';
                }
            } elseif ($depth > 0) {
                $current .= $char;
            } else {
                // Buscar operadores AND/OR/NOT
                $remaining = substr($expression, $i);

                if (preg_match('/^\s*(AND|OR|NOT)\s+/i', $remaining, $matches)) {
                    if (trim($current) !== '') {
                        $tokens[] = ['type' => 'condition', 'value' => trim($current)];
                        $current = '';
                    }

                    $operator = strtoupper(trim($matches[1]));
                    $tokens[] = ['type' => 'operator', 'value' => $operator];

                    $i += strlen($matches[0]) - 1; // Ajustar índice
                } else {
                    $current .= $char;
                }
            }
        }

        if (trim($current) !== '') {
            $tokens[] = ['type' => 'condition', 'value' => trim($current)];
        }

        return $tokens;
    }

    private function parseTokensToTree(array $tokens): array
    {
        if (empty($tokens)) {
            return [];
        }

        // Estructurar en árbol considerando precedencia de operadores
        $result = [];
        $currentCondition = null;
        $currentOperator = null;

        foreach ($tokens as $token) {
            switch ($token['type']) {
                case 'condition':
                case 'group':
                    if ($currentCondition === null) {
                        $currentCondition = $token;
                    } else {
                        // Combinar con el operador actual
                        $result[] = [
                            'type' => 'expression',
                            'left' => $currentCondition,
                            'operator' => $currentOperator ?? 'AND',
                            'right' => $token
                        ];
                        $currentCondition = null;
                        $currentOperator = null;
                    }
                    break;

                case 'operator':
                    if ($currentCondition !== null) {
                        $currentOperator = $token['value'];
                    }
                    break;
            }
        }

        // Si queda una condición sin procesar
        if ($currentCondition !== null) {
            $result[] = $currentCondition;
        }

        return count($result) === 1 ? $result[0] : [
            'type' => 'multiple',
            'expressions' => $result
        ];
    }

    private function buildWhereFromExpression(MethodCall $queryBuilder, array $expression): MethodCall
    {
        return $this->addWhereExpression($queryBuilder, $expression, true);
    }

    private function addWhereExpression(MethodCall $queryBuilder, array $expression, bool $isFirst = false): MethodCall
    {
        switch ($expression['type']) {
            case 'condition':
                $method = $isFirst ? 'where' : 'andWhere';
                return new MethodCall(
                    $queryBuilder,
                    new Identifier($method),
                    [new Arg(new String_($expression['value']))]
                );

            case 'group':
                // Procesar grupo de condiciones
                return $this->addWhereExpression($queryBuilder, $expression['value'], $isFirst);

            case 'expression':
                $left = $expression['left'];
                $operator = $expression['operator'];
                $right = $expression['right'];

                // Procesar lado izquierdo
                $queryBuilder = $this->addWhereExpression($queryBuilder, $left, $isFirst);

                // Procesar lado derecho según el operador
                switch ($operator) {
                    case 'AND':
                        $queryBuilder = $this->addWhereExpression($queryBuilder, $right, false);
                        break;

                    case 'OR':
                        $queryBuilder = $this->addOrWhereExpression($queryBuilder, $right);
                        break;

                    case 'NOT':
                        $queryBuilder = $this->addNotWhereExpression($queryBuilder, $right);
                        break;
                }

                return $queryBuilder;

            case 'multiple':
                foreach ($expression['expressions'] as $i => $expr) {
                    $queryBuilder = $this->addWhereExpression($queryBuilder, $expr, $i === 0 && $isFirst);
                }
                return $queryBuilder;

            default:
                return $queryBuilder;
        }
    }

    private function addOrWhereExpression(MethodCall $queryBuilder, array $expression): MethodCall
    {
        $conditionString = $this->expressionToString($expression);

        return new MethodCall(
            $queryBuilder,
            new Identifier('orWhere'),
            [new Arg(new String_($conditionString))]
        );
    }

    private function addNotWhereExpression(MethodCall $queryBuilder, array $expression): MethodCall
    {
        $conditionString = $this->expressionToString($expression);
        $notCondition = "NOT ($conditionString)";

        return new MethodCall(
            $queryBuilder,
            new Identifier('andWhere'),
            [new Arg(new String_($notCondition))]
        );
    }

    private function expressionToString(array $expression): string
    {
        switch ($expression['type']) {
            case 'condition':
                return $expression['value'];

            case 'group':
                return '(' . $this->expressionToString($expression['value']) . ')';

            case 'expression':
                $left = $this->expressionToString($expression['left']);
                $operator = $expression['operator'];
                $right = $this->expressionToString($expression['right']);

                return "$left $operator $right";

            case 'multiple':
                $parts = [];
                foreach ($expression['expressions'] as $expr) {
                    $parts[] = $this->expressionToString($expr);
                }
                return implode(' AND ', $parts);

            default:
                return '';
        }
    }
}

// Archivo de configuración para Rector
// rector.php


// Regla adicional para manejar la ejecución y fetch
