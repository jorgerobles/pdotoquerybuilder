<?php

declare(strict_types=1);

namespace JDR\Rector\PdoToQb\QueryBuilder;

use PhpParser\Node\Expr\MethodCall;
use JDR\Rector\PdoToQb\Parser\CommonSqlParser;

/**
 * Refactored DELETE query builder using common utilities
 */
class DeleteQueryBuilder
{
    /**
     * @readonly
     */
    private CommonSqlParser $commonParser;
    /**
     * @readonly
     */
    private QueryBuilderFactory $factory;

    public function __construct(
        ?CommonSqlParser $commonParser = null,
        ?QueryBuilderFactory $factory = null
    ) {
        $this->commonParser = $commonParser ?? new CommonSqlParser();
        $this->factory = $factory ?? new QueryBuilderFactory();
    }

    public function build(MethodCall $queryBuilder, string $sql): MethodCall
    {
        $sql = $this->commonParser->normalizeSql($sql);
        $parts = $this->parseDeleteQuery($sql);

        // DELETE FROM table
        if (!empty($parts['table'])) {
            // For DELETE queries, if there's an alias and JOINs, pass alias to delete() method
            if ($parts['table']['hasExplicitAlias'] && !empty($parts['joins'])) {
                $queryBuilder = $this->factory->createMethodCall($queryBuilder, 'delete', [
                    $parts['table']['table'],
                    $parts['table']['alias']
                ]);
            } else {
                // Simple DELETE without alias
                $queryBuilder = $this->factory->createMethodCall($queryBuilder, 'delete', [$parts['table']['table']]);
            }
        }

        // Handle multi-table deletes with JOINs
        if (!empty($parts['joins'])) {
            $mainTableAlias = $parts['table']['alias'] ?? 'main';
            foreach ($parts['joins'] as $join) {
                $queryBuilder = $this->factory->addJoin($queryBuilder, $join, $mainTableAlias);
            }
        }

        // WHERE clause - now handled by QueryBuilderFactory
        if (!empty($parts['where'])) {
            $queryBuilder = $this->factory->addWhere($queryBuilder, $parts['where'], $this->commonParser);
        }

        // ORDER BY clause (MySQL supports this in DELETE)
        if (!empty($parts['orderBy'])) {
            $queryBuilder = $this->factory->addOrderBy($queryBuilder, $parts['orderBy']);
        }

        // LIMIT clause (MySQL supports this in DELETE)
        $queryBuilder = $this->factory->addLimit($queryBuilder, $parts['limit']);

        return $queryBuilder;
    }

    private function parseDeleteQuery(string $sql): array
    {
        $parts = [];

        // Handle different DELETE patterns:
        // 1. DELETE FROM table [alias]
        // 2. DELETE alias FROM table alias JOIN ...
        // 3. DELETE t1, t2 FROM table1 t1 JOIN table2 t2 ...

        // Pattern 1: Standard DELETE FROM table [AS alias]
        if (preg_match('/DELETE\s+FROM\s+(\w+)(?:\s+(?:AS\s+)?(\w+))?/i', $sql, $matches)) {
            $tableInfo = $this->commonParser->parseTableWithAlias($matches[1] . (isset($matches[2]) ? ' ' . $matches[2] : ''));
            $parts['table'] = $tableInfo;
        }
        // Pattern 2: DELETE alias FROM table alias (MySQL multi-table delete)
        elseif (preg_match('/DELETE\s+(\w+)(?:,\s*\w+)*\s+FROM\s+(\w+)(?:\s+(?:AS\s+)?(\w+))?/i', $sql, $matches)) {
            $parts['deleteTargets'] = array_map('trim', explode(',', $matches[1]));
            // For multi-table DELETE, the main table info comes from the FROM clause
            $tableInfo = $this->commonParser->parseTableWithAlias($matches[2] . (isset($matches[3]) ? ' ' . $matches[3] : ''));
            $parts['table'] = $tableInfo;

            // Ensure we recognize this has an alias for JOIN purposes
            if (isset($matches[3]) || $matches[1] === $matches[2]) {
                $parts['table']['hasExplicitAlias'] = true;
                $parts['table']['alias'] = $matches[3] ?? $matches[1];
            }
        }

        // Handle JOINs for multi-table deletes
        $parts['joins'] = $this->commonParser->parseJoins($sql);

        // WHERE clause - now parsed by CommonSqlParser
        $parts['where'] = $this->commonParser->parseWhere($sql);

        // ORDER BY clause (MySQL specific for DELETE)
        if (preg_match('/ORDER\s+BY\s+(.+?)(?:\s+LIMIT|$)/i', $sql, $matches)) {
            $parts['orderBy'] = $this->commonParser->parseOrderBy(trim($matches[1]));
        }

        // LIMIT clause (MySQL specific for DELETE)
        $parts['limit'] = $this->commonParser->parseLimit($sql);

        return $parts;
    }
}