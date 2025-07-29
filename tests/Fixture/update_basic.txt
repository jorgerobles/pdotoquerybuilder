<?php

namespace tests\Fixture;

class UserRepository
{
    public function updateUser($pdo, $id, $name, $email)
    {
        $stmt = $pdo->prepare("UPDATE users SET name = ?, email = ?, updated_at = NOW() WHERE id = ?");
        return $stmt->execute([$name, $email, $id]);
    }
}

?>
-----
<?php

namespace App;

class UserRepository
{
    public function updateUser($pdo, $id, $name, $email)
    {
        return $this->connection->createQueryBuilder()
            ->update('users')
            ->set('name', ':param1')
            ->set('email', ':param2')
            ->set('updated_at', 'NOW()')
            ->where('id = :param3')
            ->executeStatement();
    }
}

?>