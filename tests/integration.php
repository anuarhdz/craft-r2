<?php

/**
 * Checks plugin installation, registration, settings rendering, and serialization.
 *
 * Runs only against the disposable .test-app fixture and craft_r2_test database.
 *
 * @author anuarhdz
 * @since 0.1.0
 */

use anuarhdz\r2\fs\R2;
use craft\helpers\App;
use craft\web\View;

require dirname(__DIR__) . '/.test-app/bootstrap.php';

if (App::env('CRAFT_DB_DATABASE') !== 'craft_r2_test') {
    throw new RuntimeException('Integration checks require the craft_r2_test database.');
}

$_SERVER['HTTP_HOST'] = 'craft-r2.ddev.site';
$_SERVER['REQUEST_URI'] = '/admin';
$_SERVER['SCRIPT_FILENAME'] = CRAFT_BASE_PATH . '/web/index.php';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['REQUEST_METHOD'] = 'GET';

try {
    /** @var craft\web\Application $app */
    $app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/web.php';
    if (!$app->getIsInstalled() || !$app->getPlugins()->isPluginInstalled('r2')) {
        throw new RuntimeException('Craft and the R2 plugin must be installed.');
    }
    if (!in_array(R2::class, $app->getFs()->getAllFilesystemTypes(), true)) {
        throw new RuntimeException('The R2 filesystem type was not registered.');
    }

    $fs = new R2([
        'name' => 'R2 test',
        'handle' => 'r2test',
        'accountId' => '$R2_ACCOUNT_ID',
        'accessKeyId' => '$R2_ACCESS_KEY_ID',
        'secretAccessKey' => '$R2_SECRET_ACCESS_KEY',
        'bucket' => '$R2_BUCKET',
    ]);
    $app->getView()->setTemplateMode(View::TEMPLATE_MODE_CP);
    $html = $fs->getSettingsHtml();
    foreach (['accountId', 'accessKeyId', 'secretAccessKey', 'bucket', 'region', 'subfolder'] as $attribute) {
        if (!str_contains($html ?? '', 'id="' . $attribute . '-field"')) {
            throw new RuntimeException('Missing settings field: ' . $attribute);
        }
    }
    $settings = $fs->getSettings();
    if (($settings['secretAccessKey'] ?? null) !== '$R2_SECRET_ACCESS_KEY') {
        throw new RuntimeException('Filesystem serialization did not preserve the environment reference.');
    }

    fwrite(STDOUT, "OK: Craft installation, plugin registration, CP settings rendering, and environment-reference serialization.\n");
} catch (Throwable $exception) {
    fwrite(STDERR, get_class($exception) . ": " . $exception->getMessage() . "\n");
    exit(1);
}
