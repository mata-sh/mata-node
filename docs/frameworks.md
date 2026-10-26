# Framework detection

`/apps` reports each app's framework or CMS as `{"name": "laravel", "version": "11.34.2"}`. How
the `apps.json` `framework` key controls this is in the
[configuration reference](configuration.md#framework); this page covers what automatic detection
and version lookup read.

## Known identifiers and packages

The table is also the detection order: products precede the Laravel and Symfony frameworks they
may depend on.

| Identifier | Identifying Composer packages, in priority order |
| --- | --- |
| `shopware` | `shopware/core` |
| `joomla` | `joomla/joomla-cms` |
| `cakephp` | `cakephp/cakephp` |
| `magento` | `magento/product-enterprise-edition`, `magento/product-community-edition` |
| `sylius` | `sylius/sylius` |
| `craft` | `craftcms/cms` |
| `statamic` | `statamic/cms` |
| `contao` | `contao/core-bundle` |
| `sulu` | `sulu/sulu` |
| `neos` | `neos/neos` |
| `october` | `october/rain` |
| `yii` | `yiisoft/yii2` |
| `codeigniter` | `codeigniter4/framework` |
| `laravel` | `laravel/framework` |
| `drupal` | `drupal/core` |
| `typo3` | `typo3/cms-core` |
| `wordpress` | `roots/wordpress-no-content`, `johnpbloch/wordpress-core`, `roots/wordpress`, `johnpbloch/wordpress` |
| `symfony` | `symfony/framework-bundle` |

A package matches in `vendor/composer/installed.json`, runtime `composer.lock` packages, or
`composer.json`'s root `name`. Root projects such as Joomla and Sylius therefore need no package
entry for themselves. Dev-only packages do not identify the framework. Requirement constraints
in `composer.json` never supply identity or a deployed version.

## Source probes

The Composer root is the directory of the configured `composer.lock`, else configured
`composer.json`, else `<path>/composer.json`. Detection honors `config.vendor-dir` and WordPress's
`extra.wordpress-install-dir`, even when Composer auditing is disabled. Source probes check only
these fixed paths, relative to the app and Composer roots unless shown under `vendor/`:

| Identifier | Source probe |
| --- | --- |
| `joomla` | `administrator/manifests/files/joomla.xml` |
| `laravel` | `vendor/laravel/framework/src/Illuminate/Foundation/Application.php` |
| `drupal` | `core/lib/Drupal.php`, `web/core/lib/Drupal.php`, `docroot/core/lib/Drupal.php` |
| `typo3` | `vendor/typo3/cms-core/Classes/Information/Typo3Version.php`, `typo3/sysext/core/Classes/Information/Typo3Version.php`, `public/typo3/sysext/core/Classes/Information/Typo3Version.php` |
| `wordpress` | `wp-includes/version.php` in the roots, `wp/`, `wordpress/`, `web/wp/`, or the configured WordPress install directory |
| `symfony` | `vendor/symfony/http-kernel/Kernel.php`, for version only |

An existing source probe can identify its framework even when its version cannot be parsed.
Symfony requires a package or root-name match because other products ship its components.
The other identifiers use package/root identity only.

## Version precedence

Versions come from installed packages first, then the product's own source probe, then runtime
lock packages. Package priority follows the table above. Symfony uses `symfony/http-kernel` for
its version, not `symfony/framework-bundle`. October intentionally reports `version: null`:
`october/rain` identifies the CMS but its library patch version is not the deployed CMS version.

Only concrete release versions are returned, with a leading `v` removed. Branches such as
`dev-main` and `12.x-dev`, missing versions and unreadable files yield `null` if no other source
provides a version. Unreadable or invalid detection files are logged and skipped; framework
reporting never fails `/apps`. A known declaration still reports its name if detection files are
unavailable.

## Cost and caching

The node never executes application code, `wp-cli`, `artisan` or `bin/console`, and never scans
directories. Each `/apps` request reads `composer.json` and checks the files above; the rest is
parsed again only when one of them changes, the declaration changes, or 24 hours pass.
