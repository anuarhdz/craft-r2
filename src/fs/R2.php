<?php

namespace anuarhdz\r2\fs;

use Aws\Credentials\Credentials;
use Aws\Exception\AwsException;
use Aws\Result;
use Aws\S3\S3Client;
use Craft;
use craft\base\Fs;
use craft\errors\FsException;
use craft\errors\FsObjectNotFoundException;
use craft\helpers\App;
use craft\models\FsListing;
use DateTimeInterface;
use Generator;

/**
 * Cloudflare R2 filesystem type for Craft CMS.
 *
 * Uses the S3-compatible API provided by Cloudflare R2
 * via the AWS SDK for PHP.
 *
 * Suggested environment variables:
 *   R2_ACCOUNT_ID, R2_ACCESS_KEY_ID, R2_SECRET_ACCESS_KEY,
 *   R2_BUCKET, R2_BASE_URL
 *
 * @author anuarhdz
 * @since 0.1.0
 */
class R2 extends Fs
{
    // Public Properties
    // =========================================================================

    /**
     * @var string Cloudflare account ID or environment variable reference
     * @since 0.1.0
     */
    public string $accountId = '';

    /**
     * @var string R2 S3 access key ID or environment variable reference
     * @since 0.1.0
     */
    public string $accessKeyId = '';

    /**
     * @var string R2 S3 secret key or environment variable reference
     * @since 0.1.0
     */
    public string $secretAccessKey = '';

    /**
     * @var string Bucket name or environment variable reference
     * @since 0.1.0
     */
    public string $bucket = '';

    /**
     * @var string S3 region, normally auto
     * @since 0.1.0
     */
    public string $region = 'auto';

    /**
     * @var string Optional bucket prefix or environment variable reference
     * @since 0.1.0
     */
    public string $subfolder = '';

    // Private Properties
    // =========================================================================

    /**
     * @var S3Client|null cached S3 client
     * @since 0.1.0
     */
    private ?S3Client $_client = null;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @author anuarhdz
     * @since 0.1.0
     */
    public function attributeLabels(): array
    {
        return array_merge(parent::attributeLabels(), [
            "accountId" => Craft::t("r2", "Account ID"),
            "accessKeyId" => Craft::t("r2", "Access Key ID"),
            "secretAccessKey" => Craft::t("r2", "Secret Access Key"),
            "bucket" => Craft::t("r2", "Bucket"),
            "region" => Craft::t("r2", "Region"),
            "subfolder" => Craft::t("r2", "Subfolder"),
        ]);
    }

    /**
     * @inheritdoc
     *
     * @throws FsException
     * @author anuarhdz
     * @since 0.1.0
     */
    public function copyFile(string $path, string $newPath, array $config = []): void
    {
        $bucket = $this->_getBucket();

        $this->_request("copyObject", [
            "Bucket" => $bucket,
            "Key" => $this->_prefixPath($newPath),
            "CopySource" => rawurlencode($bucket . "/" . $this->_prefixPath($path)),
        ] + (isset($config[self::CONFIG_MIMETYPE]) ? ["MetadataDirective" => "REPLACE"] : []) + $this->_uploadOptions($config));
    }

    /**
     * @inheritdoc
     *
     * @throws FsException
     * @author anuarhdz
     * @since 0.1.0
     */
    public function createDirectory(string $path, array $config = []): void
    {
        if (trim($path, "/") === "") {
            return;
        }
        // R2 uses zero-byte directory markers.
        $dirPath = rtrim($this->_prefixPath($path), "/") . "/";

        $this->_request("putObject", [
            "Bucket" => $this->_getBucket(),
            "Key" => $dirPath,
            "Body" => "",
        ]);
    }

    /**
     * @inheritdoc
     *
     * @throws FsException
     * @author anuarhdz
     * @since 0.1.0
     */
    public function deleteDirectory(string $path): void
    {
        $this->_assertDirectoryPath($path);
        foreach ($this->_pages(["Bucket" => $this->_getBucket(), "Prefix" => $this->_directoryPrefix($path)]) as $result) {
            foreach ($result->get("Contents") ?? [] as $object) {
                $this->_request("deleteObject", ["Bucket" => $this->_getBucket(), "Key" => $object["Key"]]);
            }
        }
    }

