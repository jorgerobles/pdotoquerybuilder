<?php

namespace tests\Fixture;

class LanguageRepository
{
    // Test case: No alias should work correctly
    public function getLanguageByCode($pdo, $id)
    {
        $stmt = $pdo->prepare("SELECT short_code FROM languages WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    // Test case: With explicit alias should still work
    public function getLanguageByCodeWithAlias($pdo, $id)
    {
        $stmt = $pdo->prepare("SELECT short_code FROM languages l WHERE l.id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    // Test case: Complex query with JOINs and no main alias - THE PROBLEMATIC CASE FIXED
    public function getLanguagesWithTranslations($pdo)
    {
        $stmt = $pdo->prepare("
            SELECT languages.short_code, t.text
            FROM languages
            INNER JOIN translations t ON languages.id = t.language_id
            WHERE languages.active = 1
        ");
        $stmt->execute();
        return $stmt->fetchAll();
    }
}

?>
-----
<?php

namespace App;

class LanguageRepository
{
    // FIXED: No alias - should only pass table name to from()
    public function getLanguageByCode($pdo, $id)
    {
        return $this->connection->createQueryBuilder()
            ->select('short_code')
            ->from('languages')  // NO second parameter when no alias
            ->where('id = :param1')
            ->executeQuery()
            ->fetch();
    }

    // With explicit alias should still work
    public function getLanguageByCodeWithAlias($pdo, $id)
    {
        return $this->connection->createQueryBuilder()
            ->select('short_code')
            ->from('languages', 'l')  // Alias provided as second parameter
            ->where('l.id = :param1')
            ->executeQuery()
            ->fetch();
    }

    // COMPLETELY FIXED: No more 'INNER' alias issue!
    public function getLanguagesWithTranslations($pdo)
    {
        return $this->connection->createQueryBuilder()
            ->select('languages.short_code, t.text')
            ->from('languages')  // CORRECT: Only table name, no false 'INNER' alias
            ->innerJoin('languages', 'translations', 't', 'languages.id = t.language_id')  // CORRECT: Uses 'languages' not 'INNER'
            ->where('languages.active = 1')
            ->executeQuery()
            ->fetchAllAssociative();
    }
}

?>