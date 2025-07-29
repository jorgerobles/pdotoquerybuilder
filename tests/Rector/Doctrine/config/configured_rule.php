<?php
declare(strict_types=1);

use Rector\Config\RectorConfig;
use App\Rector\Doctrine\PdoToQueryBuilderRector;

return static function (RectorConfig $rectorConfig): void {
$rectorConfig->rule(PdoToQueryBuilderRector::class);

// Configuraciones adicionales para mejor funcionamiento
$rectorConfig->importNames();
$rectorConfig->removeUnusedImports();
};