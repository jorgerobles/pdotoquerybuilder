<?php

namespace tests\Fixture;

class SearchRepository
{
    public function searchUsers($pdo, $term, $minAge, $maxAge)
    {
        $stmt = $pdo->prepare("
            SELECT * FROM users
            WHERE (name LIKE ? OR email LIKE ?)
            AND age BETWEEN ? AND ?
            AND (active = 1 OR premium = 1)
            ORDER BY name
        ");
        $stmt->execute(["%$term%", "%$term%", $minAge, $maxAge]);
        return $stmt->fetchAll();
    }
}

?>
-----
<?php

namespace App;

class SearchRepository
{
    public function searchUsers($pdo, $term, $minAge, $maxAge)
    {
        return $this->connection->createQueryBuilder()
            ->select('*')
            ->from('users', 'users')
            ->where('(name LIKE :param1 OR email LIKE :param2)')
            ->andWhere('age BETWEEN :param3 AND :param4')
            ->andWhere('(active = 1 OR premium = 1)')
            ->addOrderBy('name', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();
    }
}

?>