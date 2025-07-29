<?php

// tests/Rector/Doctrine/PdoToQueryBuilderRectorTest.php
declare(strict_types=1);

namespace Tests\Rector\Doctrine;

use PHPUnit\Framework\TestCase;
use Rector\Testing\PHPUnit\AbstractRectorTestCase;
use App\Rector\Doctrine\PdoToQueryBuilderRector;

final class PdoToQueryBuilderRectorTest extends AbstractRectorTestCase
{
//    public function testConvertPdoSelectToQueryBuilder(): void
//    {
//        $this->doTestFile(__DIR__ . '/Fixture/convert_pdo_select.php.inc');
//    }
//
//    public function testConvertPdoInsertToQueryBuilder(): void
//    {
//        $this->doTestFile(__DIR__ . '/Fixture/convert_pdo_insert.php.inc');
//    }
//
//    public function testConvertPdoUpdateToQueryBuilder(): void
//    {
//        $this->doTestFile(__DIR__ . '/Fixture/convert_pdo_update.php.inc');
//    }

    public function testConvertComplexWhereConditions(): void
    {
        $this->doTestFile(__DIR__ . '/Fixture/convert_complex_where.php.inc');
    }

//    public function testConvertNestedConditionsWithParentheses(): void
//    {
//        $this->doTestFile(__DIR__ . '/Fixture/convert_nested_conditions.php.inc');
//    }

    public function provideConfigFilePath(): string
    {
        return __DIR__ . '/config/configured_rule.php';
    }
}