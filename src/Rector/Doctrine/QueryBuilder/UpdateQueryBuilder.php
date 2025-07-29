<?php

declare(strict_types=1);

namespace App\Rector\Doctrine\QueryBuilder;

use PhpParser\Node\Expr\MethodCall;
use App\Rector\Doctrine\Parser\CommonSqlParser;
use App\Rector\Doctrine\Parser\WhereClauseParser;
use App\Rector\Doctrine\Parser\SetClauseParser;

/**
 * Refactored UPDATE query builder using common utilities
 */
class UpdateQueryBuilder
{
    private CommonSqlParser $commonParser;
    private SetClauseParser $setParser;
    private QueryBuilderFactory $factory;

    public function __construct(
        CommonSqlParser $commonParser = null,
        SetClauseParser $setParser = null,
        QueryBuilderFactory $factory = null
    ) {
        $this->commonParser = $commonParser ?? new CommonSqlParser();
        $this->factory = $factory ?? new QueryBuilderFactory();
        $this->setParser = $setParser ?? new SetClauseParser($this->commonParser);
    }

    public function build(MethodCall $queryBuilder, string $sql): MethodCall
    {
        $sql = $this->commonParser->normalizeSql($sql);
        $parts = $this->parseUpdateQuery($sql);

        // UPDATE table
        if (!empty($parts['table'])) {
            // For UPDATE queries, if there's an alias and JOINs, pass alias to update() method
            if ($parts['table']['hasExplicitAlias'] && !empty($parts['joins'])) {
                $queryBuilder = $this->factory->createMethodCall($queryBuilder, 'update', [
                    $parts['table']['table'],
                    $parts['table']['alias']
                ]);
            } else {
                // Simple UPDATE without alias
                $queryBuilder = $this->factory->createMethodCall($queryBuilder, 'update', [$parts['table']['table']]);
            }
        }

        // SET clause
        if (!empty($parts['setClause'])) {
            $setPairs = $this->setParser->parseSetClause($parts['setClause']);
            $queryBuilder = $this->factory->addSetClauses($queryBuilder, $setPairs, 'set');
        }

        // JOINs (for multi-table updates)
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

        // ORDER BY clause (MySQL supports this in UPDATE)
        if (!empty($parts['orderBy'])) {
            $queryBuilder = $this->factory->addOrderBy($queryBuilder, $parts['orderBy']);
        }

        // LIMIT clause (MySQL supports this in UPDATE)
        $queryBuilder = $this->factory->addLimit($queryBuilder, $parts['limit']);

        return $queryBuilder;
    }

    private function parseUpdateQuery(string $sql): array
    {
        $parts = [];

        // UPDATE table [alias]
        if (preg_match('/UPDATE\s+(\w+)(?:\s+(?:AS\s+)?(\w+))?/i', $sql, $matches)) {
            $tableInfo = $this->commonParser->parseTableWithAlias($matches[1] . (isset($matches[2]) ? ' ' . $matches[2] : ''));
            $parts['table'] = $tableInfo;
        }

        // JOINs (for multi-table updates)
        $parts['joins'] = $this->commonParser->parseJoins($sql);

        // SET clause
        if (preg_match('/SET\s+(.+?)(?:\s+WHERE|\s+ORDER\s+BY|\s+LIMIT|$)/i', $sql, $matches)) {
            $parts['setClause'] = trim($matches[1]);
        }

        // WHERE clause - now parsed by CommonSqlParser
        $parts['where'] = $this->commonParser->parseWhere($sql);

        // ORDER BY clause (MySQL specific for UPDATE)
        if (preg_match('/ORDER\s+BY\s+(.+?)(?:\s+LIMIT|$)/i', $sql, $matches)) {
            $parts['orderBy'] = $this->commonParser->parseOrderBy(trim($matches[1]));
        }

        // LIMIT clause (MySQL specific for UPDATE)
        $parts['limit'] = $this->commonParser->parseLimit($sql);

        return $parts;
    }
}