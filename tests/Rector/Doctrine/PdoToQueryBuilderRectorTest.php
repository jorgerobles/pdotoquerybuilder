<?php

declare(strict_types=1);

namespace Tests\Rector\Doctrine;

use Rector\Testing\PHPUnit\AbstractRectorTestCase;

/**
 * Tests comprehensivos para todos los tipos de consultas
 */
final class PdoToQueryBuilderRectorTest extends AbstractRectorTestCase
{
    /**
     * @dataProvider provideDataForTest
     */
    public function testRector(string $filePath): void
    {
        $this->doTestFile($filePath);
    }

    public static function provideDataForTest(): \Iterator
    {
        return self::yieldFilesFromDirectory(__DIR__ . '/Fixture');
    }

    public function provideConfigFilePath(): string
    {
        return __DIR__ . '/config/test_config.php';
    }
}