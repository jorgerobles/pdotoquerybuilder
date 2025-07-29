<?php

declare(strict_types=1);

namespace JDR\Rector\PdoToQb\QueryBuilder;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar\LNumber;
use PhpParser\Node\Scalar\String_;
use JDR\Rector\PdoToQb\Parser\CommonSqlParser;

/**
 * Factory for creating QueryBuilder method calls
 * Eliminates duplication across different query builders
 */
class QueryBuilderFactory
{
    /**
     * Create a method call on the query builder
     */
    public function createMethodCall(MethodCall $queryBuilder, string $method, array $args = []): MethodCall
    {
        $methodArgs = [];
        foreach ($args as $arg) {
            if (is_string($arg)) {
                $methodArgs[] = new Arg(new String_($arg));
            } elseif (is_int($arg)) {
                $methodArgs[] = new Arg(new LNumber($arg));
            } elseif ($arg instanceof Arg) {
                $methodArgs[] = $arg;
            }
        }

        return new MethodCall($queryBuilder, new Identifier($method), $methodArgs);
    }

    /**
     * Add JOIN to query builder based on join information
     */
    public function addJoin(MethodCall $queryBuilder, array $join, string $mainTableAlias = 'main'): MethodCall
    {
        $joinType = strtoupper(trim((string) $join['type']));
        $table = $join['table'];
        $alias = $join['alias'];
        $condition = $join['condition'];

        // Determine the QueryBuilder method based on JOIN type
        $method = match (true) {
            str_contains($joinType, 'LEFT') => 'leftJoin',
            str_contains($joinType, 'RIGHT') => 'rightJoin',
            str_contains($joinType, 'INNER') => 'innerJoin',
            str_contains($joinType, 'CROSS') => 'join',
            default => 'join'
        };

        return $this->createMethodCall($queryBuilder, $method, [
            $mainTableAlias,
            $table,
            $alias,
            $condition
        ]);
    }

    /**
     * Add ORDER BY clauses to query builder
     */
    public function addOrderBy(MethodCall $queryBuilder, array $orderByFields): MethodCall
    {
        foreach ($orderByFields as $orderField) {
            $queryBuilder = $this->createMethodCall($queryBuilder, 'addOrderBy', [
                $orderField['field'],
                $orderField['direction']
            ]);
        }
        return $queryBuilder;
    }

    /**
     * Add GROUP BY clauses to query builder
     */
    public function addGroupBy(MethodCall $queryBuilder, array $groupByFields): MethodCall
    {
        foreach ($groupByFields as $field) {
            $queryBuilder = $this->createMethodCall($queryBuilder, 'addGroupBy', [$field]);
        }
        return $queryBuilder;
    }

    /**
     * Add LIMIT to query builder
     */
    public function addLimit(MethodCall $queryBuilder, ?int $limit): MethodCall
    {
        if ($limit !== null) {
            return $this->createMethodCall($queryBuilder, 'setMaxResults', [$limit]);
        }
        return $queryBuilder;
    }

    /**
     * Add OFFSET to query builder
     */
    public function addOffset(MethodCall $queryBuilder, ?int $offset): MethodCall
    {
        if ($offset !== null) {
            return $this->createMethodCall($queryBuilder, 'setFirstResult', [$offset]);
        }
        return $queryBuilder;
    }

    /**
     * Add HAVING clause to query builder
     */
    public function addHaving(MethodCall $queryBuilder, string $havingClause): MethodCall
    {
        return $this->createMethodCall($queryBuilder, 'having', [$havingClause]);
    }

    /**
     * Add SET clauses for UPDATE/INSERT queries
     */
    public function addSetClauses(MethodCall $queryBuilder, array $setPairs, string $method = 'set'): MethodCall
    {
        foreach ($setPairs as $pair) {
            if (isset($pair['column']) && isset($pair['value'])) {
                $queryBuilder = $this->createMethodCall($queryBuilder, $method, [
                    $pair['column'],
                    $pair['value']
                ]);
            }
        }
        return $queryBuilder;
    }

    /**
     * Add WHERE clause to query builder using CommonSqlParser for condition splitting
     */
    public function addWhere(MethodCall $queryBuilder, string $whereClause, CommonSqlParser $commonParser): MethodCall
    {
        // Use CommonSqlParser to split conditions
        $conditions = $commonParser->splitWhereConditions($whereClause);

        // Apply the first condition with where()
        if ($conditions !== []) {
            $firstCondition = array_shift($conditions);
            $queryBuilder = $this->createMethodCall($queryBuilder, 'where', [$firstCondition['condition']]);

            // Apply remaining conditions with andWhere() or orWhere()
            foreach ($conditions as $condition) {
                $method = $condition['operator'] === 'OR' ? 'orWhere' : 'andWhere';
                $queryBuilder = $this->createMethodCall($queryBuilder, $method, [$condition['condition']]);
            }
        }

        return $queryBuilder;
    }

    /**
     * Add WHERE clause from SQL query
     * Extracts WHERE clause and builds QueryBuilder methods
     */
    public function addWhereFromSql(MethodCall $queryBuilder, string $sql, CommonSqlParser $commonParser): MethodCall
    {
        $whereClause = $commonParser->parseWhere($sql);

        if ($whereClause !== null) {
            return $this->addWhere($queryBuilder, $whereClause, $commonParser);
        }

        return $queryBuilder;
    }
}