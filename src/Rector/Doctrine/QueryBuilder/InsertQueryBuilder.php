<?php

declare(strict_types=1);

namespace App\Rector\Doctrine\QueryBuilder;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar\String_;

/**
 * Builds Doctrine QueryBuilder for INSERT queries
 */
class InsertQueryBuilder
{
    public function build(MethodCall $queryBuilder, string $sql): MethodCall
    {
        $parts = $this->parseInsertQuery($sql);

        // INSERT INTO table
        if (!empty($parts['table'])) {
            $queryBuilder = new MethodCall(
                $queryBuilder,
                new Identifier('insert'),
                [new Arg(new String_($parts['table']))]
            );
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
            $queryBuilder = $this->buildInsertSet($queryBuilder, $parts['setClause']);
        }

        return $queryBuilder;
    }

    private function parseInsertQuery(string $sql): array
    {
        $parts = [];
        $sql = preg_replace('/\s+/', ' ', trim($sql));

        // INSERT INTO table
        if (preg_match('/INSERT\s+(?:INTO\s+)?(\w+)/i', $sql, $matches)) {
            $parts['table'] = $matches[1];
        }

        // Pattern 1: INSERT INTO table (columns) VALUES (values)
        if (preg_match('/\(([^)]+)\)\s+VALUES\s*\(([^)]+)\)/i', $sql, $matches)) {
            $parts['columns'] = $this->parseColumnList($matches[1]);
            $parts['values'] = $this->parseValueList($matches[2]);
        }
        // Pattern 2: INSERT INTO table VALUES (values) - without explicit columns
        elseif (preg_match('/VALUES\s*\(([^)]+)\)/i', $sql, $matches)) {
            $parts['values'] = $this->parseValueList($matches[1]);
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
                    $parts['multipleValues'][] = $this->parseValueList($rowValues);
                }
            }
        }

        return $parts;
    }

    private function parseColumnList(string $columnList): array
    {
        // Split by comma, but respect quotes and functions
        $columns = [];
        $current = '';
        $inQuotes = false;
        $quoteChar = '';
        $depth = 0;

        for ($i = 0; $i < strlen($columnList); $i++) {
            $char = $columnList[$i];

            if (($char === '"' || $char === "'" || $char === '`') && !$inQuotes) {
                $inQuotes = true;
                $quoteChar = $char;
                $current .= $char;
            } elseif ($char === $quoteChar && $inQuotes) {
                $inQuotes = false;
                $quoteChar = '';
                $current .= $char;
            } elseif (!$inQuotes) {
                if ($char === '(') {
                    $depth++;
                    $current .= $char;
                } elseif ($char === ')') {
                    $depth--;
                    $current .= $char;
                } elseif ($char === ',' && $depth === 0) {
                    $columns[] = trim($current);
                    $current = '';
                } else {
                    $current .= $char;
                }
            } else {
                $current .= $char;
            }
        }

        if (trim($current) !== '') {
            $columns[] = trim($current);
        }

        // Clean column names (remove quotes, etc.)
        return array_map(function($column) {
            return trim($column, " \t\n\r\0\x0B`\"'");
        }, $columns);
    }

    private function parseValueList(string $valueList): array
    {
        // Similar parsing logic as columns but preserve the original format for values
        $values = [];
        $current = '';
        $inQuotes = false;
        $quoteChar = '';
        $depth = 0;

        for ($i = 0; $i < strlen($valueList); $i++) {
            $char = $valueList[$i];

            if (($char === '"' || $char === "'") && !$inQuotes) {
                $inQuotes = true;
                $quoteChar = $char;
                $current .= $char;
            } elseif ($char === $quoteChar && $inQuotes) {
                $inQuotes = false;
                $quoteChar = '';
                $current .= $char;
            } elseif (!$inQuotes) {
                if ($char === '(') {
                    $depth++;
                    $current .= $char;
                } elseif ($char === ')') {
                    $depth--;
                    $current .= $char;
                } elseif ($char === ',' && $depth === 0) {
                    $values[] = trim($current);
                    $current = '';
                } else {
                    $current .= $char;
                }
            } else {
                $current .= $char;
            }
        }

        if (trim($current) !== '') {
            $values[] = trim($current);
        }

        return $values;
    }

    private function buildInsertWithColumns(MethodCall $queryBuilder, array $columns, array $values): MethodCall
    {
        // Convert positional parameters to named parameters
        $paramCount = 0;
        $processedValues = [];

        foreach ($values as $value) {
            if ($value === '?') {
                $paramCount++;
                $processedValues[] = ":param$paramCount";
            } else {
                $processedValues[] = $value;
            }
        }

        // Add setValue() calls for each column-value pair
        $columnCount = count($columns);
        $valueCount = count($processedValues);
        $maxCount = max($columnCount, $valueCount);

        for ($i = 0; $i < $maxCount; $i++) {
            $column = $columns[$i] ?? "column$i";
            $value = $processedValues[$i] ?? '?';

            $queryBuilder = new MethodCall(
                $queryBuilder,
                new Identifier('setValue'),
                [
                    new Arg(new String_($column)),
                    new Arg(new String_($value))
                ]
            );
        }

        return $queryBuilder;
    }

    private function buildInsertSelect(MethodCall $queryBuilder, string $selectQuery): MethodCall
    {
        // For INSERT ... SELECT, we need to add the select query as raw SQL
        // This is more complex and might require a different approach in Doctrine
        // For now, we'll create a comment indicating this needs manual conversion

        $queryBuilder = new MethodCall(
            $queryBuilder,
            new Identifier('setValue'),
            [
                new Arg(new String_('__INSERT_SELECT__')),
                new Arg(new String_("/* TODO: Convert SELECT query: $selectQuery */"))
            ]
        );

        return $queryBuilder;
    }

    private function buildInsertSet(MethodCall $queryBuilder, string $setClause): MethodCall
    {
        // Parse SET clause: column1 = value1, column2 = value2, ...
        $setPairs = $this->parseSetClause($setClause);

        foreach ($setPairs as $pair) {
            if (isset($pair['column']) && isset($pair['value'])) {
                $queryBuilder = new MethodCall(
                    $queryBuilder,
                    new Identifier('setValue'),
                    [
                        new Arg(new String_($pair['column'])),
                        new Arg(new String_($pair['value']))
                    ]
                );
            }
        }

        return $queryBuilder;
    }

    private function parseSetClause(string $setClause): array
    {
        $pairs = [];
        $current = '';
        $inQuotes = false;
        $quoteChar = '';
        $depth = 0;

        for ($i = 0; $i < strlen($setClause); $i++) {
            $char = $setClause[$i];

            if (($char === '"' || $char === "'") && !$inQuotes) {
                $inQuotes = true;
                $quoteChar = $char;
                $current .= $char;
            } elseif ($char === $quoteChar && $inQuotes) {
                $inQuotes = false;
                $quoteChar = '';
                $current .= $char;
            } elseif (!$inQuotes) {
                if ($char === '(') {
                    $depth++;
                    $current .= $char;
                } elseif ($char === ')') {
                    $depth--;
                    $current .= $char;
                } elseif ($char === ',' && $depth === 0) {
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
        if (strpos($pair, '=') === false) {
            return null;
        }

        $parts = explode('=', $pair, 2);
        $column = trim($parts[0]);
        $value = trim($parts[1]);

        // Convert ? to named parameters
        if ($value === '?') {
            static $paramCount = 0;
            $paramCount++;
            $value = ":param$paramCount";
        }

        return [
            'column' => trim($column, " \t\n\r\0\x0B`\"'"),
            'value' => $value
        ];
    }
}