<?php

declare(strict_types=1);

namespace App\Rector\Doctrine;

use PhpMyAdmin\SqlParser\Components\Expression as SqlExpression;
use PhpMyAdmin\SqlParser\Components\GroupKeyword;
use PhpMyAdmin\SqlParser\Components\Limit;
use PhpMyAdmin\SqlParser\Components\OrderKeyword;
use PhpMyAdmin\SqlParser\Parser;
use PhpMyAdmin\SqlParser\Statements\DeleteStatement;
use PhpMyAdmin\SqlParser\Statements\InsertStatement;
use PhpMyAdmin\SqlParser\Statements\SelectStatement;
use PhpMyAdmin\SqlParser\Statements\UpdateStatement;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayItem;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar\LNumber;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Return_;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

final class StepByStepPdoRector extends AbstractRector
{
    private bool $debugMode = true;
    private array $parameterMappings = [];
    private int $parameterCounter = 0;

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Converts any PDO SQL statement to Doctrine QueryBuilder using SQL parser',
            [
                new CodeSample(
                    <<<'CODE_SAMPLE'
                    $stmt = $pdo->prepare("SELECT u.name, u.email FROM users u WHERE u.age > ? AND u.status = ? ORDER BY u.name LIMIT 10");
                    $stmt->execute([25, 'active']);
                    return $stmt->fetchAll();
                    CODE_SAMPLE,
                    <<<'CODE_SAMPLE'
                    return $this->connection()->createQueryBuilder()
                        ->select('u.name, u.email')
                        ->from('users', 'u')
                        ->where('u.age > :param1')
                        ->andWhere('u.status = :param2')
                        ->orderBy('u.name', 'ASC')
                        ->setMaxResults(10)
                        ->setParameter('param1', 25)
                        ->setParameter('param2', 'active')
                        ->executeQuery()
                        ->fetchAllAssociative();
                    CODE_SAMPLE
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

        // Analyze the method for PDO patterns
        $pdoInfo = $this->analyzePdoPattern($node);
        if (!$pdoInfo) {
            return null;
        }

        $this->debug("Method contains PDO pattern - converting to QueryBuilder");
        $this->debug("SQL: " . $pdoInfo['sql']);
        $this->debug("Parameters: " . json_encode($pdoInfo['parameters']));

        // Parse SQL and convert to QueryBuilder
        $queryBuilder = $this->convertSqlToQueryBuilder($pdoInfo['sql'], $pdoInfo['parameters']);

        if (!$queryBuilder) {
            $this->debug("Failed to convert SQL to QueryBuilder");
            return null;
        }

        // Add parameter bindings
        $queryBuilder = $this->addParameterBindings($queryBuilder, $pdoInfo['parameters']);

        // Add fetch method based on the original fetch call
        $queryBuilder = $this->addFetchMethod($queryBuilder, $pdoInfo['fetchMethod']);

        // Replace the entire method body
        $node->stmts = [new Return_($queryBuilder)];

        return $node;
    }

    private function analyzePdoPattern(Node\Stmt\ClassMethod $method): ?array
    {
        if (!$method->stmts) {
            return null;
        }

        $sql = null;
        $parameters = [];
        $fetchMethod = 'fetchAllAssociative';
        $stmtVar = null;

        foreach ($method->stmts as $stmt) {
            // Look for prepare statement
            if ($stmt instanceof Expression && $stmt->expr instanceof Assign) {
                $assign = $stmt->expr;
                if ($assign->expr instanceof MethodCall && $this->isName($assign->expr->name, 'prepare')) {
                    $stmtVar = $assign->var;
                    if (isset($assign->expr->args[0]) && $assign->expr->args[0]->value instanceof String_) {
                        $sql = $assign->expr->args[0]->value->value;
                        $this->debug("Found prepare with SQL: $sql");
                    }
                }
            }

            // Look for query statement (direct execution)
            if ($stmt instanceof Expression && $stmt->expr instanceof MethodCall) {
                if ($this->isName($stmt->expr->name, 'query')) {
                    if (isset($stmt->expr->args[0]) && $stmt->expr->args[0]->value instanceof String_) {
                        $sql = $stmt->expr->args[0]->value->value;
                        $this->debug("Found query with SQL: $sql");
                    }
                }

                // Look for execute with parameters
                if ($this->isName($stmt->expr->name, 'execute') && $stmtVar) {
                    if (isset($stmt->expr->args[0])) {
                        $parameters = $this->extractParameters($stmt->expr->args[0]->value);
                        $this->debug("Found execute with parameters: " . json_encode($parameters));
                    }
                }
            }

            // Look for fetch method in return statement
            if ($stmt instanceof Return_ && $stmt->expr instanceof MethodCall) {
                $fetchMethod = $this->determineFetchMethod($stmt->expr);
                $this->debug("Found fetch method: $fetchMethod");
            }
        }

        if (!$sql) {
            return null;
        }

        return [
            'sql' => $sql,
            'parameters' => $parameters,
            'fetchMethod' => $fetchMethod
        ];
    }

    private function extractParameters($paramNode): array
    {
        if (!$paramNode instanceof Array_) {
            return [];
        }

        $parameters = [];
        foreach ($paramNode->items as $item) {
            if ($item instanceof ArrayItem && $item->value) {
                if ($item->value instanceof String_) {
                    $parameters[] = $item->value->value;
                } elseif ($item->value instanceof LNumber) {
                    $parameters[] = $item->value->value;
                }
            }
        }

        return $parameters;
    }

    private function determineFetchMethod(MethodCall $methodCall): string
    {
        $methodName = $this->getName($methodCall->name);

        switch ($methodName) {
            case 'fetch':
                return 'fetchAssociative';
            case 'fetchAll':
                return 'fetchAllAssociative';
            case 'fetchColumn':
                return 'fetchOne';
            case 'fetchObject':
                return 'fetchAssociative'; // Closest equivalent
            default:
                return 'fetchAllAssociative';
        }
    }

    private function convertSqlToQueryBuilder(string $sql, array $parameters): ?MethodCall
    {
        try {
            // Reset parameter counter for each conversion
            $this->parameterCounter = 0;
            $this->parameterMappings = [];

            // Parse SQL using phpmyadmin/sql-parser
            $parser = new Parser($sql);

            if (empty($parser->statements)) {
                $this->debug("No statements found in SQL");
                return null;
            }

            $statement = $parser->statements[0];
            $this->debug("Parsed statement type: " . get_class($statement));

            // Create base QueryBuilder
            $queryBuilder = $this->createBaseQueryBuilder();

            // Convert based on statement type
            if ($statement instanceof SelectStatement) {
                return $this->convertSelectStatement($queryBuilder, $statement);
            } elseif ($statement instanceof InsertStatement) {
                return $this->convertInsertStatement($queryBuilder, $statement);
            } elseif ($statement instanceof UpdateStatement) {
                return $this->convertUpdateStatement($queryBuilder, $statement);
            } elseif ($statement instanceof DeleteStatement) {
                return $this->convertDeleteStatement($queryBuilder, $statement);
            }

            return null;

        } catch (\Exception $e) {
            $this->debug("Error parsing SQL: " . $e->getMessage());
            return null;
        }
    }

    private function createBaseQueryBuilder(): MethodCall
    {
        return new MethodCall(
            new MethodCall(
                new Variable('this'),
                new Identifier('connection')
            ),
            new Identifier('createQueryBuilder')
        );
    }

    private function convertSelectStatement(MethodCall $queryBuilder, SelectStatement $statement): MethodCall
    {
        // SELECT clause
        if ($statement->expr) {
            $selectFields = $this->buildSelectFields($statement->expr);
            $queryBuilder = new MethodCall(
                $queryBuilder,
                new Identifier('select'),
                [new Arg(new String_($selectFields))]
            );
        }

        // FROM clause
        if ($statement->from) {
            $queryBuilder = $this->addFromClause($queryBuilder, $statement->from);
        }

        // JOIN clauses
        if ($statement->join) {
            $queryBuilder = $this->addJoinClauses($queryBuilder, $statement->join);
        }

        // WHERE clause
        if ($statement->where) {
            $queryBuilder = $this->addWhereClause($queryBuilder, $statement->where);
        }

        // GROUP BY clause
        if ($statement->group) {
            $queryBuilder = $this->addGroupByClause($queryBuilder, $statement->group);
        }

        // HAVING clause
        if ($statement->having) {
            $queryBuilder = $this->addHavingClause($queryBuilder, $statement->having);
        }

        // ORDER BY clause
        if ($statement->order) {
            $queryBuilder = $this->addOrderByClause($queryBuilder, $statement->order);
        }

        // LIMIT clause
        if ($statement->limit) {
            $queryBuilder = $this->addLimitClause($queryBuilder, $statement->limit);
        }

        return $queryBuilder;
    }

    private function convertInsertStatement(MethodCall $queryBuilder, InsertStatement $statement): MethodCall
    {
        // INSERT INTO table
        if ($statement->into && isset($statement->into->dest)) {
            $tableName = $this->extractTableNameFromExpression($statement->into->dest);
            $queryBuilder = new MethodCall(
                $queryBuilder,
                new Identifier('insert'),
                [new Arg(new String_($tableName))]
            );
        }

        // VALUES
        if ($statement->values) {
            $queryBuilder = $this->addInsertValues($queryBuilder, $statement);
        }

        return $queryBuilder;
    }

    private function convertUpdateStatement(MethodCall $queryBuilder, UpdateStatement $statement): MethodCall
    {
        // UPDATE table
        if ($statement->tables && count($statement->tables) > 0) {
            $tableName = $this->extractTableNameFromExpression($statement->tables[0]);
            $queryBuilder = new MethodCall(
                $queryBuilder,
                new Identifier('update'),
                [new Arg(new String_($tableName))]
            );
        }

        // SET clause
        if ($statement->set) {
            $queryBuilder = $this->addSetClause($queryBuilder, $statement->set);
        }

        // WHERE clause
        if ($statement->where) {
            $queryBuilder = $this->addWhereClause($queryBuilder, $statement->where);
        }

        return $queryBuilder;
    }

    private function convertDeleteStatement(MethodCall $queryBuilder, DeleteStatement $statement): MethodCall
    {
        // DELETE FROM table
        if ($statement->from && count($statement->from) > 0) {
            $tableName = $this->extractTableNameFromExpression($statement->from[0]);
            $queryBuilder = new MethodCall(
                $queryBuilder,
                new Identifier('delete'),
                [new Arg(new String_($tableName))]
            );
        }

        // WHERE clause
        if ($statement->where) {
            $queryBuilder = $this->addWhereClause($queryBuilder, $statement->where);
        }

        return $queryBuilder;
    }

    private function buildSelectFields(array $expressions): string
    {
        $fields = [];

        foreach ($expressions as $expr) {
            if ($expr instanceof SqlExpression) {
                $fieldStr = (string)$expr->expr;
                if ($expr->alias) {
                    $fieldStr .= ' AS ' . $expr->alias;
                }
                $fields[] = $fieldStr;
            }
        }

        return empty($fields) ? '*' : implode(', ', $fields);
    }

    private function addFromClause(MethodCall $queryBuilder, array $fromClause): MethodCall
    {
        foreach ($fromClause as $table) {
            $tableName = $this->extractTableNameFromExpression($table);
            $alias = $this->extractTableAliasFromExpression($table) ?: $tableName;

            $queryBuilder = new MethodCall(
                $queryBuilder,
                new Identifier('from'),
                [
                    new Arg(new String_($tableName)),
                    new Arg(new String_($alias))
                ]
            );
            break; // Handle first table, others should be JOINs
        }

        return $queryBuilder;
    }

    private function addJoinClauses(MethodCall $queryBuilder, array $joinClause): MethodCall
    {
        foreach ($joinClause as $join) {
            $joinType = $join->type ?? 'JOIN';
            $tableName = $this->extractTableNameFromExpression($join->expr);
            $alias = $this->extractTableAliasFromExpression($join->expr) ?: $tableName;

            // Determine QueryBuilder method based on join type
            $method = match (strtoupper($joinType)) {
                'LEFT JOIN', 'LEFT OUTER JOIN' => 'leftJoin',
                'RIGHT JOIN', 'RIGHT OUTER JOIN' => 'rightJoin',
                'INNER JOIN' => 'innerJoin',
                default => 'join'
            };

            $condition = $this->buildConditionString($join->on);

            $queryBuilder = new MethodCall(
                $queryBuilder,
                new Identifier($method),
                [
                    new Arg(new String_($tableName)),
                    new Arg(new String_($alias)),
                    new Arg(new String_($condition))
                ]
            );
        }

        return $queryBuilder;
    }

    private function addWhereClause(MethodCall $queryBuilder, array $whereClause): MethodCall
    {
        $isFirst = true;

        foreach ($whereClause as $condition) {
            $conditionStr = $this->buildConditionString($condition);
            $conditionStr = $this->convertParameterPlaceholders($conditionStr);

            $method = $isFirst ? 'where' : 'andWhere';

            // Handle OR conditions (simplified - would need more complex logic for real OR detection)
            if ($this->isOrCondition($condition)) {
                $method = 'orWhere';
            }

            $queryBuilder = new MethodCall(
                $queryBuilder,
                new Identifier($method),
                [new Arg(new String_($conditionStr))]
            );

            $isFirst = false;
        }

        return $queryBuilder;
    }

    private function addGroupByClause(MethodCall $queryBuilder, array $groupByClause): MethodCall
    {
        foreach ($groupByClause as $group) {
            if ($group instanceof GroupKeyword) {
                $expr = (string)$group->expr;
                $queryBuilder = new MethodCall(
                    $queryBuilder,
                    new Identifier('addGroupBy'),
                    [new Arg(new String_($expr))]
                );
            }
        }

        return $queryBuilder;
    }

    private function addHavingClause(MethodCall $queryBuilder, array $havingClause): MethodCall
    {
        if (count($havingClause) > 0) {
            $conditionStr = $this->buildConditionString($havingClause[0]);
            $conditionStr = $this->convertParameterPlaceholders($conditionStr);

            $queryBuilder = new MethodCall(
                $queryBuilder,
                new Identifier('having'),
                [new Arg(new String_($conditionStr))]
            );
        }

        return $queryBuilder;
    }

    private function addOrderByClause(MethodCall $queryBuilder, array $orderByClause): MethodCall
    {
        foreach ($orderByClause as $order) {
            if ($order instanceof OrderKeyword) {
                $field = (string)$order->expr;
                $direction = strtoupper($order->type ?? 'ASC');

                $queryBuilder = new MethodCall(
                    $queryBuilder,
                    new Identifier('addOrderBy'),
                    [
                        new Arg(new String_($field)),
                        new Arg(new String_($direction))
                    ]
                );
            }
        }

        return $queryBuilder;
    }

    private function addLimitClause(MethodCall $queryBuilder, Limit $limitClause): MethodCall
    {
        if ($limitClause->rowCount !== null) {
            $queryBuilder = new MethodCall(
                $queryBuilder,
                new Identifier('setMaxResults'),
                [new Arg(new LNumber((int)$limitClause->rowCount))]
            );
        }

        if ($limitClause->offset !== null) {
            $queryBuilder = new MethodCall(
                $queryBuilder,
                new Identifier('setFirstResult'),
                [new Arg(new LNumber((int)$limitClause->offset))]
            );
        }

        return $queryBuilder;
    }

    private function addInsertValues(MethodCall $queryBuilder, InsertStatement $statement): MethodCall
    {
        if ($statement->columns && $statement->values) {
            $columns = $statement->columns;

            foreach ($columns as $index => $column) {
                $columnName = (string)$column;
                $value = $this->convertParameterPlaceholders('?'); // Use placeholder, will be replaced with named parameter

                $queryBuilder = new MethodCall(
                    $queryBuilder,
                    new Identifier('setValue'),
                    [
                        new Arg(new String_($columnName)),
                        new Arg(new String_($value))
                    ]
                );
            }
        }

        return $queryBuilder;
    }

    private function addSetClause(MethodCall $queryBuilder, array $setClause): MethodCall
    {
        foreach ($setClause as $assignment) {
            $column = (string)$assignment->column;
            $value = $this->convertParameterPlaceholders((string)$assignment->value);

            $queryBuilder = new MethodCall(
                $queryBuilder,
                new Identifier('set'),
                [
                    new Arg(new String_($column)),
                    new Arg(new String_($value))
                ]
            );
        }

        return $queryBuilder;
    }

    private function extractTableNameFromExpression($expression): string
    {
        if ($expression instanceof SqlExpression) {
            return (string)$expression->table ?: (string)$expression->expr;
        }

        return (string)$expression;
    }

    private function extractTableAliasFromExpression($expression): ?string
    {
        if ($expression instanceof SqlExpression && $expression->alias) {
            return (string)$expression->alias;
        }

        return null;
    }

    private function buildConditionString($condition): string
    {
        return (string)$condition;
    }

    private function convertParameterPlaceholders(string $condition): string
    {
        return preg_replace_callback('/\?/', function ($matches) {
            $this->parameterCounter++;
            $paramName = "param{$this->parameterCounter}";
            $this->parameterMappings[] = $paramName;
            return ":$paramName";
        }, $condition);
    }

    private function isOrCondition($condition): bool
    {
        // Simplified OR detection - in a real implementation, this would need
        // to properly parse the condition structure
        $conditionStr = (string)$condition;
        return str_contains(strtoupper($conditionStr), ' OR ');
    }

    private function addParameterBindings(MethodCall $queryBuilder, array $parameters): MethodCall
    {
        foreach ($parameters as $index => $value) {
            if (isset($this->parameterMappings[$index])) {
                $paramName = $this->parameterMappings[$index];

                $queryBuilder = new MethodCall(
                    $queryBuilder,
                    new Identifier('setParameter'),
                    [
                        new Arg(new String_($paramName)),
                        new Arg($this->createValueNode($value))
                    ]
                );
            }
        }

        return $queryBuilder;
    }

    private function addFetchMethod(MethodCall $queryBuilder, string $fetchMethod): MethodCall
    {
        // Add executeQuery first
        $queryBuilder = new MethodCall(
            $queryBuilder,
            new Identifier('executeQuery')
        );

        // Then add the fetch method
        return new MethodCall(
            $queryBuilder,
            new Identifier($fetchMethod)
        );
    }

    private function createValueNode($value): Node
    {
        if (is_string($value)) {
            return new String_($value);
        } elseif (is_int($value)) {
            return new LNumber($value);
        } elseif (is_float($value)) {
            return new Node\Scalar\DNumber($value);
        } elseif (is_bool($value)) {
            return $value ? new Node\Expr\ConstFetch(new Node\Name('true'))
                : new Node\Expr\ConstFetch(new Node\Name('false'));
        }

        // Default to string
        return new String_((string)$value);
    }

    private function debug(string $message): void
    {
        if ($this->debugMode) {
            error_log("StepByStepPdoRector: $message");
        }
    }
}