<?php

namespace anuarhdz\r2\tests;

use anuarhdz\r2\fs\R2;
use Aws\Command;
use Aws\Exception\AwsException;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\S3Client;
use craft\errors\FsException;
use craft\errors\FsObjectNotFoundException;
use DateTimeImmutable;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\TestCase;

/**
 * Verifies filesystem behavior without booting Craft or contacting R2.
 *
 * @author anuarhdz
 * @since 0.1.0
 */
class R2Test extends TestCase
{
    // =========================================================================
    // Private Properties
    // =========================================================================

    /** @var array<int, array{0: string, 1: array}> recorded S3 commands */
    private array $_commands = [];

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * Verifies continuation tokens, date objects, and relative paths.
     *
     * @author anuarhdz
     * @since 0.1.0
     */
    public function testPaginatedListing(): void
    {
        $date = new DateTimeImmutable('2026-01-01T00:00:00Z');
        $fs = $this->_filesystem([
            new Result(['Contents' => [['Key' => 'media/a.txt', 'Size' => 7, 'LastModified' => $date]], 'IsTruncated' => true, 'NextContinuationToken' => 'next']),
            new Result(['Contents' => [['Key' => 'media/folder/b.txt', 'Size' => 3]], 'IsTruncated' => false]),
        ], ['subfolder' => '/media/']);
        $files = iterator_to_array($fs->getFileList());
        self::assertCount(2, $files);
        self::assertSame('a.txt', $files[0]->getUri());
        self::assertSame($date->getTimestamp(), $files[0]->getDateModified());
        self::assertSame('folder/b.txt', $files[1]->getUri());
        self::assertSame('next', $this->_commands[1][1]['ContinuationToken']);
        self::assertSame('media/', $this->_commands[0][1]['Prefix']);
    }

    /**
     * Verifies directory markers and common prefixes use relative basenames.
     *
     * @author anuarhdz
     * @since 0.1.0
     */
    public function testNonRecursiveDirectories(): void
    {
        $fs = $this->_filesystem([new Result([
            'Contents' => [['Key' => 'media/parent/'], ['Key' => 'media/parent/child/']],
            'CommonPrefixes' => [['Prefix' => 'media/parent/other/']],
        ])], ['subfolder' => 'media']);
        $files = iterator_to_array($fs->getFileList('parent', false));
        self::assertSame(['parent/child', 'parent/other'], array_map(fn($file) => $file->getUri(), $files));
        self::assertTrue($files[0]->getIsDir());
        self::assertSame('/', $this->_commands[0][1]['Delimiter']);
    }

    /**
     * Verifies the root listing does not accidentally use a slash prefix.
     *
     * @author anuarhdz
     * @since 0.1.0
     */
    public function testBucketRootListing(): void
    {
        $fs = $this->_filesystem([new Result()]);
        iterator_to_array($fs->getFileList());
        self::assertSame('', $this->_commands[0][1]['Prefix']);
    }

    /**
     * Verifies MIME types and avoids unsupported object ACLs.
     *
     * @author anuarhdz
     * @since 0.1.0
     */
    public function testWriteMimeType(): void
    {
        $fs = $this->_filesystem([new Result()]);
        $fs->write('photo.jpg', 'bytes', [R2::CONFIG_MIMETYPE => 'image/jpeg', R2::CONFIG_VISIBILITY => R2::VISIBILITY_PUBLIC]);
        self::assertSame('image/jpeg', $this->_commands[0][1]['ContentType']);
        self::assertSame('bytes', $this->_commands[0][1]['Body']);
        self::assertArrayNotHasKey('ACL', $this->_commands[0][1]);
    }

    /**
     * Verifies copy sources encode Unicode, spaces, and reserved characters.
     *
     * @author anuarhdz
     * @since 0.1.0
     */
    public function testCopySourceEncoding(): void
    {
        $fs = $this->_filesystem([new Result()], ['subfolder' => 'media']);
        $fs->copyFile('café #1?.jpg', 'new.jpg');
        self::assertSame(rawurlencode('test-bucket/media/café #1?.jpg'), $this->_commands[0][1]['CopySource']);
    }

    /**
     * Verifies a same-path rename cannot delete the source.
     *
     * @author anuarhdz
     * @since 0.1.0
     */
    public function testSamePathRenameIsANoop(): void
    {
        $fs = $this->_filesystem([]);
        $fs->renameFile('same.txt', 'same.txt');
        $fs->renameDirectory('parent/same', 'same');
        self::assertSame([], $this->_commands);
    }

    /**
     * Verifies every page is copied before any source object is deleted.
     *
     * @author anuarhdz
     * @since 0.1.0
     */
    public function testDirectoryRenameAcrossPages(): void
    {
        $fs = $this->_filesystem([
            new Result(['Contents' => [['Key' => 'old/a']], 'IsTruncated' => true, 'NextContinuationToken' => 'page2']),
            new Result(),
            new Result(['Contents' => [['Key' => 'old/b']], 'IsTruncated' => false]),
            new Result(), new Result(), new Result(),
        ]);
        $fs->renameDirectory('old', 'new');
        self::assertSame(['ListObjectsV2', 'CopyObject', 'ListObjectsV2', 'CopyObject', 'DeleteObject', 'DeleteObject'], array_column($this->_commands, 0));
        self::assertSame('new/b', $this->_commands[3][1]['Key']);
    }

