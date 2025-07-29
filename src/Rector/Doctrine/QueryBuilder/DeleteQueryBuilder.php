<?php

declare(strict_types=1);

namespace App\Rector\Doctrine\QueryBuilder;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Scalar\LNumber;
use App\Rector\Doctrine\Parser\WhereClauseParser;

/**
 * Builds Doctrine QueryBuilder for DELETE queries
 */
class DeleteQueryBuilder
{
    private WhereClauseParser $whereParser;

    public function __construct()
    {
        $this->whereParser = new WhereClauseParser();
    }

    public function build(MethodCall $queryBuilder, string $sql): MethodCall
    {
        $parts = $this->parseDeleteQuery($sql);

        // DELETE FROM table
        if (!empty($parts['table'])) {
            $queryBuilder = new MethodCall(
                $queryBuilder,
                new Identifier('delete'),
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

        // Handle multi-table deletes with JOINs
        if (!empty($parts['joins'])) {
            foreach ($parts['joins'] as $join) {
                $queryBuilder = $this->addJoinToQueryBuilder($queryBuilder, $join);
            }
        }

        // WHERE clause
        if (!empty($parts['where'])) {
            $queryBuilder = $this->whereParser->buildWhereClause($queryBuilder, $parts['where']);
        }

        // ORDER BY clause (MySQL supports this in DELETE)
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

        // LIMIT clause (MySQL supports this in DELETE)
        if (!empty($parts['limit'])) {
            $queryBuilder = new MethodCall(
                $queryBuilder,
                new Identifier('setMaxResults'),
                [new Arg(new LNumber((int)$parts['limit']))]
            );
        }

        return $queryBuilder;
    }

    private function parseDeleteQuery(string $sql): array
    {
        $parts = [];
        $sql = preg_replace('/\s+/', ' ', trim($sql));

        // Handle different DELETE patterns:
        // 1. DELETE FROM table
        // 2. DELETE table FROM table JOIN ...
        // 3. DELETE t1, t2 FROM table1 t1 JOIN table2 t2 ...

        // Pattern 1: Standard DELETE FROM table
        if (preg_match('/DELETE\s+FROM\s+(\w+)(?:\s+(?:AS\s+)?(\w+))?/i', $sql, $matches)) {
            $parts['table'] = $matches[1];
            $parts['alias'] = $matches[2] ?? $matches[1];
        }
        // Pattern 2: DELETE table FROM table (MySQL multi-table delete)
        elseif (preg_match('/DELETE\s+(\w+(?:,\s*\w+)*)\s+FROM\s+(\w+)(?:\s+(?:AS\s+)?(\w+))?/i', $sql, $matches)) {
            $parts['deleteTargets'] = array_map('trim', explode(',', $matches[1]));
            $parts['table'] = $matches[2];
            $parts['alias'] = $matches[3] ?? $matches[2];
        }

        // Handle JOINs for multi-table deletes
        $parts['joins'] = $this->parseJoins($sql);

        // WHERE clause
        if (preg_match('/WHERE\s+(.+?)(?:\s+ORDER\s+BY|\s+LIMIT|$)/i', $sql, $matches)) {
            $parts['where'] = trim($matches[1]);
        }

        // ORDER BY clause (MySQL specific for DELETE)
        if (preg_match('/ORDER\s+BY\s+(.+?)(?:\s+LIMIT|$)/i', $sql, $matches)) {
            $parts['orderBy'] = trim($matches[1]);
        }

        // LIMIT clause (MySQL specific for DELETE)
        if (preg_match('/LIMIT\s+(\d+)/i', $sql, $matches)) {
            $parts['limit'] = $matches[1];
        }

        return $parts;
    }

    private function parseJoins(string $sql): array
    {
        $joins = [];

        // Pattern for JOINs in DELETE statements
        $joinPattern = '/\b((?:LEFT|RIGHT|INNER|OUTER)?\s*JOIN)\s+(\w+)(?:\s+(?:AS\s+)?(\w+))?\s+ON\s+([^)]+?)(?=\s+(?:LEFT|RIGHT|INNER|OUTER)?\s*JOIN|\s+WHERE|\s+ORDER\s+BY|\s+LIMIT|$)/i';

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

        // For DELETE queries with JOINs
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