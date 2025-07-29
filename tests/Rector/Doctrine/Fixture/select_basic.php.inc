<?php

namespace App;

class UserRepository
{
    public function findActiveUsers($pdo)
    {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE active = ?");
        $stmt->execute([1]);
        return $stmt->fetchAll();
    }
}

?>
-----
<?php

namespace App;

class UserRepository
{
    public function findActiveUsers($pdo)
    {
        return $this->connection->createQueryBuilder()
            ->select('*')
            ->from('users', 'users')
            ->where('active = :param1')
            ->executeQuery()
            ->fetchAllAssociative();
    }
}

?>