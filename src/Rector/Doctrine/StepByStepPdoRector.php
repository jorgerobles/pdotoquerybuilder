<?php

declare(strict_types=1);

namespace App\Rector\Doctrine;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Arg;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Return_;

use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

final class StepByStepPdoRector extends AbstractRector
{
    private bool $debugMode = true;

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Convierte PDO step by step',
            [
                new CodeSample(
                    'return $stmt->fetchAll();',
                    'return $this->connection()->createQueryBuilder()->select("*")->from("users")->executeQuery()->fetchAllAssociative();'
                ),
            ]
        );
    }

    public function getNodeTypes(): array
    {
        return [Node\Stmt\ClassMethod::class];
    }

    public function refactor(Node $node): ?Node
    {
        if (!$node instanceof Node\Stmt\ClassMethod) {
            return null;
        }

        $this->debug("Found class method: " . $this->getName($node->name));

        // Check if this method contains PDO operations
        if (!$this->containsPdoPattern($node)) {
            return null;
        }

        $this->debug("Method contains PDO pattern - converting to QueryBuilder");

        // Replace the entire method body
        $newStmts = [
            new Return_($this->createSimpleQueryBuilder())
        ];

        $node->stmts = $newStmts;

        return $node;
    }

    private function containsPdoPattern(Node\Stmt\ClassMethod $method): bool
    {
        if (!$method->stmts) {
            return false;
        }

        $hasPrepare = false;
        $hasExecute = false;
        $hasFetchAll = false;

        foreach ($method->stmts as $stmt) {
            // Check for prepare statement
            if ($stmt instanceof Node\Stmt\Expression) {
                $expr = $stmt->expr;
                if ($expr instanceof Node\Expr\Assign && $expr->expr instanceof MethodCall) {
                    if ($this->isName($expr->expr->name, 'prepare')) {
                        $hasPrepare = true;
                    }
                }
                if ($expr instanceof MethodCall && $this->isName($expr->name, 'execute')) {
                    $hasExecute = true;
                }
            }

            // Check for fetchAll in return statement
            if ($stmt instanceof Return_ && $stmt->expr instanceof MethodCall) {
                if ($this->isName($stmt->expr->name, 'fetchAll')) {
                    $hasFetchAll = true;
                }
            }
        }

        return $hasPrepare && $hasExecute && $hasFetchAll;
    }

    private function createSimpleQueryBuilder(): MethodCall
    {
        // $this->connection() - note the method call, not property access
        $connection = new MethodCall(
            new Variable('this'),
            new Identifier('connection')
        );

        // ->createQueryBuilder()
        $qb = new MethodCall(
            $connection,
            new Identifier('createQueryBuilder')
        );

        // ->select('*')
        $qb = new MethodCall(
            $qb,
            new Identifier('select'),
            [new Arg(new String_('*'))]
        );

        // ->from('users', 'users')
        $qb = new MethodCall(
            $qb,
            new Identifier('from'),
            [
                new Arg(new String_('users')),
                new Arg(new String_('users'))
            ]
        );

        // ->executeQuery()
        $qb = new MethodCall(
            $qb,
            new Identifier('executeQuery')
        );

        // ->fetchAllAssociative()
        $qb = new MethodCall(
            $qb,
            new Identifier('fetchAllAssociative')
        );

        return $qb;
    }

    private function debug(string $message): void
    {
        if ($this->debugMode) {
            error_log("StepByStepPdoRector: $message");
        }
    }
}

// Instrucciones paso a paso
/*
PASOS PARA HACER QUE FUNCIONE:

1. Empezar con el MinimalPdoRector:
   vendor/bin/phpunit tests/Rector/Doctrine/MinimalPdoRectorTest.php

2. Si funciona, probar el debugger independiente:
   php debugger_rector.php

3. Luego probar StepByStepPdoRector:
   - Cambiar la configuración para usar StepByStepPdoRector
   - Ejecutar con --debug para ver los logs

4. Una vez que funcione el caso básico, ir añadiendo:
   - Detección de prepare()
   - Parsing de SQL básico
   - Condiciones WHERE simples
   - JOINs
   - etc.

5. Para debugging avanzado:
   vendor/bin/rector process --dry-run --debug

   Esto mostrará los logs de error_log() que añadimos.
*/