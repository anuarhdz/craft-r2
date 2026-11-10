# Cloudflare R2 for Craft CMS

Store Craft assets in Cloudflare R2 through its S3-compatible API. The plugin adds a **Cloudflare R2** filesystem type to Craft's native filesystem and asset volume settings.

This is an independent plugin, unaffiliated with Cloudflare or Pixel & Tonic.

## Requirements

- Craft CMS `^5.9.0` (Craft 5 only).
- PHP 8.2 or later, with the extensions required by your Craft version.
- A standard R2 bucket and S3 API credentials with **Object Read & Write** permission restricted to that bucket.

## Installation

The package has not been released to Packagist or the Plugin Store yet.

While the GitHub repository is private, add its VCS repository to the consuming Craft project's `composer.json`:

```json
{
  "repositories": [
    {"type": "vcs", "url": "git@github.com:anuarhdz/craft-r2.git"}
  ]
}
```

Install version `0.1.0` with an SSH identity that has access:

```sh
composer require anuarhdz/craft-r2:^0.1.0
php craft plugin/install r2
```

Once a stable version is publicly available on Packagist, remove the VCS repository entry and install with:

```sh
composer require anuarhdz/craft-r2
php craft plugin/install r2
```

## Configuration

Create an R2 bucket and [R2 S3 API credentials](https://developers.cloudflare.com/r2/api/tokens/). These are an Access Key ID and Secret Access Key, separate from a general Cloudflare API token.

Set the following variables in the Craft project's environment:

```dotenv
R2_ACCOUNT_ID=your-32-character-cloudflare-account-id
R2_ACCESS_KEY_ID=your-r2-access-key-id
R2_SECRET_ACCESS_KEY=your-r2-secret-access-key
R2_BUCKET=your-bucket-name
R2_BASE_URL=https://assets.example.com
```

Create a filesystem under **Settings > Filesystems**, select **Cloudflare R2**, and enter variable references:

| Setting | Value |
| --- | --- |
| Account ID | `$R2_ACCOUNT_ID` |
| Access Key ID | `$R2_ACCESS_KEY_ID` |
| Secret Access Key | `$R2_SECRET_ACCESS_KEY` |
| Bucket | `$R2_BUCKET` |
| Region | `auto` |
| Subfolder | Optional bucket prefix, such as `media` |
| Base URL | `$R2_BASE_URL`, when public URLs are enabled |

Use `$VARIABLE_NAME` references to keep credentials out of Craft project config and Git. Attach the filesystem to an asset volume as usual.

Public access must be configured through the bucket or a custom domain. The plugin does not send object ACLs or provision infrastructure. For a `media` subfolder, include it in the Base URL, for example `https://assets.example.com/media`. Leave public URLs disabled for a private filesystem.

## Behavior and limitations

- File reads, streamed reads/uploads, copies, renames, directory markers, and paginated listings use the R2 S3 API.
- Uploads preserve the MIME type supplied by Craft. Copies preserve source metadata unless a MIME type is explicitly supplied; that option replaces object metadata.
- Directory renames copy every object before deleting any source. Root directory deletion and renaming are refused.
- Directory operations are not atomic. Failures can leave destination copies or partially deleted sources. Concurrent edits are not coordinated.
- Standard endpoints (`<account-id>.r2.cloudflarestorage.com`) are supported. Jurisdiction-specific endpoints, temporary session credentials, and multipart transfers for large objects are not implemented.
- Live R2 behavior must be verified with a disposable bucket/prefix before production use. Mocked tests do not prove credentials, bucket permissions, public URLs, or service compatibility.

## Development

DDEV is configured for this package with PHP 8.2 and its own MariaDB container. It serves a development placeholder; it does not expose the repository as a web root or configure an existing Craft site.

```sh
ddev start
ddev composer install
ddev composer check
ddev composer audit
ddev test-install
```

If an ignored local lock file was generated on a newer PHP version, run `ddev composer update` to resolve compatible versions. This library does not commit `composer.lock`; consuming applications should commit theirs.

Individual checks:

```sh
ddev composer check-cs
ddev composer fix-cs
ddev composer phpstan
ddev composer test
```

Craft's official ECS and PHPStan presets are used. Their upstream packages currently publish development branches rather than stable tags. ECS uses the Craft 4 preset, which also applies to Craft 5. Its older ECS dependency requires serial execution and deprecation suppression on newer PHP versions.

Unit tests use an AWS SDK mock transport without loading a Craft application, `.env`, database connection, or real R2 credentials. CI resolves dependencies independently on PHP 8.2, 8.3, and 8.4, runs the checks and dependency audit, and verifies the Git release archive.

`ddev test-install` creates a disposable `.test-app` installation and the `craft_r2_test` database in this DDEV project. It verifies plugin installation, filesystem registration, CP settings rendering, environment-reference serialization, and uninstall/reinstall. It does not use R2 credentials.

See [release readiness](https://github.com/anuarhdz/craft-r2/blob/main/docs/release-readiness.md) for validation evidence and publication steps. The plugin uses the [MIT license](LICENSE.txt).
