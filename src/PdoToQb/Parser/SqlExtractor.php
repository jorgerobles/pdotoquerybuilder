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
 * Extracts SQL strings from different PHP node types
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

        // Handle string concatenation (basic support)
        if ($node instanceof Concat) {
            return $this->extractFromConcat($node);
        }

        // Log unsupported node types for debugging
        error_log("SqlExtractor: Unsupported SQL node type: " . $node::class);
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
                error_log("SqlExtractor: Unsupported encapsed part type: " . $part::class);
                return null;
            }
        }

        return $sql;
    }

    private function extractFromConcat(Concat $node): ?string
    {
        $left = $this->extractSqlFromNode($node->left);
        $right = $this->extractSqlFromNode($node->right);

        if ($left !== null && $right !== null) {
            return $left . $right;
        }

        return null;
    }
}