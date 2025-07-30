<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use JDR\Rector\PdoToQb\PdoToQueryBuilderRector;

return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->rule(PdoToQueryBuilderRector::class);

    // Opcional: configurar imports automáticos
    $rectorConfig->importNames(true, true, true, true);
};