    /**
     * Verifies a failed directory copy leaves every source untouched.
     *
     * @author anuarhdz
     * @since 0.1.0
     */
    public function testFailedRenameKeepsSourceObjects(): void
    {
        $fs = $this->_filesystem([
            new Result(['Contents' => [['Key' => 'old/a'], ['Key' => 'old/b']]]),
            new Result(), $this->_error(403),
        ]);
        try {
            $fs->renameDirectory('old', 'new');
            self::fail('Expected copy failure.');
        } catch (FsException) {
            self::assertNotContains('DeleteObject', array_column($this->_commands, 0));
        }
    }

    /**
     * Verifies deletion is limited to the directory prefix on all pages.
     *
     * @author anuarhdz
     * @since 0.1.0
     */
    public function testDirectoryDeletionAcrossPages(): void
    {
        $fs = $this->_filesystem([
            new Result(['Contents' => [['Key' => 'media/old/a']], 'IsTruncated' => true, 'NextContinuationToken' => 'page2']),
            new Result(),
            new Result(['Contents' => [['Key' => 'media/old/b']]]), new Result(),
        ], ['subfolder' => 'media']);
        $fs->deleteDirectory('old');
        self::assertSame('media/old/', $this->_commands[0][1]['Prefix']);
        self::assertSame('page2', $this->_commands[2][1]['ContinuationToken']);
        self::assertSame('media/old/b', $this->_commands[3][1]['Key']);
    }

    /**
     * Verifies destructive operations cannot target the filesystem root.
     *
     * @author anuarhdz
     * @since 0.1.0
     */
    public function testRootDeletionIsRejected(): void
    {
        $fs = $this->_filesystem([]);
        $this->expectException(FsException::class);
        $fs->deleteDirectory('/');
    }

    /**
     * Verifies missing source directories produce Craft's expected exception.
     *
     * @author anuarhdz
     * @since 0.1.0
     */
    public function testMissingDirectoryRename(): void
    {
        $fs = $this->_filesystem([new Result()]);
        $this->expectException(FsObjectNotFoundException::class);
        $fs->renameDirectory('missing', 'new');
    }

    /**
     * Verifies missing files are distinct from authorization failures.
     *
     * @author anuarhdz
     * @since 0.1.0
     */
    public function testFileNotFound(): void
    {
        $fs = $this->_filesystem([$this->_error(404)]);
        self::assertFalse($fs->fileExists('missing'));
    }

    /**
     * Verifies access errors are not misreported as missing files.
     *
     * @author anuarhdz
     * @since 0.1.0
     */
    public function testPermissionFailure(): void
    {
        $fs = $this->_filesystem([$this->_error(403)]);
        $this->expectException(FsException::class);
        $fs->fileExists('private');
    }

    /**
     * Verifies string reads, stream reads, and streamed uploads.
     *
     * @author anuarhdz
     * @since 0.1.0
     */
    public function testStreams(): void
    {
        $fs = $this->_filesystem([
            new Result(['Body' => Utils::streamFor('hello')]),
            new Result(['Body' => Utils::streamFor('stream')]), new Result(),
        ]);
        self::assertSame('hello', $fs->read('hello.txt'));
        $stream = $fs->getFileStream('stream.txt');
        self::assertIsResource($stream);
        self::assertSame('stream', stream_get_contents($stream));
        rewind($stream);
        $fs->writeFileFromStream('copy.txt', $stream, [R2::CONFIG_MIMETYPE => 'text/plain']);
        self::assertSame('text/plain', $this->_commands[2][1]['ContentType']);
        fclose($stream);
    }

    /**
     * Verifies pagination fails explicitly if the server omits a required token.
     *
     * @author anuarhdz
     * @since 0.1.0
     */
    public function testMalformedPagination(): void
    {
        $fs = $this->_filesystem([new Result(['IsTruncated' => true])]);
        $this->expectException(FsException::class);
        iterator_to_array($fs->getFileList());
    }

    /**
     * Verifies root directory existence does not issue an invalid S3 query.
     *
     * @author anuarhdz
     * @since 0.1.0
     */
    public function testRootExists(): void
    {
        self::assertTrue($this->_filesystem([])->directoryExists(''));
        self::assertSame([], $this->_commands);
    }

    /**
     * Verifies a missing prefix variable cannot redirect writes to the bucket root.
     *
     * @author anuarhdz
     * @since 0.1.0
     */
    public function testMissingSubfolderVariableIsRejected(): void
    {
        $fs = $this->_filesystem([], ['subfolder' => '$CRAFT_R2_TEST_UNDEFINED_PREFIX']);
        $this->expectException(FsException::class);
        $fs->write('file.txt', 'contents');
    }