    /**
     * @inheritdoc
     *
     * @throws FsException
     * @author anuarhdz
     * @since 0.1.0
     */
    public function deleteFile(string $path): void
    {
        $this->_request("deleteObject", [
            "Bucket" => $this->_getBucket(),
            "Key" => $this->_prefixPath($path),
        ]);
    }

    /**
     * @inheritdoc
     *
     * @throws FsException
     * @author anuarhdz
     * @since 0.1.0
     */
    public function directoryExists(string $path): bool
    {
        if ($this->_prefixPath($path) === "") {
            return true;
        }
        $result = $this->_request("listObjectsV2", [
            "Bucket" => $this->_getBucket(), "Prefix" => $this->_directoryPrefix($path), "MaxKeys" => 1,
        ]);
        return ($result->get("KeyCount") ?? 0) > 0;
    }

    /**
     * @inheritdoc
     *
     * @author anuarhdz
     * @since 0.1.0
     */
    public static function displayName(): string
    {
        return "Cloudflare R2";
    }

    /**
     * @inheritdoc
     *
     * @throws FsException
     * @author anuarhdz
     * @since 0.1.0
     */
    public function fileExists(string $path): bool
    {
        try {
            $this->_request("headObject", ["Bucket" => $this->_getBucket(), "Key" => $this->_prefixPath($path)]);
        } catch (FsObjectNotFoundException) {
            return false;
        }
        return true;
    }

    /**
     * @inheritdoc
     *
     * @throws FsException
     * @author anuarhdz
     * @since 0.1.0
     */
    public function getDateModified(string $uri): int
    {
        $result = $this->_request("headObject", [
            "Bucket" => $this->_getBucket(),
            "Key" => $this->_prefixPath($uri),
        ]);

        /**
     * @var DateTimeInterface $lastModified */
        $lastModified = $result->get("LastModified");

        return $lastModified->getTimestamp();
    }

    /**
     * @inheritdoc
     *
     * @throws FsException
     * @author anuarhdz
     * @since 0.1.0
     */
    public function getFileList(string $directory = "", bool $recursive = true): Generator
    {
        $prefix = $this->_directoryPrefix($directory);
        $params = ["Bucket" => $this->_getBucket(), "Prefix" => $prefix];
        if (!$recursive) {
            $params["Delimiter"] = "/";
        }
        $subfolder = $this->subfolder === "" ? "" : trim($this->_setting($this->subfolder, "subfolder"), "/");
        $offset = $subfolder === "" ? 0 : strlen($subfolder) + 1;
        foreach ($this->_pages($params) as $result) {
            foreach ($result->get("Contents") ?? [] as $object) {
                $key = $object["Key"];
                if ($key === $prefix) {
                    continue;
                }
                $path = rtrim(substr($key, $offset), "/");
                if ($path === "") {
                    continue;
                }
                $isDir = str_ends_with($key, "/");
                $parent = dirname($path);
                $modified = $object["LastModified"] ?? null;
                yield new FsListing([
                    "dirname" => $parent === "." ? "" : $parent,
                    "basename" => basename($path),
                    "type" => $isDir ? "dir" : "file",
                    "fileSize" => (int) ($object["Size"] ?? 0),
                    "dateModified" => $modified instanceof DateTimeInterface ? $modified->getTimestamp() : null,
                ]);
            }
            foreach ($result->get("CommonPrefixes") ?? [] as $entry) {
                $path = rtrim(substr($entry["Prefix"], $offset), "/");
                $parent = dirname($path);
                yield new FsListing([
                    "dirname" => $parent === "." ? "" : $parent,
                    "basename" => basename($path),
                    "type" => "dir",
                ]);
            }
        }
    }

    /**
     * @inheritdoc
     *
     * @throws FsException
     * @author anuarhdz
     * @since 0.1.0
     */
    public function getFileSize(string $uri): int
    {
        $result = $this->_request("headObject", [
            "Bucket" => $this->_getBucket(),
            "Key" => $this->_prefixPath($uri),
        ]);

        return (int) $result->get("ContentLength");
    }

    /**
     * @inheritdoc
     *
     * @throws FsException
     * @author anuarhdz
     * @since 0.1.0
     */
    public function getFileStream(string $uriPath)
    {
        $result = $this->_request("getObject", [
            "Bucket" => $this->_getBucket(),
            "Key" => $this->_prefixPath($uriPath),
        ]);

        $stream = $result->get("Body")->detach();
        if (!is_resource($stream)) {
            throw new FsException("R2 did not return a readable stream.");
        }
        return $stream;
    }

