<?php

declare(strict_types=1);

use App\Rector\Doctrine\StepByStepPdoRector;
use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withRules([
        StepByStepPdoRector::class,
    ])
    ->withPaths([
        __DIR__ . '/src',
    ])
    ->withSkip([
        __DIR__ . '/tests',
        __DIR__ . '/vendor',
    ])
    ->withImportNames(true, true, true, true);
