<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use App\Rector\Doctrine\CompletePdoFlowRector;

return RectorConfig::configure()
    ->withRules([
        \App\Rector\Doctrine\StepByStepPdoRector::class,
    ])
    ->withPaths([
        __DIR__ . '/src',
    ])
    ->withSkip([
        __DIR__ . '/tests',
        __DIR__ . '/vendor',
    ])
    ->withImportNames(true, true, true, true);
