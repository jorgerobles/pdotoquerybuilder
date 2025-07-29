<?php

namespace Tests\Rector\Doctrine\Fixture;

class ComplexQueryRepository
{
    private $pdo;

    public function findWithOrConditions(): array
    {
        $stmt = $this->pdo->prepare("
            SELECT * FROM users 
            WHERE (status = 'active' OR status = 'pending') 
            AND age > 18
        ");
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function findWithNotCondition(): array
    {
        $stmt = $this->pdo->prepare("
            SELECT * FROM products 
            WHERE NOT category_id = ? 
            AND price > 100
        ");
        $stmt->execute([5]);
        return $stmt->fetchAll();
    }

    public function findWithComplexLogic(): array
    {
        $stmt = $this->pdo->prepare("
            SELECT * FROM orders 
            WHERE (status = 'completed' OR status = 'shipped') 
            AND NOT (payment_method = 'credit' AND amount > 1000)
            AND created_at > ?
        ");
        $stmt->execute(['2024-01-01']);
        return $stmt->fetchAll();
    }

    public function findWithNestedParentheses(): array
    {
        $stmt = $this->pdo->prepare("
            SELECT u.*, p.title 
            FROM users u
            LEFT JOIN profiles p ON u.id = p.user_id
            WHERE (
                (u.department = 'IT' AND u.level > 3) 
                OR (u.department = 'Sales' AND u.commission > 0.1)
            ) 
            AND NOT (u.status = 'inactive' OR u.terminated = 1)
            AND (p.public = 1 OR p.public IS NULL)
        ");
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function findWithMixedOperators(): array
    {
        $stmt = $this->pdo->prepare("
            SELECT * FROM events 
            WHERE (
                (type = 'conference' AND capacity > 100) 
                OR (type = 'workshop' AND duration > 4)
                OR (type = 'webinar' AND platform = 'zoom')
            )
            AND NOT cancelled = 1
            AND (start_date > ? OR priority = 'high')
            AND venue_id IS NOT NULL
        ");
        $stmt->execute(['2024-06-01']);
        return $stmt->fetchAll();
    }

    public function findWithDeeplyNestedConditions(): array
    {
        $stmt = $this->pdo->prepare("
            SELECT t.*, c.name as category_name
            FROM tasks t
            JOIN categories c ON t.category_id = c.id
            WHERE (
                (
                    (t.priority = 'high' AND t.estimated_hours <= 8)
                    OR (t.priority = 'medium' AND t.estimated_hours <= 16)
                )
                AND NOT (t.status = 'blocked' OR t.status = 'cancelled')
            )
            OR (
                t.urgent = 1 
                AND NOT t.assigned_to IS NULL
                AND (t.due_date < NOW() OR t.escalated = 1)
            )
            AND c.active = 1
        ");
        $stmt->execute();
        return $stmt->fetchAll();
    }
}

?>
    -----
<?php

namespace Tests\Rector\Doctrine\Fixture;

class ComplexQueryRepository
{
    private $connection;

    public function findWithOrConditions(): array
    {
        return $this->connection->createQueryBuilder()
            ->select('*')
            ->from('users', 'users')
            ->where('(status = \'active\' OR status = \'pending\')')
            ->andWhere('age > 18')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    public function findWithNotCondition(): array
    {
        return $this->connection->createQueryBuilder()
            ->select('*')
            ->from('products', 'products')
            ->where('NOT (category_id = :param1)')
            ->andWhere('price > 100')
            ->setParameter('param1', 5)
            ->executeQuery()
            ->fetchAllAssociative();
    }

    public function findWithComplexLogic(): array
    {
        return $this->connection->createQueryBuilder()
            ->select('*')
            ->from('orders', 'orders')
            ->where('(status = \'completed\' OR status = \'shipped\')')
            ->andWhere('NOT (payment_method = \'credit\' AND amount > 1000)')
            ->andWhere('created_at > :param1')
            ->setParameter('param1', '2024-01-01')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    public function findWithNestedParentheses(): array
    {
        return $this->connection->createQueryBuilder()
            ->select('u.*, p.title')
            ->from('users', 'u')
            ->leftJoin('u', 'profiles', 'p', 'u.id = p.user_id')
            ->where('((u.department = \'IT\' AND u.level > 3) OR (u.department = \'Sales\' AND u.commission > 0.1))')
            ->andWhere('NOT (u.status = \'inactive\' OR u.terminated = 1)')
            ->andWhere('(p.public = 1 OR p.public IS NULL)')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    public function findWithMixedOperators(): array
    {
        return $this->connection->createQueryBuilder()
            ->select('*')
            ->from('events', 'events')
            ->where('((type = \'conference\' AND capacity > 100) OR (type = \'workshop\' AND duration > 4) OR (type = \'webinar\' AND platform = \'zoom\'))')
            ->andWhere('NOT (cancelled = 1)')
            ->andWhere('(start_date > :param1 OR priority = \'high\')')
            ->andWhere('venue_id IS NOT NULL')
            ->setParameter('param1', '2024-06-01')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    public function findWithDeeplyNestedConditions(): array
    {
        return $this->connection->createQueryBuilder()
            ->select('t.*, c.name as category_name')
            ->from('tasks', 't')
            ->innerJoin('t', 'categories', 'c', 't.category_id = c.id')
            ->where('(((t.priority = \'high\' AND t.estimated_hours <= 8) OR (t.priority = \'medium\' AND t.estimated_hours <= 16)) AND NOT (t.status = \'blocked\' OR t.status = \'cancelled\'))')
            ->orWhere('(t.urgent = 1 AND NOT (t.assigned_to IS NULL) AND (t.due_date < NOW() OR t.escalated = 1))')
            ->andWhere('c.active = 1')
            ->executeQuery()
            ->fetchAllAssociative();
    }
}

?>