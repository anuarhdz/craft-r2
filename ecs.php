<?php

declare(strict_types=1);

use craft\ecs\SetList;
use Symplify\EasyCodingStandard\Config\ECSConfig;

return static function(ECSConfig $config): void {
    $config->paths([__DIR__ . '/src', __DIR__ . '/tests', __FILE__]);
    $config->sets([SetList::CRAFT_CMS_4]);
    $config->parameters()->set('parallel', false);
};
