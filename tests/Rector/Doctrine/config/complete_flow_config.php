<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use App\Rector\Doctrine\CompletePdoFlowRector;

return RectorConfig::configure()
    ->withRules([
        CompletePdoFlowRector::class,
    ]);