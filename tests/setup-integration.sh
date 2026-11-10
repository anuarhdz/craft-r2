#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."

# Fixed, disposable fixture; never load the parent site's environment.
export CRAFT_APP_ID=craft-r2-integration
export CRAFT_ENVIRONMENT=dev
export CRAFT_SECURITY_KEY=craft-r2-local-integration-only-key
export CRAFT_DB_DRIVER=mysql
export CRAFT_DB_SERVER="${CRAFT_R2_TEST_DB_SERVER:-db}"
export CRAFT_DB_PORT=3306
export CRAFT_DB_DATABASE=craft_r2_test
export CRAFT_DB_USER=db
export CRAFT_DB_PASSWORD=db
export PRIMARY_SITE_URL=https://craft-r2.ddev.site

if [[ ! -f .test-app/craft ]]; then
    # DDEV's create-project shorthand only supports the project root.
    composer create-project craftcms/craft .test-app --no-install --no-scripts --no-interaction
    cp .test-app/composer.json.default .test-app/composer.json
fi
composer --working-dir=.test-app config repositories.plugin '{"type":"path","url":"..","options":{"symlink":true,"versions":{"anuarhdz/craft-r2":"dev-main"}}}'
composer --working-dir=.test-app require anuarhdz/craft-r2:dev-main --update-no-dev --no-interaction --prefer-dist
php .test-app/craft install/craft --username=plugin-test --password=LocalFixtureOnly123 --email=plugin-test@example.test --site-name='R2 integration' --site-url=https://craft-r2.ddev.site --language=en --interactive=0
php .test-app/craft install/check --interactive=0
# Craft also returns 1 when the plugin is already installed. The integration
# check below verifies actual installed state rather than trusting that exit code.
php .test-app/craft plugin/install r2 --interactive=0 || test "$?" -eq 1
php tests/integration.php
php .test-app/craft plugin/uninstall r2 --interactive=0
php .test-app/craft plugin/install r2 --interactive=0
php tests/integration.php
