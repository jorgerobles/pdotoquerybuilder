<?php

declare(strict_types=1);

namespace App\Rector\Doctrine\Parser;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar\String_;

/**
 * Parses and builds WHERE clauses for QueryBuilder
 */
class WhereClauseParser
{
    public function buildWhereClause(MethodCall $queryBuilder, string $whereClause): MethodCall
    {
        // Normalize the WHERE clause first
        $whereClause = $this->normalizeWhereClause($whereClause);

        // Split top-level AND/OR conditions (not inside parentheses)
        $conditions = $this->splitTopLevelWhereConditions($whereClause);

        // Apply the first condition with where()
        if ($conditions !== []) {
            $firstCondition = array_shift($conditions);
            $queryBuilder = new MethodCall(
                $queryBuilder,
                new Identifier('where'),
                [new Arg(new String_($firstCondition['condition']))]
            );

            // Apply remaining conditions with andWhere() or orWhere()
            foreach ($conditions as $condition) {
                $method = $condition['operator'] === 'OR' ? 'orWhere' : 'andWhere';
                $queryBuilder = new MethodCall(
                    $queryBuilder,
                    new Identifier($method),
                    [new Arg(new String_($condition['condition']))]
                );
            }
        }

        return $queryBuilder;
    }

    private function splitTopLevelWhereConditions(string $where): array
    {
        // If the entire WHERE clause is wrapped in parentheses, treat it as one condition
        $trimmed = trim($where);
        if ($this->isWrappedInParentheses($trimmed)) {
            return [['condition' => $trimmed, 'operator' => null]];
        }

        // Split by top-level AND/OR operators (not inside parentheses)
        $conditions = [];
        $current = '';
        $depth = 0;
        $inQuotes = false;
        $quoteChar = '';
        $i = 0;

        while ($i < strlen($where)) {
            $char = $where[$i];

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
                $remaining = substr($where, $i);

                // Check for AND operator
                if (preg_match('/^\s*AND\s+/i', $remaining, $matches)) {
                    if (trim($current) !== '') {
                        $conditions[] = ['condition' => trim($current), 'operator' => null];
                        $current = '';
                    }
                    $i += strlen($matches[0]);
                    // Next condition will be added with AND operator
                    $nextCondition = $this->extractNextCondition(substr($where, $i));
                    if ($nextCondition) {
                        $conditions[] = ['condition' => trim($nextCondition['condition']), 'operator' => 'AND'];
                        $i += strlen($nextCondition['condition']);
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
                    // Next condition will be added with OR operator
                    $nextCondition = $this->extractNextCondition(substr($where, $i));
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

        // Add the final condition
        if (trim($current) !== '') {
            $conditions[] = ['condition' => trim($current), 'operator' => null];
        }

        // If no splitting occurred, return the original as one condition
        if (count($conditions) <= 1) {
            return [['condition' => $where, 'operator' => null]];
        }

        return $conditions;
    }

    private function extractNextCondition(string $remaining): ?array
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

    private function isWrappedInParentheses(string $str): bool
    {
        $str = trim($str);
        if (strlen($str) < 2 || $str[0] !== '(' || $str[strlen($str) - 1] !== ')') {
            return false;
        }

        // Check if the parentheses are balanced and the entire string is wrapped
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

    private function normalizeWhereClause(string $where): string
    {
        // Convert positional parameters to named ones
        $paramCount = 0;
        $where = preg_replace_callback('/\?/', function ($matches) use (&$paramCount): string {
            $paramCount++;
            return ":param$paramCount";
        }, $where);

        // Clean up and normalize whitespace
        $where = preg_replace('/\s+/', ' ', trim($where));

        // Fix common SQL issues
        $where = str_replace(' AND NULL', '', $where);
        $where = str_replace('IS AND', 'IS NOT', $where);

        return $where;
    }
}