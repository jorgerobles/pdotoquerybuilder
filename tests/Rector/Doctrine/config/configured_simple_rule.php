<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use App\Rector\Doctrine\SimplePdoToQueryBuilderRector;

return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->rule(SimplePdoToQueryBuilderRector::class);
};