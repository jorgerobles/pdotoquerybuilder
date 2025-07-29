<?php

declare(strict_types=1);

namespace JDR\Rector\PdoToQb\Parser;

/**
 * Common SQL parsing utilities shared across different query builders
 */
class CommonSqlParser
{
    private static int $globalParamCount = 0;

    /**
     * Parse table name and alias from SQL fragment
     */
    public function parseTableWithAlias(string $tableFragment): array
    {
        $tableFragment = trim($tableFragment);

        if (preg_match('/^(\w+)(?:\s+(?:AS\s+)?(\w+))?$/i', $tableFragment, $matches)) {
            $tableName = $matches[1];
            $hasExplicitAlias = isset($matches[2]) && !empty($matches[2]); // ← Better detection
            $alias = $hasExplicitAlias ? $matches[2] : $tableName;

            return [
                'table' => $tableName,
                'alias' => $alias,
                'hasExplicitAlias' => $hasExplicitAlias
            ];
        }

        return [
            'table' => $tableFragment,
            'alias' => $tableFragment,
            'hasExplicitAlias' => false
        ];
    }

    /**
     * Parse JOIN clauses from SQL
     */
    public function parseJoins(string $sql): array
    {
        $joins = [];
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

            if (strtoupper($fieldName) === 'CASE') {
                $caseExpression = $this->extractCaseExpression($field);
                $fieldName = $caseExpression ?: $field;
                $direction = 'ASC';
            }

            $orderByFields[] = [
                'field' => $fieldName,
                'direction' => $direction
            ];
        }

        return $orderByFields;
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
        $sql = preg_replace('/\s+/', ' ', trim($sql));
        $sql = str_replace(' AND NULL', '', $sql);
        return str_replace('IS AND', 'IS NOT', $sql);
    }

    /**
     * Parse WHERE clause and extract it from different query types
     */
    public function parseWhere(string $sql): ?string
    {
        $patterns = [
            '/WHERE\s+(.+?)(?:\s+GROUP\s+BY|\s+HAVING|\s+ORDER\s+BY|\s+LIMIT|\s+OFFSET|$)/i',
            '/WHERE\s+(.+?)(?:\s+ORDER\s+BY|\s+LIMIT|$)/i',
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
     */
    public function splitWhereConditions(string $whereClause): array
    {
        $whereClause = $this->normalizeSql($whereClause);
        $whereClause = $this->convertPositionalToNamedParams($whereClause);

        if ($this->isWrappedInParentheses($whereClause)) {
            return [['condition' => $whereClause, 'operator' => null]];
        }

        $conditions = [];
        $current = '';
        $depth = 0;
        $inQuotes = false;
        $quoteChar = '';
        $i = 0;

        while ($i < strlen($whereClause)) {
            $char = $whereClause[$i];

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

            if ($depth === 0) {
                $remaining = substr($whereClause, $i);

                if (preg_match('/^\s*AND\s+/i', $remaining, $matches)) {
                    if (trim($current) !== '') {
                        $conditions[] = ['condition' => trim($current), 'operator' => null];
                        $current = '';
                    }
                    $i += strlen($matches[0]);

                    $nextCondition = $this->extractNextWhereCondition(substr($whereClause, $i));
                    if ($nextCondition) {
                        $conditions[] = ['condition' => trim($nextCondition['condition']), 'operator' => 'AND'];
                        $i += strlen($nextCondition['condition']);
                        $current = '';
                        continue;
                    }
                } elseif (preg_match('/^\s*OR\s+/i', $remaining, $matches)) {
                    if (trim($current) !== '') {
                        $conditions[] = ['condition' => trim($current), 'operator' => null];
                        $current = '';
                    }
                    $i += strlen($matches[0]);

                    $nextCondition = $this->extractNextWhereCondition(substr($whereClause, $i));
                    if ($nextCondition) {
                        $conditions[] = ['condition' => trim($nextCondition['condition']), 'operator' => 'OR'];
                        $i += strlen($nextCondition['condition']);
                        $current = '';
                        continue;
                    }
                }
            }

            $current .= $char;
            $i++;
        }

        if (trim($current) !== '') {
            $conditions[] = ['condition' => trim($current), 'operator' => null];
        }

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

            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
            }

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
                } elseif ($char === $delimiter && $depth === 0) {
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
     * Find the position of a character at the top level
     */
    public function findTopLevelPosition(string $str, string $searchChar): int|false
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
                    if ($depth === 0 && $i < strlen($str) - 1) {
                        return false;
                    }
                }
            }
        }

        return $depth === 0;
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
}