    /**
     * Verifies parent segments cannot escape a configured object prefix.
     *
     * @author anuarhdz
     * @since 0.1.0
     */
    public function testParentPathIsRejected(): void
    {
        $fs = $this->_filesystem([], ['subfolder' => 'media']);
        $this->expectException(FsException::class);
        $fs->deleteFile('../outside.txt');
    }

    /**
     * Verifies creating the root cannot introduce a slash object in the bucket.
     *
     * @author anuarhdz
     * @since 0.1.0
     */
    public function testCreatingRootIsANoop(): void
    {
        $fs = $this->_filesystem([]);
        $fs->createDirectory('');
        $fs->createDirectory('/');
        self::assertSame([], $this->_commands);
    }

    /**
     * Verifies an explicit MIME type replaces copy metadata at the S3 API layer.
     *
     * @author anuarhdz
     * @since 0.1.0
     */
    public function testCopyMimeType(): void
    {
        $fs = $this->_filesystem([new Result(), new Result()]);
        $fs->copyFile('old.txt', 'new.txt', [R2::CONFIG_MIMETYPE => 'text/plain']);
        $fs->copyFile('old.txt', 'other.txt');
        self::assertSame('REPLACE', $this->_commands[0][1]['MetadataDirective']);
        self::assertSame('text/plain', $this->_commands[0][1]['ContentType']);
        self::assertArrayNotHasKey('MetadataDirective', $this->_commands[1][1]);
    }

    /**
     * Verifies a malformed endpoint cannot send signed requests to another host.
     *
     * @author anuarhdz
     * @since 0.1.0
     */
    public function testInvalidAccountCannotCreateClient(): void
    {
        $fs = new R2(['accountId' => 'example.invalid/?', 'accessKeyId' => 'test', 'secretAccessKey' => 'test']);
        $method = new \ReflectionMethod(R2::class, '_createClient');
        $this->expectException(FsException::class);
        $method->invoke($fs);
    }

    /**
     * Verifies the real SDK client uses the R2 endpoint without issuing a request.
     *
     * @author anuarhdz
     * @since 0.1.0
     */
    public function testClientConfiguration(): void
    {
        $fs = new R2(['accountId' => str_repeat('a', 32), 'accessKeyId' => 'test', 'secretAccessKey' => 'test']);
        $client = (new \ReflectionMethod(R2::class, '_createClient'))->invoke($fs);
        self::assertSame('https://' . str_repeat('a', 32) . '.r2.cloudflarestorage.com', (string)$client->getEndpoint());
        self::assertSame('auto', $client->getRegion());
        self::assertTrue($client->getConfig('use_path_style_endpoint'));
    }

    /**
     * Verifies a repeated token cannot leave workers in an endless listing loop.
     *
     * @author anuarhdz
     * @since 0.1.0
     */
    public function testRepeatedPaginationToken(): void
    {
        $page = new Result(['IsTruncated' => true, 'NextContinuationToken' => 'same']);
        $fs = $this->_filesystem([$page, $page]);
        $this->expectException(FsException::class);
        iterator_to_array($fs->getFileList());
    }

    /**
     * Verifies a missing bucket remains an operational error, not a missing file.
     *
     * @author anuarhdz
     * @since 0.1.0
     */
    public function testMissingBucketIsAnError(): void
    {
        $error = new AwsException('Simulated failure', new Command('HeadObject'), [
            'response' => new Response(404), 'code' => 'NoSuchBucket',
        ]);
        $fs = $this->_filesystem([$error]);
        $this->expectException(FsException::class);
        $fs->fileExists('file.txt');
    }

    /**
     * Verifies a response with an already detached body cannot return null to Craft.
     *
     * @author anuarhdz
     * @since 0.1.0
     */
    public function testDetachedStreamIsRejected(): void
    {
        $body = Utils::streamFor('detached');
        $resource = $body->detach();
        fclose($resource);
        $fs = $this->_filesystem([new Result(['Body' => $body])]);
        $this->expectException(FsException::class);
        $fs->getFileStream('file.txt');
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

    /**
     * Creates an SDK error with a deterministic HTTP status.
     *
     * @author anuarhdz
     * @since 0.1.0
     */
    private function _error(int $status): AwsException
    {
        return new AwsException('Simulated failure', new Command('HeadObject'), ['response' => new Response($status)]);
    }

    /**
     * Injects a mocked SDK transport; no real requests can leave this process.
     *
     * @author anuarhdz
     * @since 0.1.0
     */
    private function _filesystem(array $responses, array $config = []): R2
    {
        $mock = new MockHandler($responses);
        $client = new S3Client([
            'version' => 'latest', 'region' => 'auto', 'endpoint' => 'https://example.invalid',
            'credentials' => ['key' => 'test', 'secret' => 'test'],
            'handler' => function($command, $request) use ($mock) {
                $this->_commands[] = [$command->getName(), $command->toArray()];
                return $mock($command, $request);
            },
        ]);
        $fs = new R2(['bucket' => 'test-bucket'] + $config);
        $property = new \ReflectionProperty(R2::class, '_client');
        $property->setValue($fs, $client);
        return $fs;
    }
}
