<?php

declare(strict_types=1);

use App\Rector\Doctrine\PdoToQueryBuilderRector;
use Rector\Config\RectorConfig;

return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->rule(PdoToQueryBuilderRector::class);

    // Opcional: configurar imports automáticos
    $rectorConfig->importNames(true, true, true, true);
};