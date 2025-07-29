<?php

namespace tests\Fixture;

class PostRepository
{
    public function findPostsWithAuthors($pdo)
    {
        $stmt = $pdo->prepare("
            SELECT p.*, u.name as author_name
            FROM posts p
            INNER JOIN users u ON p.user_id = u.id
            WHERE p.published = ? AND u.active = ?
            ORDER BY p.created_at DESC
            LIMIT 10
        ");
        $stmt->execute([1, 1]);
        return $stmt->fetchAll();
    }
}

?>
-----
<?php

namespace App;

class PostRepository
{
    public function findPostsWithAuthors($pdo)
    {
        return $this->connection->createQueryBuilder()
            ->select('p.*, u.name as author_name')
            ->from('posts', 'p')
            ->innerJoin('p', 'users', 'u', 'p.user_id = u.id')
            ->where('p.published = :param1')
            ->andWhere('u.active = :param2')
            ->addOrderBy('p.created_at', 'DESC')
            ->setMaxResults(10)
            ->executeQuery()
            ->fetchAllAssociative();
    }
}

?>