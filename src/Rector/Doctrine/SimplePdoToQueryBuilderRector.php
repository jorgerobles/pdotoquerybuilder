<?php

declare(strict_types=1);

namespace App\Rector\Doctrine;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Return_;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

final class SimplePdoToQueryBuilderRector extends AbstractRector
{
    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Convierte PDO fetchAll a QueryBuilder',
            [
                new CodeSample(
                    'return $stmt->fetchAll();',
                    'return $this->connection->createQueryBuilder()->select("*")->from("users")->executeQuery()->fetchAllAssociative();'
                ),
            ]
        );
    }

    public function getNodeTypes(): array
    {
        return [Return_::class];
    }

    public function refactor(Node $node): ?Node
    {
        if (!$node instanceof Return_) {
            return null;
        }

        if (!$node->expr instanceof MethodCall) {
            return null;
        }

        $methodCall = $node->expr;

        // Verificar si es fetchAll()
        if (!$this->isName($methodCall->name, 'fetchAll')) {
            return null;
        }

        // Crear QueryBuilder simple
        $queryBuilder = new MethodCall(
            new MethodCall(
                new Variable('this'),
                new Identifier('connection')
            ),
            new Identifier('createQueryBuilder')
        );

        $queryBuilder = new MethodCall(
            $queryBuilder,
            new Identifier('select'),
            [new Arg(new String_('*'))]
        );

        $queryBuilder = new MethodCall(
            $queryBuilder,
            new Identifier('from'),
            [
                new Arg(new String_('users')),
                new Arg(new String_('users'))
            ]
        );

        $queryBuilder = new MethodCall(
            $queryBuilder,
            new Identifier('where'),
            [new Arg(new String_('status = \'active\''))]
        );

        $executeQuery = new MethodCall($queryBuilder, new Identifier('executeQuery'));
        $finalMethod = new MethodCall($executeQuery, new Identifier('fetchAllAssociative'));

        return new Return_($finalMethod);
    }
}