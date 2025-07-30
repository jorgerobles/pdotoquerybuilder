<?php
class UserRepository
{
    public function updateUserStatus($pdo, $userId, $status, $reason)
    {
        $stmt = $pdo->prepare("
            UPDATE users 
            SET status = ?, 
                status_reason = ?, 
                updated_at = NOW() 
            WHERE id = ? AND active = 1
        ");
        return $stmt->execute([$status, $reason, $userId]);
    }
}