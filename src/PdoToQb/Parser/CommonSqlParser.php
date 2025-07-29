<?php

declare(strict_types=1);

namespace JDR\Rector\PdoToQb\Parser;

/**
 * Common SQL parsing utilities shared across different query builders
 */
class CommonSqlParser
{
    /**
     * Parse table name and alias from SQL fragment
     * Handles patterns like: "table", "table alias", "table AS alias"
     */
    public function parseTableWithAlias(string $tableFragment): array
    {
        $tableFragment = trim($tableFragment);

        // Pattern: table_name [AS] alias
        if (preg_match('/^(\w+)(?:\s+(?:AS\s+)?(\w+))?$/i', $tableFragment, $matches)) {
            $tableName = $matches[1];
            $hasExplicitAlias = isset($matches[2]);
            $alias = $hasExplicitAlias ? $matches[2] : null;

            return [
                'table' => $tableName,
                'alias' => $alias,
                'hasExplicitAlias' => $hasExplicitAlias
            ];
        }

        return [
            'table' => $tableFragment,
            'alias' => null,
            'hasExplicitAlias' => false
        ];
    }

    /**
     * Parse JOIN clauses from SQL
     * Returns array of join information
     */
    public function parseJoins(string $sql): array
    {
        $joins = [];

        // Pattern for JOINs - works for SELECT, UPDATE, DELETE
        $joinPattern = '/\b((?:LEFT|RIGHT|INNER|OUTER|CROSS)?\s*JOIN)\s+(\w+)(?:\s+(?:AS\s+)?(\w+))?\s+ON\s+([^)]+?)(?=\s+(?:LEFT|RIGHT|INNER|OUTER|CROSS)?\s*JOIN|\s+WHERE|\s+SET|\s+GROUP\s+BY|\s+HAVING|\s+ORDER\s+BY|\s+LIMIT|\s+OFFSET|$)/i';

        if (preg_match_all($joinPattern, $sql, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $tableInfo = $this->parseTableWithAlias($match[2] . (isset($match[3]) ? ' ' . $match[3] : ''));

                $joins[] = [
                    'type' => trim($match[1]),
                    'table' => $tableInfo['table'],
                    'alias' => $tableInfo['alias'],
                    'condition' => trim($match[4]),
                    'hasExplicitAlias' => $tableInfo['hasExplicitAlias']
                ];
            }
        }

        return $joins;
    }

    private static int $globalParamCount = 0;

    /**
     * Convert positional parameters (?) to named parameters (:param1, :param2, etc.)
     */
    public function convertPositionalToNamedParams(string $sql): string
    {
        return preg_replace_callback('/\?/', function($matches): string {
            self::$globalParamCount++;
            return ":param" . self::$globalParamCount;
        }, $sql);
    }

    /**
     * Reset parameter counter to start from :param1 for each new query
     */
    public function resetParameterCounter(): void
    {
        self::$globalParamCount = 0;
    }

    /**
     * Parse ORDER BY clause into individual fields with directions
     */
    public function parseOrderBy(string $orderByClause): array
    {
        $orderByFields = [];
        $fields = array_map('trim', explode(',', $orderByClause));

        foreach ($fields as $field) {
            $parts = preg_split('/\s+/', trim($field));
            $fieldName = $parts[0];
            $direction = strtoupper($parts[1] ?? 'ASC');

            // Handle complex expressions like "CASE WHEN ... END"
            if (strtoupper($fieldName) === 'CASE') {
                // Find the matching END
                $caseExpression = $this->extractCaseExpression($field);
                $fieldName = $caseExpression ?: $field;
                $direction = 'ASC'; // Default for complex expressions
            }

            $orderByFields[] = [
                'field' => $fieldName,
                'direction' => $direction
            ];
        }

        return $orderByFields;
    }

    /**
     * Extract CASE...END expression from ORDER BY field
     */
    private function extractCaseExpression(string $field): ?string
    {
        if (preg_match('/CASE\s+.*?\s+END/i', $field, $matches)) {
            return trim($matches[0]);
        }
        return null;
    }

    /**
     * Parse GROUP BY clause into individual fields
     */
    public function parseGroupBy(string $groupByClause): array
    {
        return array_map('trim', explode(',', $groupByClause));
    }

    /**
     * Extract LIMIT value from SQL
     */
    public function parseLimit(string $sql): ?int
    {
        if (preg_match('/LIMIT\s+(\d+)/i', $sql, $matches)) {
            return (int)$matches[1];
        }
        return null;
    }

    /**
     * Extract OFFSET value from SQL
     */
    public function parseOffset(string $sql): ?int
    {
        if (preg_match('/OFFSET\s+(\d+)/i', $sql, $matches)) {
            return (int)$matches[1];
        }
        return null;
    }

    /**
     * Normalize SQL by cleaning up whitespace and common issues
     */
    public function normalizeSql(string $sql): string
    {
        // Replace multiple whitespace with single space
        $sql = preg_replace('/\s+/', ' ', trim($sql));

        // Fix common SQL issues
        $sql = str_replace(' AND NULL', '', $sql);

        return str_replace('IS AND', 'IS NOT', $sql);
    }

