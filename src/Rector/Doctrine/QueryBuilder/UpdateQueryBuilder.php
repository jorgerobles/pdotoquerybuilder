<?php

declare(strict_types=1);

namespace App\Rector\Doctrine\QueryBuilder;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar\String_;
use App\Rector\Doctrine\Parser\WhereClauseParser;

/**
 * Builds Doctrine QueryBuilder for UPDATE queries
 */
class UpdateQueryBuilder
{
    private WhereClauseParser $whereParser;

    public function __construct()
    {
        $this->whereParser = new WhereClauseParser();
    }

    public function build(MethodCall $queryBuilder, string $sql): MethodCall
    {
        $parts = $this->parseUpdateQuery($sql);

        // UPDATE table
        if (!empty($parts['table'])) {
            $queryBuilder = new MethodCall(
                $queryBuilder,
                new Identifier('update'),
                [new Arg(new String_($parts['table']))]
            );

            // Add table alias if present
            if (!empty($parts['alias']) && $parts['alias'] !== $parts['table']) {
                $queryBuilder = new MethodCall(
                    $queryBuilder,
                    new Identifier('from'),
                    [
                        new Arg(new String_($parts['table'])),
                        new Arg(new String_($parts['alias']))
                    ]
                );
            }
        }

        // SET clause
        if (!empty($parts['setClause'])) {
            $queryBuilder = $this->buildSetClause($queryBuilder, $parts['setClause']);
        }

        // JOINs (for multi-table updates)
        if (!empty($parts['joins'])) {
            foreach ($parts['joins'] as $join) {
                $queryBuilder = $this->addJoinToQueryBuilder($queryBuilder, $join);
            }
        }

        // WHERE clause
        if (!empty($parts['where'])) {
            $queryBuilder = $this->whereParser->buildWhereClause($queryBuilder, $parts['where']);
        }

        // ORDER BY clause (MySQL supports this in UPDATE)
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

        // LIMIT clause (MySQL supports this in UPDATE)
        if (!empty($parts['limit'])) {
            $queryBuilder = new MethodCall(
                $queryBuilder,
                new Identifier('setMaxResults'),
                [new Arg(new \PhpParser\Node\Scalar\LNumber((int)$parts['limit']))]
            );
        }

        return $queryBuilder;
    }

    private function parseUpdateQuery(string $sql): array
    {
        $parts = [];
        $sql = preg_replace('/\s+/', ' ', trim($sql));

        // UPDATE table [alias] - Handle both single and multi-table updates
        if (preg_match('/UPDATE\s+(\w+)(?:\s+(?:AS\s+)?(\w+))?/i', $sql, $matches)) {
            $parts['table'] = $matches[1];
            $parts['alias'] = $matches[2] ?? $matches[1];
        }

        // Handle multi-table updates with JOINs
        $parts['joins'] = $this->parseJoins($sql);

        // SET clause - more robust parsing
        if (preg_match('/SET\s+(.+?)(?:\s+WHERE|\s+ORDER\s+BY|\s+LIMIT|$)/i', $sql, $matches)) {
            $parts['setClause'] = trim($matches[1]);
        }

        // WHERE clause
        if (preg_match('/WHERE\s+(.+?)(?:\s+ORDER\s+BY|\s+LIMIT|$)/i', $sql, $matches)) {
            $parts['where'] = trim($matches[1]);
        }

        // ORDER BY clause (MySQL specific for UPDATE)
        if (preg_match('/ORDER\s+BY\s+(.+?)(?:\s+LIMIT|$)/i', $sql, $matches)) {
            $parts['orderBy'] = trim($matches[1]);
        }

        // LIMIT clause (MySQL specific for UPDATE)
        if (preg_match('/LIMIT\s+(\d+)/i', $sql, $matches)) {
            $parts['limit'] = $matches[1];
        }

        return $parts;
    }

