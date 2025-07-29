<?php

declare(strict_types=1);

namespace JDR\Rector\PdoToQb\Parser;

/**
 * Parser for SET clauses used in UPDATE and INSERT queries
 */
class SetClauseParser
{
    /**
     * @readonly
     */
    private CommonSqlParser $commonParser;

    public function __construct(?CommonSqlParser $commonParser = null)
    {
        $this->commonParser = $commonParser ?? new CommonSqlParser();
    }

    /**
     * Parse SET clause into column-value pairs
     * Handles: column = value, table.column = value, column = function(args), etc.
     */
    public function parseSetClause(string $setClause): array
    {
        $pairs = [];
        $setParts = $this->commonParser->splitRespectingDelimiters($setClause, ',');

        foreach ($setParts as $part) {
            $pair = $this->parseSetPair($part);
            if ($pair !== null) {
                $pairs[] = $pair;
            }
        }

        return $pairs;
    }

    /**
     * Parse individual SET pair (column = value)
     */
    private function parseSetPair(string $pair): ?array
    {
        $equalPos = $this->commonParser->findTopLevelPosition($pair, '=');
        if ($equalPos === false) {
            return null;
        }

        $column = trim(substr($pair, 0, $equalPos));
        $value = trim(substr($pair, $equalPos + 1));

        // Clean column name (remove table prefix if present, quotes, etc.)
        $column = $this->cleanColumnName($column);

        // Convert positional parameters to named parameters
        $value = $this->normalizeValue($value);

        return [
            'column' => $column,
            'value' => $value
        ];
    }

    /**
     * Clean column name by removing table prefixes and quotes
     */
    private function cleanColumnName(string $column): string
    {
        // Remove table prefix if present
        if (strpos($column, '.') !== false) {
            $columnParts = explode('.', $column);
            $column = end($columnParts);
        }

        // Remove quotes
        return trim($column, " \t\n\r\0\x0B`\"'");
    }

    /**
     * Normalize value by converting positional parameters
     */
    private function normalizeValue(string $value): string
    {
        $value = trim($value);

        // Convert single positional parameter
        if ($value === '?') {
            return $this->getNextNamedParameter();
        }

        // Convert multiple positional parameters in the value
        return $this->commonParser->convertPositionalToNamedParams($value);
    }

    /**
     * Get next named parameter (:param1, :param2, etc.)
     * Uses the same counter as CommonSqlParser for consistency
     */
    private function getNextNamedParameter(): string
    {
        // Use CommonSqlParser's parameter conversion to maintain consistency
        // Convert a single ? to get the next parameter name
        return $this->commonParser->convertPositionalToNamedParams('?');
    }

    /**
     * Parse column list from INSERT query
     * Handles: (col1, col2, col3) or col1, col2, col3
     */
    public function parseColumnList(string $columnList): array
    {
        // Remove surrounding parentheses if present
        $columnList = trim($columnList, " \t\n\r\0\x0B()");

        $columns = $this->commonParser->splitRespectingDelimiters($columnList, ',');

        // Clean column names
        return array_map([$this, 'cleanColumnName'], $columns);
    }

    /**
     * Parse value list from INSERT query
     * Handles: (val1, val2, val3) or val1, val2, val3
     */
    public function parseValueList(string $valueList): array
    {
        // Remove surrounding parentheses if present
        $valueList = trim($valueList, " \t\n\r\0\x0B()");

        $values = $this->commonParser->splitRespectingDelimiters($valueList, ',');

        // Normalize values
        return array_map(fn($value): string => $this->normalizeValue($value), $values);
    }
}