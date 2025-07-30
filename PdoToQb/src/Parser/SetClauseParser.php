<?php
declare(strict_types=1);

namespace JDR\Rector\PdoToQb\Parser;

/**
 * Parser for SET clauses used in UPDATE and INSERT queries
 */
class SetClauseParser
{
    private CommonSqlParser $commonParser;

    public function __construct(CommonSqlParser $commonParser = null)
    {
        $this->commonParser = $commonParser ?? new CommonSqlParser();
    }

    /**
     * Parse SET clause into column-value pairs
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

        $column = $this->cleanColumnName($column);
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
        if (strpos($column, '.') !== false) {
            $columnParts = explode('.', $column);
            $column = end($columnParts);
        }

        return trim($column, " \t\n\r\0\x0B`\"'");
    }

    /**
     * Normalize value by converting positional parameters
     */
    private function normalizeValue(string $value): string
    {
        $value = trim($value);

        if ($value === '?') {
            return $this->getNextNamedParameter();
        }

        return $this->commonParser->convertPositionalToNamedParams($value);
    }

    /**
     * Get next named parameter (:param1, :param2, etc.)
     */
    private function getNextNamedParameter(): string
    {
        return $this->commonParser->convertPositionalToNamedParams('?');
    }

    /**
     * Parse column list from INSERT query
     */
    public function parseColumnList(string $columnList): array
    {
        $columnList = trim($columnList, " \t\n\r\0\x0B()");
        $columns = $this->commonParser->splitRespectingDelimiters($columnList, ',');
        return array_map([$this, 'cleanColumnName'], $columns);
    }

    /**
     * Parse value list from INSERT query
     */
    public function parseValueList(string $valueList): array
    {
        $valueList = trim($valueList, " \t\n\r\0\x0B()");
        $values = $this->commonParser->splitRespectingDelimiters($valueList, ',');

        return array_map(fn($value): string => $this->normalizeValue($value), $values);
    }
}