    private function parseJoins(string $sql): array
    {
        $joins = [];

        // Pattern for JOINs in UPDATE statements
        $joinPattern = '/\b((?:LEFT|RIGHT|INNER|OUTER)?\s*JOIN)\s+(\w+)(?:\s+(?:AS\s+)?(\w+))?\s+ON\s+([^)]+?)(?=\s+(?:LEFT|RIGHT|INNER|OUTER)?\s*JOIN|\s+SET|\s+WHERE|\s+ORDER\s+BY|\s+LIMIT|$)/i';

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

    private function buildSetClause(MethodCall $queryBuilder, string $setClause): MethodCall
    {
        $setPairs = $this->parseSetPairs($setClause);

        foreach ($setPairs as $pair) {
            if (isset($pair['column']) && isset($pair['value'])) {
                $queryBuilder = new MethodCall(
                    $queryBuilder,
                    new Identifier('set'),
                    [
                        new Arg(new String_($pair['column'])),
                        new Arg(new String_($pair['value']))
                    ]
                );
            }
        }

        return $queryBuilder;
    }

    private function parseSetPairs(string $setClause): array
    {
        $pairs = [];
        $current = '';
        $inQuotes = false;
        $quoteChar = '';
        $depth = 0;

        for ($i = 0; $i < strlen($setClause); $i++) {
            $char = $setClause[$i];

            // Handle quotes
            if (($char === '"' || $char === "'" || $char === '`') && !$inQuotes) {
                $inQuotes = true;
                $quoteChar = $char;
                $current .= $char;
            } elseif ($char === $quoteChar && $inQuotes) {
                $inQuotes = false;
                $quoteChar = '';
                $current .= $char;
            } elseif (!$inQuotes) {
                // Handle function calls and subqueries
                if ($char === '(') {
                    $depth++;
                    $current .= $char;
                } elseif ($char === ')') {
                    $depth--;
                    $current .= $char;
                } elseif ($char === ',' && $depth === 0) {
                    // Split on comma only at top level
                    $pairs[] = $this->parseSetPair(trim($current));
                    $current = '';
                } else {
                    $current .= $char;
                }
            } else {
                $current .= $char;
            }
        }

        if (trim($current) !== '') {
            $pairs[] = $this->parseSetPair(trim($current));
        }

        return array_filter($pairs); // Remove null entries
    }

    private function parseSetPair(string $pair): ?array
    {
        // Handle different assignment patterns
        // column = value
        // table.column = value
        // column = function(args)
        // column = (SELECT ...)
        // column = CASE WHEN ... END

        if (strpos($pair, '=') === false) {
            return null;
        }

        // Find the first = that's not inside parentheses or quotes
        $equalPos = $this->findTopLevelEqual($pair);
        if ($equalPos === false) {
            return null;
        }

        $column = trim(substr($pair, 0, $equalPos));
        $value = trim(substr($pair, $equalPos + 1));

        // Clean column name (remove table prefix if present, quotes, etc.)
        if (strpos($column, '.') !== false) {
            $columnParts = explode('.', $column);
            $column = end($columnParts);
        }
        $column = trim($column, " \t\n\r\0\x0B`\"'");

        // Convert positional parameters to named parameters
        $value = $this->normalizeValue($value);

        return [
            'column' => $column,
            'value' => $value
        ];
    }

    private function findTopLevelEqual(string $str): int|false
    {
        $depth = 0;
        $inQuotes = false;
        $quoteChar = '';

        for ($i = 0; $i < strlen($str); $i++) {
            $char = $str[$i];

            if (($char === '"' || $char === "'" || $char === '`') && !$inQuotes) {
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
                } elseif ($char === '=' && $depth === 0) {
                    return $i;
                }
            }
        }

        return false;
    }

    private function normalizeValue(string $value): string
    {
        $value = trim($value);

        // Convert positional parameters to named parameters
        if ($value === '?') {
            static $paramCount = 0;
            $paramCount++;
            return ":param$paramCount";
        }

        // Handle common SQL functions and expressions
        $value = $this->convertPositionalParams($value);

        return $value;
    }

    private function convertPositionalParams(string $value): string
    {
        static $globalParamCount = 0;

        return preg_replace_callback('/\?/', function($matches) use (&$globalParamCount) {
            $globalParamCount++;
            return ":param$globalParamCount";
        }, $value);
    }

    private function addJoinToQueryBuilder(MethodCall $queryBuilder, array $join): MethodCall
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

        // For UPDATE queries with JOINs, use a simplified approach
        return new MethodCall(
            $queryBuilder,
            new Identifier($method),
            [
                new Arg(new String_('main')), // from alias
                new Arg(new String_($table)), // join table
                new Arg(new String_($alias)), // join alias
                new Arg(new String_($condition)) // join condition
            ]
        );
    }
}