    /**
     * @inheritdoc
     *
     * @author anuarhdz
     * @since 0.1.0
     */
    public function getSettingsHtml(): ?string
    {
        /**
     * @var \craft\web\Application $app */
        $app = Craft::$app;
        return $app->getView()->renderTemplate("r2/_fs/settings", [
            "fs" => $this,
        ]);
    }

    /**
     * @inheritdoc
     *
     * @throws FsException
     * @author anuarhdz
     * @since 0.1.0
     */
    public function read(string $path): string
    {
        $result = $this->_request("getObject", [
            "Bucket" => $this->_getBucket(),
            "Key" => $this->_prefixPath($path),
        ]);

        return (string) $result->get("Body");
    }

    /**
     * @inheritdoc
     *
     * @throws FsException
     * @author anuarhdz
     * @since 0.1.0
     */
    public function renameDirectory(string $path, string $newName): void
    {
        $this->_assertDirectoryPath($path);
        if ($newName === "" || $newName === "." || $newName === ".." || str_contains($newName, "/")) {
            throw new FsException("The new directory name must be a single path segment.");
        }
        $path = trim($path, "/");
        $parent = dirname($path);
        $newPath = ($parent === "." ? "" : $parent . "/") . $newName;
        if ($path === $newPath) {
            return;
        }
        $bucket = $this->_getBucket();
        $oldPrefix = $this->_directoryPrefix($path);
        $newPrefix = $this->_directoryPrefix($newPath);
        $keys = [];
        foreach ($this->_pages(["Bucket" => $bucket, "Prefix" => $oldPrefix]) as $result) {
            foreach ($result->get("Contents") ?? [] as $object) {
                $key = $object["Key"];
                $this->_request("copyObject", [
                    "Bucket" => $bucket,
                    "Key" => $newPrefix . substr($key, strlen($oldPrefix)),
                    "CopySource" => rawurlencode($bucket . "/" . $key),
                ]);
                $keys[] = $key;
            }
        }
        if ($keys === []) {
            throw new FsObjectNotFoundException("The source directory does not exist.");
        }
        // Keep every source object until all copies have succeeded.
        foreach ($keys as $key) {
            $this->_request("deleteObject", ["Bucket" => $bucket, "Key" => $key]);
        }
    }

    /**
     * @inheritdoc
     *
     * @throws FsException
     * @author anuarhdz
     * @since 0.1.0
     */
    public function renameFile(string $path, string $newPath, array $config = []): void
    {
        if ($this->_prefixPath($path) === $this->_prefixPath($newPath)) {
            return;
        }
        $this->copyFile($path, $newPath, $config);
        $this->deleteFile($path);
    }

    /**
     * @inheritdoc
     *
     * @throws FsException
     * @author anuarhdz
     * @since 0.1.0
     */
    public function write(string $path, string $contents, array $config = []): void
    {
        $this->_request("putObject", [
            "Bucket" => $this->_getBucket(),
            "Key" => $this->_prefixPath($path),
            "Body" => $contents,
        ] + $this->_uploadOptions($config));
    }

    /**
     * @inheritdoc
     *
     * @throws FsException
     * @author anuarhdz
     * @since 0.1.0
     */
    public function writeFileFromStream(string $path, $stream, array $config = []): void
    {
        $this->_request("putObject", [
            "Bucket" => $this->_getBucket(),
            "Key" => $this->_prefixPath($path),
            "Body" => $stream,
        ] + $this->_uploadOptions($config));
    }

    // Protected Methods
    // =========================================================================

    /**
     * Returns a configured S3Client pointing at the R2 endpoint.
     *
     * @throws FsException
     * @author anuarhdz
     * @since 0.1.0
     */
    protected function _createClient(): S3Client
    {
        $accountId = $this->_setting($this->accountId, "accountId");
        if (!preg_match('/^[a-f0-9]{32}$/iD', $accountId)) {
            throw new FsException("The R2 account ID must contain 32 hexadecimal characters.");
        }
        $accessKeyId = $this->_setting($this->accessKeyId, "accessKeyId");
        $secretAccessKey = $this->_setting($this->secretAccessKey, "secretAccessKey");
        $region = App::parseEnv($this->region) ?: "auto";

        return new S3Client([
            "region" => $region,
            "version" => "latest",
            "endpoint" => "https://{$accountId}.r2.cloudflarestorage.com",
            "credentials" => new Credentials($accessKeyId, $secretAccessKey),
            "use_path_style_endpoint" => true,
        ]);
    }

