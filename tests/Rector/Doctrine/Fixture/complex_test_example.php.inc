<?php

namespace Tests\Rector\Doctrine\Fixture;

class ComprehensivePdoToQueryBuilder
{
    // Simple SELECT
    public function getUsers()
    {
        $stmt = $pdo->prepare("SELECT id, name, email FROM users WHERE status = ?");
        $stmt->execute(['active']);
        return $stmt->fetchAll();
    }

    // SELECT with JOIN
    public function getUsersWithProfiles()
    {
        $stmt = $pdo->prepare("SELECT u.name, u.email, p.bio FROM users u LEFT JOIN profiles p ON u.id = p.user_id WHERE u.status = ?");
        $stmt->execute(['active']);
        return $stmt->fetchAll();
    }

    // INSERT
    public function createUser($name, $email)
    {
        $stmt = $pdo->prepare("INSERT INTO users (name, email, status) VALUES (?, ?, 'active')");
        $stmt->execute([$name, $email]);
        return $pdo->lastInsertId();
    }

    // UPDATE
    public function updateUserStatus($id, $status)
    {
        $stmt = $pdo->prepare("UPDATE users SET status = ? WHERE id = ?");
        $stmt->execute([$status, $id]);
        return $stmt->rowCount();
    }

    // DELETE
    public function deleteInactiveUsers()
    {
        $stmt = $pdo->prepare("DELETE FROM users WHERE status = ?");
        $stmt->execute(['inactive']);
        return $stmt->rowCount();
    }

    // Complex SELECT with ORDER BY and LIMIT
    public function getTopUsers()
    {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE status = ? ORDER BY created_at DESC LIMIT ?");
        $stmt->execute(['active', 10]);
        return $stmt->fetchAll();
    }

    // SELECT with fetch() instead of fetchAll()
    public function getUser($id)
    {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    // SELECT with fetchColumn()
    public function getUserCount()
    {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE status = ?");
        $stmt->execute(['active']);
        return $stmt->fetchColumn();
    }
}

?>
-----
<?php

namespace Tests\Rector\Doctrine\Fixture;

class ComprehensivePdoToQueryBuilder
{
    // Simple SELECT
    public function getUsers()
    {
        return $this->connection()->createQueryBuilder()
            ->select('id, name, email')
            ->from('users', 'users')
            ->where('status = :param1')
            ->setParameter('param1', 'active')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    // SELECT with JOIN
    public function getUsersWithProfiles()
    {
        return $this->connection()->createQueryBuilder()
            ->select('u.name, u.email, p.bio')
            ->from('users', 'u')
            ->leftJoin('profiles', 'p', 'u.id = p.user_id')
            ->where('u.status = :param1')
            ->setParameter('param1', 'active')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    // INSERT
    public function createUser($name, $email)
    {
        return $this->connection()->createQueryBuilder()
            ->insert('users')
            ->setValue('name', ':param1')
            ->setValue('email', ':param2')
            ->setValue('status', 'active')
            ->setParameter('param1', $name)
            ->setParameter('param2', $email)
            ->executeQuery();
    }

    // UPDATE
    public function updateUserStatus($id, $status)
    {
        return $this->connection()->createQueryBuilder()
            ->update('users')
            ->set('status', ':param1')
            ->where('id = :param2')
            ->setParameter('param1', $status)
            ->setParameter('param2', $id)
            ->executeQuery();
    }

    // DELETE
    public function deleteInactiveUsers()
    {
        return $this->connection()->createQueryBuilder()
            ->delete('users')
            ->where('status = :param1')
            ->setParameter('param1', 'inactive')
            ->executeQuery();
    }

    // Complex SELECT with ORDER BY and LIMIT
    public function getTopUsers()
    {
        return $this->connection()->createQueryBuilder()
            ->select('*')
            ->from('users', 'users')
            ->where('status = :param1')
            ->addOrderBy('created_at', 'DESC')
            ->setMaxResults(':param2')
            ->setParameter('param1', 'active')
            ->setParameter('param2', 10)
            ->executeQuery()
            ->fetchAllAssociative();
    }

    // SELECT with fetch() instead of fetchAll()
    public function getUser($id)
    {
        return $this->connection()->createQueryBuilder()
            ->select('*')
            ->from('users', 'users')
            ->where('id = :param1')
            ->setParameter('param1', $id)
            ->executeQuery()
            ->fetchAssociative();
    }

    // SELECT with fetchColumn()
    public function getUserCount()
    {
        return $this->connection()->createQueryBuilder()
            ->select('COUNT(*)')
            ->from('users', 'users')
            ->where('status = :param1')
            ->setParameter('param1', 'active')
            ->executeQuery()
            ->fetchOne();
    }
}

?>