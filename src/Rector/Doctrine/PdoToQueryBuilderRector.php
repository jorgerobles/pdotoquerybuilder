<?php

declare(strict_types=1);

namespace App\Rector\Doctrine;

use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Scalar\LNumber;
use PhpParser\Node\Scalar\Encapsed;
use PhpParser\Node\Scalar\EncapsedStringPart;
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
                        ->where('age > :param1')
                        ->andWhere('name = :param2')
                        ->executeQuery()
                        ->fetchAllAssociative();
                    CODE_SAMPLE
                ),
                new CodeSample(
                    <<<'CODE_SAMPLE'
                    $stmt = $pdo->prepare(<<<SQL
                        SELECT p.*, c.name as category_name
                        FROM products p
                        INNER JOIN categories c ON p.category_id = c.id
                        WHERE p.active = ? AND c.featured = ?
                        ORDER BY p.name
                        SQL);
                    $stmt->execute([1, 1]);
                    $products = $stmt->fetchAll();
                    CODE_SAMPLE
                    ,
                    <<<'CODE_SAMPLE'
                    $products = $this->connection->createQueryBuilder()
                        ->select('p.*, c.name as category_name')
                        ->from('products', 'p')
                        ->innerJoin('p', 'categories', 'c', 'p.category_id = c.id')
                        ->where('p.active = :param1')
                        ->andWhere('c.featured = :param2')
                        ->addOrderBy('p.name', 'ASC')
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
        if ($this->isName($var, 'pdo')) {
            return true;
        }
        if ($this->isName($var, 'db')) {
            return true;
        }
        return $this->isName($var, 'connection');
    }

    private function convertPdoPrepareToQueryBuilder(MethodCall $node): ?Node
    {
        if (!isset($node->args[0])) {
            return null;
        }

        $sqlNode = $node->args[0]->value;
        $sql = $this->extractSqlFromNode($sqlNode);

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

        $sqlNode = $node->args[0]->value;
        $sql = $this->extractSqlFromNode($sqlNode);

        if ($sql === null) {
            return null;
        }

        $queryBuilder = $this->buildQueryBuilderFromSql($sql);

        // Para query(), añadir executeQuery() al final
        if ($queryBuilder instanceof MethodCall) {
            return new MethodCall($queryBuilder, new Identifier('executeQuery'));
        }

        return null;
    }

    /**
     * Extract SQL string from different node types (String_, Encapsed, etc.)
     */
    private function extractSqlFromNode(Node $node): ?string
    {
        // Handle regular string literals and heredocs/nowdocs
        if ($node instanceof String_) {
            return $node->value;
        }

        // Handle interpolated strings (heredocs with variables)
        if ($node instanceof Encapsed) {
            // For now, we'll try to extract the SQL by concatenating string parts
            // This is a simplified approach - in a real scenario you might want more sophisticated handling
            $sql = '';
            foreach ($node->parts as $part) {
                if ($part instanceof EncapsedStringPart) {
                    $sql .= $part->value;
                } elseif ($part instanceof Variable) {
                    // Replace variables with placeholders - this is a basic approach
                    $sql .= '?';
                } else {
                    // For other expressions, we can't easily convert - return null
                    error_log("PdoToQueryBuilderRector: Unsupported encapsed part type: " . get_class($part));
                    return null;
                }
            }
            return $sql;
        }

        // Handle string concatenation (basic support)
        if ($node instanceof Concat) {
            $left = $this->extractSqlFromNode($node->left);
            $right = $this->extractSqlFromNode($node->right);

            if ($left !== null && $right !== null) {
                return $left . $right;
            }
        }

        // Log unsupported node types for debugging
        error_log("PdoToQueryBuilderRector: Unsupported SQL node type: " . get_class($node));
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
        }
        if (strpos($sqlUpper, 'INSERT') === 0) {
            return $this->buildInsertQuery($queryBuilder);
        }
        if (strpos($sqlUpper, 'UPDATE') === 0) {
            return $this->buildUpdateQuery($queryBuilder);
        }

        // Parsear diferentes tipos de consultas
        if (strpos($sqlUpper, 'DELETE') === 0) {
            return $this->buildDeleteQuery($queryBuilder);
        }

        return null;
    }

    private function buildSelectQuery(MethodCall $queryBuilder, string $sql): MethodCall
    {
        // Parse the SQL query parts
        $parts = $this->parseSelectQuery($sql);

        // SELECT clause
        $selectClause = $parts['select'] ?? '*';
        $queryBuilder = new MethodCall(
            $queryBuilder,
            new Identifier('select'),
            [new Arg(new String_($selectClause))]
        );

        // FROM clause - Correctly use the detected alias
        $mainTableAlias = null;
        if (!empty($parts['from'])) {
            $tableName = $parts['from'];
            $mainTableAlias = $parts['fromAlias'] ?? $tableName;

            $queryBuilder = new MethodCall(
                $queryBuilder,
                new Identifier('from'),
                [
                    new Arg(new String_($tableName)),
                    new Arg(new String_($mainTableAlias))
                ]
            );
        }

        // JOIN clauses - Use the main table alias as first argument
        if (!empty($parts['joins'])) {
            foreach ($parts['joins'] as $join) {
                $queryBuilder = $this->addJoinToQueryBuilder($queryBuilder, $join, $mainTableAlias);
            }
        }

        // WHERE clause - Split top-level AND/OR conditions
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
                [new Arg(new LNumber((int)$parts['limit']))]
            );
        }

        // OFFSET clause
        if (!empty($parts['offset'])) {
            return new MethodCall(
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

        // SELECT
        if (preg_match('/SELECT\s+(.+?)\s+FROM/i', $sql, $matches)) {
            $parts['select'] = trim($matches[1]);
        }

        // FROM with alias detection - FIXED VERSION
        if (preg_match('/FROM\s+(\w+)(?:\s+(?:AS\s+)?(\w+))?/i', $sql, $matches)) {
            $parts['from'] = trim($matches[1]); // table name
            $parts['fromAlias'] = empty($matches[2]) ? trim($matches[1]) : trim($matches[2]); // alias or table name
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

    private function parseJoins(string $sql): array
    {
        $joins = [];
        $joinPattern = '/\b((?:LEFT|RIGHT|INNER|OUTER|CROSS)?\s*JOIN)\s+(\w+)(?:\s+(?:AS\s+)?(\w+))?\s+ON\s+([^)]+?)(?=\s+(?:LEFT|RIGHT|INNER|OUTER|CROSS)?\s*JOIN|\s+WHERE|\s+GROUP\s+BY|\s+HAVING|\s+ORDER\s+BY|\s+LIMIT|\s+OFFSET|$)/i';

        if (preg_match_all($joinPattern, $sql, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $joins[] = [
                    'type' => trim($match[1]),
                    'table' => trim($match[2]),
                    'alias' => empty($match[3]) ? null : trim($match[3]),
                    'condition' => trim($match[4])
                ];
            }
        }

        return $joins;
    }

    private function addJoinToQueryBuilder(MethodCall $queryBuilder, array $join, string $mainTableAlias = null): MethodCall
    {
        $joinType = strtoupper(trim($join['type']));
        $table = $join['table'];
        $alias = $join['alias'] ?? $table;
        $condition = $join['condition'];

        // Determine the QueryBuilder method based on JOIN type
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

        // FIXED: Use the main table alias as the first argument
        // Syntax: join(fromAlias, joinTable, joinAlias, condition)
        return new MethodCall(
            $queryBuilder,
            new Identifier($method),
            [
                new Arg(new String_($mainTableAlias ?? 'main')), // from alias (the main table)
                new Arg(new String_($table)), // join table
                new Arg(new String_($alias)), // join alias
                new Arg(new String_($condition)) // join condition
            ]
        );
    }

    private function buildWhereClause(MethodCall $queryBuilder, string $whereClause): MethodCall
    {
        // Normalize the WHERE clause first
        $whereClause = $this->normalizeWhereClause($whereClause);

        // Split top-level AND/OR conditions (not inside parentheses)
        $conditions = $this->splitTopLevelWhereConditions($whereClause);

        // Apply the first condition with where()
        if ($conditions !== []) {
            $firstCondition = array_shift($conditions);
            $queryBuilder = new MethodCall(
                $queryBuilder,
                new Identifier('where'),
                [new Arg(new String_($firstCondition['condition']))]
            );

            // Apply remaining conditions with andWhere() or orWhere()
            foreach ($conditions as $condition) {
                $method = $condition['operator'] === 'OR' ? 'orWhere' : 'andWhere';
                $queryBuilder = new MethodCall(
                    $queryBuilder,
                    new Identifier($method),
                    [new Arg(new String_($condition['condition']))]
                );
            }
        }

        return $queryBuilder;
    }

    private function splitTopLevelWhereConditions(string $where): array
    {
        // If the entire WHERE clause is wrapped in parentheses, treat it as one condition
        $trimmed = trim($where);
        if ($this->isWrappedInParentheses($trimmed)) {
            return [['condition' => $trimmed, 'operator' => null]];
        }

        // Split by top-level AND/OR operators (not inside parentheses)
        $conditions = [];
        $current = '';
        $depth = 0;
        $inQuotes = false;
        $quoteChar = '';
        $i = 0;

        while ($i < strlen($where)) {
            $char = $where[$i];
            // Handle quotes
            if (($char === '"' || $char === "'") && !$inQuotes) {
                $inQuotes = true;
                $quoteChar = $char;
                $current .= $char;
                $i++;
                continue;
            }

            // Handle quotes
            if ($char === $quoteChar && $inQuotes) {
                $inQuotes = false;
                $quoteChar = '';
                $current .= $char;
                $i++;
                continue;
            }

            if ($inQuotes) {
                $current .= $char;
                $i++;
                continue;
            }
            // Handle parentheses depth
            if ($char === '(') {
                $depth++;
                $current .= $char;
                $i++;
                continue;
            }

            // Handle parentheses depth
            if ($char === ')') {
                $depth--;
                $current .= $char;
                $i++;
                continue;
            }

            // Only split on top-level AND/OR (depth = 0)
            if ($depth === 0) {
                $remaining = substr($where, $i);

                // Check for AND operator
                if (preg_match('/^\s*AND\s+/i', $remaining, $matches)) {
                    if (trim($current) !== '') {
                        $conditions[] = ['condition' => trim($current), 'operator' => null];
                        $current = '';
                    }
                    $i += strlen($matches[0]);
                    // Next condition will be added with AND operator
                    $nextCondition = $this->extractNextCondition(substr($where, $i));
                    if ($nextCondition) {
                        $conditions[] = ['condition' => trim($nextCondition['condition']), 'operator' => 'AND'];
                        $i += strlen($nextCondition['condition']);
                        $current = '';
                        continue;
                    }
                }
                // Check for OR operator
                elseif (preg_match('/^\s*OR\s+/i', $remaining, $matches)) {
                    if (trim($current) !== '') {
                        $conditions[] = ['condition' => trim($current), 'operator' => null];
                        $current = '';
                    }
                    $i += strlen($matches[0]);
                    // Next condition will be added with OR operator
                    $nextCondition = $this->extractNextCondition(substr($where, $i));
                    if ($nextCondition) {
                        $conditions[] = ['condition' => trim($nextCondition['condition']), 'operator' => 'OR'];
                        $i += strlen($nextCondition['condition']);
                        $current = '';
                        continue;
                    }
                }
            }

            $current .= $char;
            $i++;
        }

        // Add the final condition
        if (trim($current) !== '') {
            $conditions[] = ['condition' => trim($current), 'operator' => null];
        }

        // If no splitting occurred, return the original as one condition
        if (count($conditions) <= 1) {
            return [['condition' => $where, 'operator' => null]];
        }

        return $conditions;
    }

    private function extractNextCondition(string $remaining): ?array
    {
        $condition = '';
        $depth = 0;
        $inQuotes = false;
        $quoteChar = '';
        $i = 0;

        while ($i < strlen($remaining)) {
            $char = $remaining[$i];
            // Handle quotes
            if (($char === '"' || $char === "'") && !$inQuotes) {
                $inQuotes = true;
                $quoteChar = $char;
                $condition .= $char;
                $i++;
                continue;
            }

            // Handle quotes
            if ($char === $quoteChar && $inQuotes) {
                $inQuotes = false;
                $quoteChar = '';
                $condition .= $char;
                $i++;
                continue;
            }

            if ($inQuotes) {
                $condition .= $char;
                $i++;
                continue;
            }

            // Handle parentheses depth
            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
            }

            // Stop at top-level AND/OR
            if ($depth === 0) {
                $next = substr($remaining, $i);
                if (preg_match('/^\s*(AND|OR)\s+/i', $next)) {
                    break;
                }
            }

            $condition .= $char;
            $i++;
        }

        return trim($condition) !== '' ? ['condition' => $condition] : null;
    }

    private function isWrappedInParentheses(string $str): bool
    {
        $str = trim($str);
        if (strlen($str) < 2 || $str[0] !== '(' || $str[strlen($str) - 1] !== ')') {
            return false;
        }

        // Check if the parentheses are balanced and the entire string is wrapped
        $depth = 0;
        $inQuotes = false;
        $quoteChar = '';

        for ($i = 0; $i < strlen($str); $i++) {
            $char = $str[$i];

            if (($char === '"' || $char === "'") && !$inQuotes) {
                $inQuotes = true;
                $quoteChar = $char;
            } elseif ($char === $quoteChar && $inQuotes) {
                $inQuotes = false;
                $quoteChar = '';
            } elseif (!$inQuotes) {
                if ($char === '(') {
                    $depth++;
                } elseif ($char === ')') {
                    $depth--;
                    // If we reach depth 0 before the end, it's not fully wrapped
                    if ($depth === 0 && $i < strlen($str) - 1) {
                        return false;
                    }
                }
            }
        }

        return $depth === 0;
    }

    private function normalizeWhereClause(string $where): string
    {
        // Convert positional parameters to named ones
        $paramCount = 0;
        $where = preg_replace_callback('/\?/', function ($matches) use (&$paramCount): string {
            $paramCount++;
            return ":param$paramCount";
        }, $where);

        // Clean up and normalize
        $where = preg_replace('/\s+/', ' ', trim($where));
        $where = str_replace(' AND NULL', '', $where);

        return str_replace('IS AND', 'IS NOT', $where);
    }

    // Simplified implementations for other query types
    private function buildInsertQuery(MethodCall $queryBuilder): MethodCall
    {
        // Basic INSERT implementation
        return $queryBuilder;
    }

    private function buildUpdateQuery(MethodCall $queryBuilder): MethodCall
    {
        // Basic UPDATE implementation
        return $queryBuilder;
    }

    private function buildDeleteQuery(MethodCall $queryBuilder): MethodCall
    {
        // Basic DELETE implementation
        return $queryBuilder;
    }
}