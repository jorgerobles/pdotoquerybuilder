<?php
class UserRepository
{
    public function createUser($pdo, $name, $email, $age)
    {
        $stmt = $pdo->prepare("INSERT INTO users (name, email, age, created_at) VALUES (?, ?, ?, NOW())");
        $stmt->execute([$name, $email, $age]);
        return $pdo->lastInsertId();
    }
}