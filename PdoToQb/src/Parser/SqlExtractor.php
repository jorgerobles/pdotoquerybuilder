<?php

declare(strict_types=1);

namespace JDR\Rector\PdoToQb\Parser;

use PhpParser\Node;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Scalar\Encapsed;
use PhpParser\Node\Scalar\EncapsedStringPart;
use PhpParser\Node\Scalar\String_;

/**
 * FIXED: Extracts SQL strings from different PHP node types with improved concatenation handling
 */
class SqlExtractor
{
    /**
     * Extract SQL string from different node types (String_, Encapsed, etc.)
     */
    public function extractSqlFromNode(Node $node): ?string
    {
        // Handle regular string literals and heredocs/nowdocs
        if ($node instanceof String_) {
            return $node->value;
        }

        // Handle interpolated strings (heredocs with variables)
        if ($node instanceof Encapsed) {
            return $this->extractFromEncapsed($node);
        }

        // Handle string concatenation (IMPROVED for complex multi-line concatenation)
        if ($node instanceof Concat) {
            return $this->extractFromConcat($node);
        }

        // Log unsupported node types for debugging
        error_log("SqlExtractor: Unsupported SQL node type: " . get_class($node));
        return null;
    }

    private function extractFromEncapsed(Encapsed $node): ?string
    {
        // For now, we'll try to extract the SQL by concatenating string parts
        // This is a simplified approach - in a real scenario you might want more sophisticated handling
        $sql = '';

        foreach ($node->parts as $part) {
            if ($part instanceof EncapsedStringPart) {
                $sql .= $part->value;
            } elseif ($part instanceof Variable) {
                // Replace variables with placeholders - this is a basic approach
                $sql .= '?';
            } else {
                // For other expressions, we can't easily convert - return null
                error_log("SqlExtractor: Unsupported encapsed part type: " . get_class($part));
                return null;
            }
        }

        return $sql;
    }

    /**
     * COMPLETELY REWRITTEN: Handle complex multi-level string concatenation
     */
    private function extractFromConcat(Concat $node): ?string
    {
        return $this->extractConcatenationRecursively($node);
    }

    /**
     * NEW: Recursively extract concatenated strings
     */
    private function extractConcatenationRecursively(Node $node): ?string
    {
        // Base case: simple string
        if ($node instanceof String_) {
            return $node->value;
        }

        // Base case: encapsed string (heredocs, etc.)
        if ($node instanceof Encapsed) {
            return $this->extractFromEncapsed($node);
        }

        // Recursive case: concatenation
        if ($node instanceof Concat) {
            $left = $this->extractConcatenationRecursively($node->left);
            $right = $this->extractConcatenationRecursively($node->right);

            // If either side fails, the whole extraction fails
            if ($left === null || $right === null) {
                return null;
            }

            return $left . $right;
        }

        // Variables and other expressions - we can't extract these
        if ($node instanceof Variable) {
            error_log("SqlExtractor: Found variable in SQL concatenation - cannot extract static SQL");
            return null;
        }

        // Unknown node type
        error_log("SqlExtractor: Unknown node type in concatenation: " . get_class($node));
        return null;
    }

    /**
     * NEW: Debug helper to understand node structure
     */
    public function debugNode(Node $node, int $depth = 0): string
    {
        $indent = str_repeat('  ', $depth);
        $result = $indent . get_class($node) . "\n";

        if ($node instanceof String_) {
            $result .= $indent . "  value: " . json_encode($node->value) . "\n";
        }

        if ($node instanceof Concat) {
            $result .= $indent . "  left:\n" . $this->debugNode($node->left, $depth + 1);
            $result .= $indent . "  right:\n" . $this->debugNode($node->right, $depth + 1);
        }

        if ($node instanceof Encapsed) {
            $result .= $indent . "  parts: " . count($node->parts) . "\n";
            foreach ($node->parts as $i => $part) {
                $result .= $indent . "  part[$i]:\n" . $this->debugNode($part, $depth + 1);
            }
        }

        return $result;
    }
}