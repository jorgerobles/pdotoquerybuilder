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
 * Builds Doctrine QueryBuilder for SELECT queries
 */
class SelectQueryBuilder
{
    private WhereClauseParser $whereParser;

    public function __construct()
    {
        $this->whereParser = new WhereClauseParser();
    }

    public function build(MethodCall $queryBuilder, string $sql): MethodCall
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

        // WHERE clause
        if (!empty($parts['where'])) {
            $queryBuilder = $this->whereParser->buildWhereClause($queryBuilder, $parts['where']);
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

        // SELECT
        if (preg_match('/SELECT\s+(.+?)\s+FROM/i', $sql, $matches)) {
            $parts['select'] = trim($matches[1]);
        }

        // FROM with alias detection
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
}