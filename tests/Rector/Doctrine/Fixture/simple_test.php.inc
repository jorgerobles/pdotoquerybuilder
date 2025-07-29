<?php

namespace Tests\Rector\Doctrine\Fixture;

class SimpleRepository
{
    private $pdo;

    public function findUsers(): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE status = 'active'");
        $stmt->execute();
        return $stmt->fetchAll();
    }
}

?>
-----
<?php

namespace Tests\Rector\Doctrine\Fixture;

class SimpleRepository
{
    private $connection;

    public function findUsers(): array
    {
        return $this->connection->createQueryBuilder()
            ->select('*')
            ->from('users', 'users')
            ->where('status = \'active\'')
            ->executeQuery()
            ->fetchAllAssociative();
    }
}

?>