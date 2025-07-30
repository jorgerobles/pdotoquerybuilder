<?php

declare(strict_types=1);

namespace JDR\Rector\MethodsToTraits\Tests;

use Rector\Testing\PHPUnit\AbstractRectorTestCase;

/**
 * Comprehensive test suite for PublicMethodsToTraitsRector
 */
final class MethodsToTraitsRectorTest extends AbstractRectorTestCase
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
        return __DIR__ . '/config/rector_traits_config.php';
    }

    public function testExtractValidationMethods(): void
    {
        $this->doTestFile(__DIR__ . '/Fixture/validation_methods.php.inc');
    }

    public function testExtractFormattingMethods(): void
    {
        $this->doTestFile(__DIR__ . '/Fixture/formatting_methods.php.inc');
    }

    public function testExtractWithAnnotations(): void
    {
        $this->doTestFile(__DIR__ . '/Fixture/annotation_extraction.php.inc');
    }

    public function testExtractWithAttributes(): void
    {
        $this->doTestFile(__DIR__ . '/Fixture/attribute_extraction.php.inc');
    }

    public function testHandleDependencies(): void
    {
        $this->doTestFile(__DIR__ . '/Fixture/with_dependencies.php.inc');
    }

    public function testComplexGrouping(): void
    {
        $this->doTestFile(__DIR__ . '/Fixture/complex_grouping.php.inc');
    }

    public function testMinimumMethodsRequirement(): void
    {
        $this->doTestFile(__DIR__ . '/Fixture/minimum_methods.php.inc');
    }

    public function testExcludedMethods(): void
    {
        $this->doTestFile(__DIR__ . '/Fixture/excluded_methods.php.inc');
    }
}