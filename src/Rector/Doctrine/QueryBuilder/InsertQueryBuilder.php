<?php

declare(strict_types=1);

namespace App\Rector\Doctrine\QueryBuilder;

use PhpParser\Node\Expr\MethodCall;
use App\Rector\Doctrine\Parser\CommonSqlParser;
use App\Rector\Doctrine\Parser\SetClauseParser;

/**
 * Refactored INSERT query builder using common utilities
 */
readonly class InsertQueryBuilder
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
        $this->setParser = $setParser ?? new SetClauseParser($this->commonParser);
        $this->factory = $factory ?? new QueryBuilderFactory();
    }

    public function build(MethodCall $queryBuilder, string $sql): MethodCall
    {
        $sql = $this->commonParser->normalizeSql($sql);
        $parts = $this->parseInsertQuery($sql);

        // INSERT INTO table
        if (!empty($parts['table'])) {
            $queryBuilder = $this->factory->createMethodCall($queryBuilder, 'insert', [$parts['table']]);
        }

        // Handle different INSERT patterns
        if (!empty($parts['columns']) && !empty($parts['values'])) {
            // Standard INSERT with explicit columns and values
            $queryBuilder = $this->buildInsertWithColumns($queryBuilder, $parts['columns'], $parts['values']);
        } elseif (!empty($parts['selectQuery'])) {
            // INSERT ... SELECT
            $queryBuilder = $this->buildInsertSelect($queryBuilder, $parts['selectQuery']);
        } elseif (!empty($parts['setClause'])) {
            // INSERT ... SET (MySQL specific)
            $setPairs = $this->setParser->parseSetClause($parts['setClause']);
            $queryBuilder = $this->factory->addSetClauses($queryBuilder, $setPairs, 'setValue');
        } elseif (!empty($parts['multipleValues'])) {
            // Handle multiple VALUES rows (INSERT INTO ... VALUES (...), (...), ...)
            // For now, we'll handle just the first row and add a comment for manual review
            if (!empty($parts['multipleValues'][0])) {
                $queryBuilder = $this->buildInsertWithValues($queryBuilder, $parts['multipleValues'][0]);
                if (count($parts['multipleValues']) > 1) {
                    // Add comment for additional rows
                    $queryBuilder = $this->factory->createMethodCall($queryBuilder, 'setValue', [
                        '__MULTIPLE_VALUES__',
                        '/* TODO: Handle ' . (count($parts['multipleValues']) - 1) . ' additional rows */'
                    ]);
                }
            }
        }

        return $queryBuilder;
    }

    private function parseInsertQuery(string $sql): array
    {
        $parts = [];

        // INSERT INTO table
        if (preg_match('/INSERT\s+(?:INTO\s+)?(\w+)/i', $sql, $matches)) {
            $parts['table'] = $matches[1];
        }

        // Pattern 1: INSERT INTO table (columns) VALUES (values)
        if (preg_match('/\(([^)]+)\)\s+VALUES\s*\(([^)]+)\)/i', $sql, $matches)) {
            $parts['columns'] = $this->setParser->parseColumnList($matches[1]);
            $parts['values'] = $this->setParser->parseValueList($matches[2]);
        }
        // Pattern 2: INSERT INTO table VALUES (values) - without explicit columns
        elseif (preg_match('/VALUES\s*\(([^)]+)\)/i', $sql, $matches)) {
            $parts['values'] = $this->setParser->parseValueList($matches[1]);
        }
        // Pattern 3: INSERT ... SELECT
        elseif (preg_match('/INSERT\s+(?:INTO\s+)?\w+\s+(SELECT.+)/i', $sql, $matches)) {
            $parts['selectQuery'] = trim($matches[1]);
        }
        // Pattern 4: INSERT ... SET (MySQL specific)
        elseif (preg_match('/SET\s+(.+)$/i', $sql, $matches)) {
            $parts['setClause'] = trim($matches[1]);
        }

        // Handle multiple VALUES rows: INSERT INTO table (cols) VALUES (row1), (row2), ...
        if (preg_match('/VALUES\s*(.+)$/i', $sql, $matches)) {
            $valuesSection = $matches[1];
            if (preg_match_all('/\(([^)]+)\)/i', $valuesSection, $rowMatches)) {
                $parts['multipleValues'] = [];
                foreach ($rowMatches[1] as $rowValues) {
                    $parts['multipleValues'][] = $this->setParser->parseValueList($rowValues);
                }
            }
        }

        return $parts;
    }

    private function buildInsertWithColumns(MethodCall $queryBuilder, array $columns, array $values): MethodCall
    {
        // Create setValue calls for each column-value pair
        $maxCount = max(count($columns), count($values));

        for ($i = 0; $i < $maxCount; $i++) {
            $column = $columns[$i] ?? "column$i";
            $value = $values[$i] ?? '?';

            $queryBuilder = $this->factory->createMethodCall($queryBuilder, 'setValue', [$column, $value]);
        }

        return $queryBuilder;
    }

    private function buildInsertWithValues(MethodCall $queryBuilder, array $values): MethodCall
    {
        // When no explicit columns are provided, create generic column names
        foreach ($values as $index => $value) {
            $column = "column" . ($index + 1);
            $queryBuilder = $this->factory->createMethodCall($queryBuilder, 'setValue', [$column, $value]);
        }

        return $queryBuilder;
    }

    private function buildInsertSelect(MethodCall $queryBuilder, string $selectQuery): MethodCall
    {
        // For INSERT ... SELECT, we need to add the select query as raw SQL
        // This is more complex and might require a different approach in Doctrine
        // For now, we'll create a comment indicating this needs manual conversion

        return $this->factory->createMethodCall($queryBuilder, 'setValue', [
            '__INSERT_SELECT__',
            "/* TODO: Convert SELECT query: $selectQuery */"
        ]);
    }
}