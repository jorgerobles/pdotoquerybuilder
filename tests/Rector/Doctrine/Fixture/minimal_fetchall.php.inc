<?php

namespace Tests\Rector\Doctrine\Fixture;

class TestRepository
{
    private $pdo;

    public function getUsers()
    {
        $stmt = $this->pdo->prepare("SELECT * FROM users");
        $stmt->execute();
        return $stmt->fetchAll();
    }
}

?>
-----
<?php

namespace Tests\Rector\Doctrine\Fixture;

class TestRepository
{
    private $pdo;

    public function getUsers()
    {
        return $this->connection()->createQueryBuilder()->select('*')->from('users', 'users')->executeQuery()->fetchAllAssociative();
    }
}

?>