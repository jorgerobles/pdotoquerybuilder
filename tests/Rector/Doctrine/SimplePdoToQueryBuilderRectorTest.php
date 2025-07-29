<?php

declare(strict_types=1);

namespace Tests\Rector\Doctrine;

use Rector\Testing\PHPUnit\AbstractRectorTestCase;

final class SimplePdoToQueryBuilderRectorTest extends AbstractRectorTestCase
{
    public function testSimpleConversion(): void
    {
        $this->doTestFile(__DIR__ . '/Fixture/simple_test.php.inc');
    }

    public function provideConfigFilePath(): string
    {
        return __DIR__ . '/config/configured_simple_rule.php';
    }
}