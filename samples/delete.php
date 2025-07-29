<?php
class CleanupRepository
{
    public function deleteOrphanedPosts($pdo)
    {
        $stmt = $pdo->prepare("
            DELETE p FROM posts p
            LEFT JOIN users u ON p.user_id = u.id
            WHERE u.id IS NULL OR u.deleted_at IS NOT NULL
        ");
        return $stmt->execute();
    }
}