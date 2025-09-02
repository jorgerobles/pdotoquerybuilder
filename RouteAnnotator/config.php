<?php

// 1. Rector Configuration (rector.php)
declare(strict_types=1);

use JDR\Rector\RouteAnnotator\RouteRector;
use Rector\Config\RectorConfig;


return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->paths([
        #__DIR__ . '/tests',
        '/home/jorge/workspace/src/gitlab.com/hub-buildings/services/hub-os-backend-v1/modules/'
    ]);

    // Register our custom Rector rule
    $rectorConfig->rule(RouteRector::class);
    $rectorConfig->parallel(300, 16, 5);

    // Configure the rule with custom parameters
    $rectorConfig->ruleWithConfiguration(RouteRector::class, [
        'classPattern' => 'Controller',
        'addUseStatement' => false,  // Set to true if you want "use Symfony\Component\Routing\Annotation\Route;"
//        'pathTemplate' => function($variables = []): string {
//            $variables['moduleSlug'] = basename(dirname($variables['filePath'],2));
//            return RouteRector::template('/:moduleSlug/:controllerSlug/:methodSlug/{params}', $variables, ':',null);
//        },
        'nameTemplate' => function($variables = []): string {
            $variables['moduleSlug'] = basename(dirname($variables['filePath'],2));
            return RouteRector::template(':moduleSlug_:controllerSlug_:methodSlug', $variables);
        },
        //'requirements'=>['params'=>'.+']
    ]);
};