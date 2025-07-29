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