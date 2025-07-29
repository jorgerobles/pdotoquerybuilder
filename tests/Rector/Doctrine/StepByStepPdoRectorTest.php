<?php

declare(strict_types=1);

namespace Tests\Rector\Doctrine;

use Rector\Testing\PHPUnit\AbstractRectorTestCase;

final class StepByStepPdoRectorTest extends AbstractRectorTestCase
{
    public function testConvertFetchAll(): void
    {
        $this->doTestFile(__DIR__ . '/Fixture/minimal_fetchall.php.inc');
    }

    public function provideConfigFilePath(): string
    {
        return __DIR__ . '/config/babysteps_config.php';
    }
}