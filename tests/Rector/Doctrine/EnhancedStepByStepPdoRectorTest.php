<?php

declare(strict_types=1);

namespace Tests\Rector\Doctrine;

use Rector\Testing\PHPUnit\AbstractRectorTestCase;

final class EnhancedStepByStepPdoRectorTest extends AbstractRectorTestCase
{
    /**
     * @dataProvider provideData()
     */
    public function testRule(string $filePath): void
    {
        $this->doTestFile($filePath);
    }

    public static function provideData(): \Iterator
    {
        return self::yieldFilesFromDirectory(__DIR__ . '/Fixture');
    }

    public function provideConfigFilePath(): string
    {
        return __DIR__ . '/config/enhanced_config.php';
    }



    public function simpleExampleTest(): void
    {
        $this->doTestFile(__DIR__ . '/Fixture/simple_test_example.php.inc');
    }
}