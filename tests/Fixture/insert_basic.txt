<?php

namespace tests\Fixture;

class UserRepository
{
    public function createUser($pdo, $name, $email)
    {
        $stmt = $pdo->prepare("INSERT INTO users (name, email, created_at) VALUES (?, ?, NOW())");
        $stmt->execute([$name, $email]);
        return $pdo->lastInsertId();
    }
}

?>
-----
<?php

namespace App;

class UserRepository
{
    public function createUser($pdo, $name, $email)
    {
        $this->connection->createQueryBuilder()
            ->insert('users')
            ->setValue('name', ':param1')
            ->setValue('email', ':param2')
            ->setValue('created_at', 'NOW()')
            ->executeStatement();
        return $this->connection->lastInsertId();
    }
}

?>