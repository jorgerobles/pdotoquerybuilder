<?php

namespace Tests\Rector\Doctrine\Fixture;

class SimpleSelectExample
{
    public function getUsers()
    {
        $stmt = $pdo->prepare("SELECT id, name, email FROM users WHERE status = ? ORDER BY name");
        $stmt->execute(['active']);
        return $stmt->fetchAll();
    }
}

?>
-----
<?php

namespace Tests\Rector\Doctrine\Fixture;

class SimpleSelectExample
{
    public function getUsers()
    {
        return $this->connection()->createQueryBuilder()
            ->select('id, name, email')
            ->from('users', 'users')
            ->where('status = :param1')
            ->addOrderBy('name', 'ASC')
            ->setParameter('param1', 'active')
            ->executeQuery()
            ->fetchAllAssociative();
    }
}

?>