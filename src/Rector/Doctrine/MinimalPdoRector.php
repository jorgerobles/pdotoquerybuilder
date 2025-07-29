<?php

// src/Rector/Doctrine/MinimalPdoRector.php
declare(strict_types=1);

namespace App\Rector\Doctrine;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

final class MinimalPdoRector extends AbstractRector
{
    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Convierte fetchAll() a fetchAllAssociative()',
            [
                new CodeSample(
                    'return $stmt->fetchAll();',
                    'return $stmt->fetchAllAssociative();'
                ),
            ]
        );
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

        // Solo cambiar fetchAll por fetchAllAssociative
        if ($this->isName($node->name, 'fetchAll')) {
            return new MethodCall(
                $node->var,
                new Identifier('fetchAllAssociative')
            );
        }

        return null;
    }
}