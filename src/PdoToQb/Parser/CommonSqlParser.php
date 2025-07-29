<?php

declare(strict_types=1);

namespace JDR\Rector\PdoToQb\Parser;

/**
 * COMPLETELY FIXED: Common SQL parsing utilities
 * This version eliminates the JOIN keyword capture issue entirely
 */
class CommonSqlParser
{
    /**
     * Parse table name and alias from SQL fragment
     */
    public function parseTableWithAlias(string $tableFragment): array
    {
        $tableFragment = trim($tableFragment);

        // Pattern: table_name [AS] alias
        if (preg_match('/^(\w+)(?:\s+(?:AS\s+)?(\w+))?$/i', $tableFragment, $matches)) {
            $tableName = $matches[1];
            $hasExplicitAlias = isset($matches[2]) && (isset($matches[2]) && ($matches[2] !== '' && $matches[2] !== '0'));
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
     * COMPLETELY REWRITTEN: FROM clause parsing that never captures JOIN keywords
     */
    public function parseFromClause(string $sql): ?array
    {
        // Step 1: Try the most specific pattern first - this should catch most cases correctly
        $specificPattern = '/FROM\s+(\w+)(?:\s+(?:AS\s+)?(\w+))?(?=\s+(?:INNER\s+JOIN|LEFT\s+JOIN|RIGHT\s+JOIN|OUTER\s+JOIN|CROSS\s+JOIN|WHERE|GROUP\s+BY|HAVING|ORDER\s+BY|LIMIT|OFFSET|$))/i';

        if (preg_match($specificPattern, $sql, $matches)) {
            $tableName = $matches[1];
            $hasExplicitAlias = isset($matches[2]) && (isset($matches[2]) && ($matches[2] !== '' && $matches[2] !== '0'));
            $alias = $hasExplicitAlias ? $matches[2] : null;

            return [
                'table' => $tableName,
                'alias' => $alias,
                'hasExplicitAlias' => $hasExplicitAlias
            ];
        }

        // Step 2: Extract just the FROM portion and manually parse it
        if (preg_match('/FROM\s+([^WHERE]+?)(?=\s+WHERE|$)/i', $sql, $matches)) {
            $fromPortion = trim($matches[1]);

            // Remove JOIN clauses to isolate the main table
            $cleanFromPortion = preg_replace('/\s+(?:INNER|LEFT|RIGHT|OUTER|CROSS)\s+JOIN.*/i', '', $fromPortion);
            $cleanFromPortion = trim($cleanFromPortion);

            // Now parse the clean FROM portion
            if (preg_match('/^(\w+)(?:\s+(?:AS\s+)?(\w+))?$/i', $cleanFromPortion, $matches)) {
                $tableName = $matches[1];
                $potentialAlias = $matches[2] ?? null;

                // Validate that the alias is not a reserved word
                $reservedWords = ['INNER', 'LEFT', 'RIGHT', 'OUTER', 'CROSS', 'JOIN', 'WHERE', 'GROUP', 'HAVING', 'ORDER', 'LIMIT', 'OFFSET', 'SET'];
                $hasExplicitAlias = $potentialAlias && !in_array(strtoupper($potentialAlias), $reservedWords, true);

                return [
                    'table' => $tableName,
                    'alias' => $hasExplicitAlias ? $potentialAlias : null,
                    'hasExplicitAlias' => $hasExplicitAlias
                ];
            }
        }

        // Step 3: Last resort - basic FROM pattern with strict validation
        if (preg_match('/FROM\s+(\w+)(?:\s+(?:AS\s+)?(\w+))?/i', $sql, $matches)) {
            $tableName = $matches[1];
            $potentialAlias = $matches[2] ?? null;

            // Very strict validation - reject any reserved words
            $allReservedWords = [
                'INNER', 'LEFT', 'RIGHT', 'OUTER', 'CROSS', 'JOIN',
                'WHERE', 'GROUP', 'BY', 'HAVING', 'ORDER', 'LIMIT', 'OFFSET',
                'SET', 'VALUES', 'SELECT', 'UPDATE', 'DELETE', 'INSERT'
            ];

            $hasExplicitAlias = $potentialAlias &&
                ($potentialAlias !== '' && $potentialAlias !== '0') &&
                !in_array(strtoupper($potentialAlias), $allReservedWords, true);

            return [
                'table' => $tableName,
                'alias' => $hasExplicitAlias ? $potentialAlias : null,
                'hasExplicitAlias' => $hasExplicitAlias
            ];
        }

        return null;
    }

    /**
     * Parse JOIN clauses from SQL
     */
    public function parseJoins(string $sql): array
    {
        $joins = [];

        // Enhanced JOIN pattern
        $joinPattern = '/\b((?:LEFT|RIGHT|INNER|OUTER|CROSS)?\s*JOIN)\s+(\w+)(?:\s+(?:AS\s+)?(\w+))?\s+ON\s+([^)]+?)(?=\s+(?:LEFT|RIGHT|INNER|OUTER|CROSS)?\s*JOIN|\s+WHERE|\s+SET|\s+GROUP\s+BY|\s+HAVING|\s+ORDER\s+BY|\s+LIMIT|\s+OFFSET|$)/i';

        if (preg_match_all($joinPattern, $sql, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $tableName = $match[2];
                $hasExplicitAlias = isset($match[3]) && (isset($match[3]) && ($match[3] !== '' && $match[3] !== '0'));
                $alias = $hasExplicitAlias ? $match[3] : null;

                $joins[] = [
                    'type' => trim($match[1]),
                    'table' => $tableName,
                    'alias' => $alias,
                    'condition' => trim($match[4]),
                    'hasExplicitAlias' => $hasExplicitAlias
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
     * Reset parameter counter
     */
    public function resetParameterCounter(): void
    {
        self::$globalParamCount = 0;
    }

    /**
     * Parse ORDER BY clause
     */
    public function parseOrderBy(string $orderByClause): array
    {
        $orderByFields = [];
        $fields = $this->splitRespectingDelimiters($orderByClause, ',');

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

    private function extractCaseExpression(string $field): ?string
    {
        if (preg_match('/CASE\s+.*?\s+END/i', $field, $matches)) {
            return trim($matches[0]);
        }
        return null;
    }

    /**
     * Parse GROUP BY clause
     */
    public function parseGroupBy(string $groupByClause): array
    {
        return array_map('trim', $this->splitRespectingDelimiters($groupByClause, ','));
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
     * Normalize SQL
     */
    public function normalizeSql(string $sql): string
    {
        $sql = preg_replace('/\s+/', ' ', trim($sql));
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
     * Check if a string is wrapped in parentheses
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
     * Parse WHERE clause
     */
    public function parseWhere(string $sql): ?string
    {
        $patterns = [
            '/\bWHERE\s+(.+?)(?:\s+GROUP\s+BY|\s+HAVING|\s+ORDER\s+BY|\s+LIMIT|\s+OFFSET|$)/i',
            '/\bWHERE\s+(.+?)(?:\s+ORDER\s+BY|\s+LIMIT|$)/i',
            '/\bWHERE\s+(.+?)$/i'
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $sql, $matches)) {
                return trim($matches[1]);
            }
        }

        return null;
    }

    /**
     * Split WHERE clause conditions
     */
    public function splitWhereConditions(string $whereClause): array
    {
        $whereClause = $this->normalizeSql($whereClause);
        $whereClause = $this->convertPositionalToNamedParams($whereClause);

        if ($this->isWrappedInParentheses($whereClause)) {
            return [['condition' => $whereClause, 'operator' => null]];
        }

        if (!$this->hasTopLevelLogicalOperators($whereClause)) {
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
                        $conditions[] = ['condition' => trim((string) $nextCondition['condition']), 'operator' => 'AND'];
                        $i += strlen((string) $nextCondition['condition']);
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

        if (trim($current) !== '') {
            $conditions[] = ['condition' => trim($current), 'operator' => null];
        }

        if (count($conditions) <= 1) {
            return [['condition' => $whereClause, 'operator' => null]];
        }

        return $conditions;
    }

    private function hasTopLevelLogicalOperators(string $whereClause): bool
    {
        $depth = 0;
        $inQuotes = false;
        $quoteChar = '';

        for ($i = 0; $i < strlen($whereClause); $i++) {
            $char = $whereClause[$i];

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
                } elseif ($depth === 0) {
                    $remaining = substr($whereClause, $i);
                    if (preg_match('/^\s*(AND|OR)\s+/i', $remaining)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

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
}