    /**
     * Split comma-separated values respecting quotes and parentheses
     */
    public function splitRespectingDelimiters(string $input, string $delimiter = ','): array
    {
        $result = [];
        $current = '';
        $inQuotes = false;
        $quoteChar = '';
        $depth = 0;

        for ($i = 0; $i < strlen($input); $i++) {
            $char = $input[$i];

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
                // Handle parentheses depth
                if ($char === '(') {
                    $depth++;
                    $current .= $char;
                } elseif ($char === ')') {
                    $depth--;
                    $current .= $char;
                } elseif ($char === $delimiter && $depth === 0) {
                    // Split only at top level
                    $result[] = trim($current);
                    $current = '';
                } else {
                    $current .= $char;
                }
            } else {
                $current .= $char;
            }
        }

        if (trim($current) !== '') {
            $result[] = trim($current);
        }

        return $result;
    }

    /**
     * Find the position of a character at the top level (not inside quotes or parentheses)
     * @return int|false
     */
    public function findTopLevelPosition(string $str, string $searchChar)
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
                } elseif ($char === $searchChar && $depth === 0) {
                    return $i;
                }
            }
        }

        return false;
    }

    /**
     * Check if a string is wrapped in parentheses at the top level
     */
    public function isWrappedInParentheses(string $str): bool
    {
        $str = trim($str);
        if (strlen($str) < 2 || $str[0] !== '(' || $str[strlen($str) - 1] !== ')') {
            return false;
        }

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

    /**
     * Parse WHERE clause and extract it from different query types
     * Returns WHERE clause content without the WHERE keyword
     */
    public function parseWhere(string $sql): ?string
    {
        // Pattern to match WHERE clause, accounting for different query endings
        $patterns = [
            // For SELECT: WHERE ... [GROUP BY|HAVING|ORDER BY|LIMIT|OFFSET|end]
            '/WHERE\s+(.+?)(?:\s+GROUP\s+BY|\s+HAVING|\s+ORDER\s+BY|\s+LIMIT|\s+OFFSET|$)/i',
            // For UPDATE: WHERE ... [ORDER BY|LIMIT|end]
            '/WHERE\s+(.+?)(?:\s+ORDER\s+BY|\s+LIMIT|$)/i',
            // For DELETE: WHERE ... [ORDER BY|LIMIT|end]
            '/WHERE\s+(.+?)(?:\s+ORDER\s+BY|\s+LIMIT|$)/i',
            // Generic fallback: WHERE ... [end]
            '/WHERE\s+(.+?)$/i'
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $sql, $matches)) {
                return trim($matches[1]);
            }
        }

        return null;
    }

    /**
     * Split WHERE clause conditions into individual conditions with their operators
     * Handles complex nested parentheses and preserves logical structure
     */
    public function splitWhereConditions(string $whereClause): array
    {
        // Normalize and convert parameters first
        $whereClause = $this->normalizeSql($whereClause);
        $whereClause = $this->convertPositionalToNamedParams($whereClause);

        // If the entire WHERE clause is wrapped in parentheses, treat it as one condition
        if ($this->isWrappedInParentheses($whereClause)) {
            return [['condition' => $whereClause, 'operator' => null]];
        }

        // Split by top-level AND/OR operators
        $conditions = [];
        $current = '';
        $depth = 0;
        $inQuotes = false;
        $quoteChar = '';
        $i = 0;

        while ($i < strlen($whereClause)) {
            $char = $whereClause[$i];

            // Handle quotes
            if (($char === '"' || $char === "'") && !$inQuotes) {
                $inQuotes = true;
                $quoteChar = $char;
                $current .= $char;
                $i++;
                continue;
            }

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

            if ($char === ')') {
                $depth--;
                $current .= $char;
                $i++;
                continue;
            }

            // Only split on top-level AND/OR (depth = 0)
            if ($depth === 0) {
                $remaining = substr($whereClause, $i);

                // Check for AND operator
                if (preg_match('/^\s*AND\s+/i', $remaining, $matches)) {
                    if (trim($current) !== '') {
                        $conditions[] = ['condition' => trim($current), 'operator' => null];
                        $current = '';
                    }
                    $i += strlen($matches[0]);

                    // Extract next condition
                    $nextCondition = $this->extractNextWhereCondition(substr($whereClause, $i));
                    if ($nextCondition) {
                        $conditions[] = ['condition' => trim((string) $nextCondition['condition']), 'operator' => 'AND'];
                        $i += strlen((string) $nextCondition['condition']);
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

                    // Extract next condition
                    $nextCondition = $this->extractNextWhereCondition(substr($whereClause, $i));
                    if ($nextCondition) {
                        $conditions[] = ['condition' => trim((string) $nextCondition['condition']), 'operator' => 'OR'];
                        $i += strlen((string) $nextCondition['condition']);
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
            return [['condition' => $whereClause, 'operator' => null]];
        }

        return $conditions;
    }

    /**
     * Extract the next condition from a WHERE clause fragment
     */
    private function extractNextWhereCondition(string $remaining): ?array
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
}