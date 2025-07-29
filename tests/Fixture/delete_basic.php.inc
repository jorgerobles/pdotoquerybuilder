<?php

namespace tests\Fixture;

class UserRepository
{
    public function deleteInactiveUsers($pdo, $days)
    {
        $stmt = $pdo->prepare("DELETE FROM users WHERE active = 0 AND last_login < DATE_SUB(NOW(), INTERVAL ? DAY)");
        return $stmt->execute([$days]);
    }
}

?>
-----
<?php

namespace App;

class UserRepository
{
    public function deleteInactiveUsers($pdo, $days)
    {
        return $this->connection->createQueryBuilder()
            ->delete('users')
            ->where('active = 0')
            ->andWhere('last_login < DATE_SUB(NOW(), INTERVAL :param1 DAY)')
            ->executeStatement();
    }
}

?>