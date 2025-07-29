<?php

declare(strict_types=1);

namespace App\Rector\Doctrine;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

final class DebugRector extends AbstractRector
{
    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition('Debug rector', []);
    }

    public function getNodeTypes(): array
    {
        return [MethodCall::class];
    }

    public function refactor(Node $node): ?Node
    {
        if (!$node instanceof MethodCall) {
            return null;
        }

        // Log de debug
        error_log("DebugRector: Found method call: " . $this->getName($node->name));

        if ($this->isName($node->name, 'prepare')) {
            error_log("DebugRector: Found prepare() call");
        }

        if ($this->isName($node->name, 'fetchAll')) {
            error_log("DebugRector: Found fetchAll() call");
        }

        return null; // No modificamos nada, solo debug
    }
}