    /**
     * @inheritdoc
     *
     * @author anuarhdz
     * @since 0.1.0
     */
    protected function defineRules(): array
    {
        return array_merge(parent::defineRules(), [
            [["accountId", "accessKeyId", "secretAccessKey", "bucket"], "required"],
        ]);
    }

    // Private Methods
    // =========================================================================

    /**
     * Refuses destructive operations on the configured filesystem root.
     *
     * @throws FsException
     * @author anuarhdz
     * @since 0.1.0
     */
    private function _assertDirectoryPath(string $path): void
    {
        if (trim($path, "/.") === "") {
            throw new FsException("The filesystem root cannot be deleted or renamed.");
        }
    }

    /**
     * Returns a prefix with exactly one trailing slash, or an empty bucket root.
     *
     * @throws FsException
     * @author anuarhdz
     * @since 0.1.0
     */
    private function _directoryPrefix(string $path): string
    {
        $key = $this->_prefixPath($path);
        return $key === "" ? "" : rtrim($key, "/") . "/";
    }

    /**
     * Resolves the bucket name from the (possibly env-var) setting.
     *
     * @throws FsException
     * @author anuarhdz
     * @since 0.1.0
     */
    private function _getBucket(): string
    {
        return $this->_setting($this->bucket, "bucket");
    }

    /**
     * Iterates every S3 listing page, including empty pages with continuation tokens.
     *
     * @throws FsException
     * @author anuarhdz
     * @since 0.1.0
     */
    private function _pages(array $params): Generator
    {
        $tokens = [];
        do {
            $result = $this->_request("listObjectsV2", $params);
            yield $result;
            $token = $result->get("NextContinuationToken");
            if ($result->get("IsTruncated") && !$token) {
                throw new FsException("R2 returned a truncated listing without a continuation token.");
            }
            if ($result->get("IsTruncated") && isset($tokens[$token])) {
                throw new FsException("R2 returned a repeated continuation token.");
            }
            $tokens[$token ?? ""] = true;
            $params["ContinuationToken"] = $token;
        } while ($result->get("IsTruncated"));
    }

    /**
     * Prefixes a path with the subfolder, if one is configured.
     *
     * @throws FsException
     * @author anuarhdz
     * @since 0.1.0
     */
    private function _prefixPath(string $path): string
    {
        $path = trim($path, "/");
        if (in_array("..", explode("/", $path), true)) {
            throw new FsException("Parent path segments are not supported.");
        }
        $subfolder = $this->subfolder === "" ? "" : trim($this->_setting($this->subfolder, "subfolder"), "/");

        if (in_array("..", explode("/", $subfolder), true)) {
            throw new FsException("Parent path segments are not supported in the subfolder.");
        }

        if ($subfolder === "") {
            return $path;
        }

        return $subfolder . "/" . $path;
    }

    /**
     * Executes an S3 command and translates failures to Craft filesystem exceptions.
     *
     * @throws FsException
     * @author anuarhdz
     * @since 0.1.0
     */
    private function _request(string $operation, array $params): Result
    {
        $this->_client ??= $this->_createClient();
        try {
            return $this->_client->{$operation}($params);
        } catch (AwsException $exception) {
            if ($exception->getStatusCode() === 404 && $exception->getAwsErrorCode() !== "NoSuchBucket") {
                throw new FsObjectNotFoundException("R2 object not found.", 0, $exception);
            }
            throw new FsException("R2 {$operation} failed.", 0, $exception);
        }
    }

    /**
     * Resolves a required setting without exposing its value in errors.
     *
     * @throws FsException
     * @author anuarhdz
     * @since 0.1.0
     */
    private function _setting(string $value, string $attribute): string
    {
        $resolved = App::parseEnv($value);
        if (!is_string($resolved) || $resolved === "" || str_starts_with($resolved, "$")) {
            throw new FsException("Missing R2 setting: {$attribute}.");
        }
        return $resolved;
    }

    /**
     * Maps supported Craft upload options to S3 without sending unsupported ACLs.
     *
     * @author anuarhdz
     * @since 0.1.0
     */
    private function _uploadOptions(array $config): array
    {
        if (isset($config[self::CONFIG_MIMETYPE])) {
            return ["ContentType" => $config[self::CONFIG_MIMETYPE]];
        }
        return [];
    }
}
