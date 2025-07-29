<?php

declare(strict_types=1);

use App\Rector\Doctrine\StepByStepPdoRector;
use Rector\Config\RectorConfig;

return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->rule(StepByStepPdoRector::class);
};