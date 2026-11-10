<?php

namespace anuarhdz\r2;

use anuarhdz\r2\fs\R2;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterComponentTypesEvent;
use craft\services\Fs;
use yii\base\Event;

/**
 * Registers the Cloudflare R2 filesystem in every Craft request context.
 *
 * @author anuarhdz
 * @since 0.1.0
 */
class Plugin extends BasePlugin
{
    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @author anuarhdz
     * @since 0.1.0
     */
    public function init(): void
    {
        parent::init();
        Event::on(Fs::class, Fs::EVENT_REGISTER_FILESYSTEM_TYPES, static function(RegisterComponentTypesEvent $event): void {
            $event->types[] = R2::class;
        });
    }
}
