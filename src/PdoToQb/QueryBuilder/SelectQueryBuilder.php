<?php
declare(strict_types=1);

namespace JDR\Rector\PdoToQb\QueryBuilder;

use PhpParser\Node\Expr\MethodCall;
use JDR\Rector\PdoToQb\Parser\CommonSqlParser;

/**
 * Refactored SELECT query builder using common utilities
 */
class SelectQueryBuilder
{
    private CommonSqlParser $commonParser;
    private QueryBuilderFactory $factory;

    public function __construct(
        CommonSqlParser $commonParser = null,
        QueryBuilderFactory $factory = null
    ) {
        $this->commonParser = $commonParser ?? new CommonSqlParser();
        $this->factory = $factory ?? new QueryBuilderFactory();
    }

    public function build(MethodCall $queryBuilder, string $sql): MethodCall
    {
        $sql = $this->commonParser->normalizeSql($sql);
        $parts = $this->parseSelectQuery($sql);

        // SELECT clause
        $selectClause = $parts['select'] ?? '*';
        $queryBuilder = $this->factory->createMethodCall($queryBuilder, 'select', [$selectClause]);

        // FROM clause with alias
        if (!empty($parts['from'])) {
            // Only add alias if it's explicitly different from table name
            if ($parts['from']['hasExplicitAlias']) {
                $queryBuilder = $this->factory->createMethodCall($queryBuilder, 'from', [
                    $parts['from']['table'],
                    $parts['from']['alias']
                ]);
            } else {
                // No alias, just table name
                $queryBuilder = $this->factory->createMethodCall($queryBuilder, 'from', [
                    $parts['from']['table']  // ← Only table name, no alias
                ]);
            }
        }

        // JOINs
        if (!empty($parts['joins'])) {
            // Use table name as main alias when no explicit alias exists
            $mainTableAlias = $parts['from']['hasExplicitAlias'] ? $parts['from']['alias'] : $parts['from']['table'];
            foreach ($parts['joins'] as $join) {
                $queryBuilder = $this->factory->addJoin($queryBuilder, $join, $mainTableAlias);
            }
        }

        // WHERE clause
        if (!empty($parts['where'])) {
            $queryBuilder = $this->factory->addWhere($queryBuilder, $parts['where'], $this->commonParser);
        }

        // GROUP BY clause
        if (!empty($parts['groupBy'])) {
            $queryBuilder = $this->factory->addGroupBy($queryBuilder, $parts['groupBy']);
        }

        // HAVING clause
        if (!empty($parts['having'])) {
            $queryBuilder = $this->factory->addHaving($queryBuilder, $parts['having']);
        }

        // ORDER BY clause
        if (!empty($parts['orderBy'])) {
            $queryBuilder = $this->factory->addOrderBy($queryBuilder, $parts['orderBy']);
        }

        // LIMIT and OFFSET
        $queryBuilder = $this->factory->addLimit($queryBuilder, $parts['limit']);
        $queryBuilder = $this->factory->addOffset($queryBuilder, $parts['offset']);

        return $queryBuilder;
    }

    private function parseSelectQuery(string $sql): array
    {
        $parts = [];

        // SELECT clause
        if (preg_match('/SELECT\s+(.+?)\s+FROM/i', $sql, $matches)) {
            $parts['select'] = trim($matches[1]);
        }

        // FROM clause with alias
        if (preg_match('/FROM\s+(\w+)(?:\s+(?:AS\s+)?(\w+))?/i', $sql, $matches)) {
            $tableInfo = $this->commonParser->parseTableWithAlias($matches[1] . (isset($matches[2]) ? ' ' . $matches[2] : ''));
            $parts['from'] = $tableInfo;
        }

        // JOINs
        $parts['joins'] = $this->commonParser->parseJoins($sql);

        // WHERE clause
        $parts['where'] = $this->commonParser->parseWhere($sql);

        // GROUP BY clause
        if (preg_match('/GROUP\s+BY\s+(.+?)(?:\s+HAVING|\s+ORDER\s+BY|\s+LIMIT|\s+OFFSET|$)/i', $sql, $matches)) {
            $parts['groupBy'] = $this->commonParser->parseGroupBy(trim($matches[1]));
        }

        // HAVING clause
        if (preg_match('/HAVING\s+(.+?)(?:\s+ORDER\s+BY|\s+LIMIT|\s+OFFSET|$)/i', $sql, $matches)) {
            $parts['having'] = trim($matches[1]);
        }

        // ORDER BY clause
        if (preg_match('/ORDER\s+BY\s+(.+?)(?:\s+LIMIT|\s+OFFSET|$)/i', $sql, $matches)) {
            $parts['orderBy'] = $this->commonParser->parseOrderBy(trim($matches[1]));
        }

        // LIMIT and OFFSET
        $parts['limit'] = $this->commonParser->parseLimit($sql);
        $parts['offset'] = $this->commonParser->parseOffset($sql);

        return $parts;
    }
}