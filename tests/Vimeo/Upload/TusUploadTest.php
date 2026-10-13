<?php

namespace Vimeify\Vimeo\Upload;

use PHPUnit\Framework\TestCase;
use Vimeify\Vimeo\Exceptions\VimeoUploadException;
use Vimeify\Vimeo\Vimeo;

/**
 * Runs the tus client against tests/fixtures/tus-server.php on PHP's built-in web server.
 */
class TusUploadTest extends TestCase
{
    /** @var resource */
    private static $server;

    /** @var string */
    private static $base_url;

    /** @var string */
    private static $dir;

    /** @var string */
    private $source;

    public static function setUpBeforeClass(): void
    {
        self::$dir = sys_get_temp_dir() . '/vimeo-tus-' . uniqid();
        mkdir(self::$dir);

        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        self::$base_url = 'http://' . $address;

        $command = 'exec ' . escapeshellarg(PHP_BINARY) . ' -S ' . escapeshellarg($address) . ' '
            . escapeshellarg(dirname(__DIR__, 2) . '/fixtures/tus-server.php');
        $env = array_merge(getenv(), array('TUS_TEST_DIR' => self::$dir));
        self::$server = proc_open($command, array(array('pipe', 'r'), array('file', '/dev/null', 'w'), array('file', '/dev/null', 'w')), $pipes, null, $env);

        for ($i = 0; $i < 50; $i++) {
            $connection = @fsockopen('127.0.0.1', (int)substr(strrchr($address, ':'), 1));
            if ($connection) {
                fclose($connection);
                return;
            }
            usleep(100000);
        }

        self::fail('The test tus server did not start.');
    }

    public static function tearDownAfterClass(): void
    {
        proc_terminate(self::$server);
        array_map('unlink', glob(self::$dir . '/*') ?: array());
        rmdir(self::$dir);
    }

    protected function setUp(): void
    {
        $this->source = self::$dir . '/source-' . uniqid() . '.mp4';
        file_put_contents($this->source, random_bytes(2621440)); // 2.5 MB
    }

    public function testUploadsTheFileInChunks(): void
    {
        $id = uniqid('chunks');
        $upload = new TusUpload(self::$base_url . '/files/' . $id, $this->source);

        $offset = $upload->getOffset();
        $requests = 0;
        while ($offset < filesize($this->source)) {
            $offset = $upload->uploadChunk($offset, 1048576);
            $requests++;
        }

        $this->assertSame(3, $requests);
        $this->assertStored($id);
    }

    public function testPerformUploadTusReturnsTheVideoUri(): void
    {
        $id = uniqid('vimeo');
        $vimeo = new TestableVimeo();

        $this->assertSame('/videos/123', $vimeo->uploadTus($this->source, self::attempt($id)));
        $this->assertStored($id);
    }

    public function testResumesFromTheServerOffset(): void
    {
        $id = uniqid('resume');
        file_put_contents(self::$dir . '/' . $id . '.bin', file_get_contents($this->source, false, null, 0, 1048576));

        $vimeo = new TestableVimeo();
        $vimeo->uploadTus($this->source, self::attempt($id));

        $this->assertStored($id);
    }

    public function testRetriesAFailedChunk(): void
    {
        $id = uniqid('retry');
        file_put_contents(self::$dir . '/' . $id . '.fail', '1');

        $vimeo = new TestableVimeo();
        $vimeo->uploadTus($this->source, self::attempt($id));

        $this->assertSame(array(1), $vimeo->waits);
        $this->assertStored($id);
    }

    public function testResumesAfterAChunkIsPartlyStored(): void
    {
        $id = uniqid('partial');
        file_put_contents(self::$dir . '/' . $id . '.partial', '1');

        $vimeo = new TestableVimeo();
        $vimeo->uploadTus($this->source, self::attempt($id));

        $this->assertSame(array(1), $vimeo->waits);
        $this->assertStored($id);
    }

    public function testGivesUpAfterThreeRetries(): void
    {
        $id = uniqid('down');
        file_put_contents(self::$dir . '/' . $id . '.fail', '10');

        $vimeo = new TestableVimeo();
        try {
            $vimeo->uploadTus($this->source, self::attempt($id));
            $this->fail('The upload should have failed.');
        } catch (VimeoUploadException $e) {
            $this->assertSame(array(1, 2, 3), $vimeo->waits);
        }
    }

    public function testRejectsAChunkAtTheWrongOffset(): void
    {
        $upload = new TusUpload(self::$base_url . '/files/' . uniqid('offset'), $this->source);

        $this->expectException(VimeoUploadException::class);
        $upload->uploadChunk(1024, 1024);
    }

    /**
     * @param string $id
     * @return array
     */
    private static function attempt(string $id): array
    {
        return array('body' => array(
            'uri' => '/videos/123',
            'upload' => array('upload_link' => self::$base_url . '/files/' . $id),
        ));
    }

    private function assertStored(string $id): void
    {
        $this->assertSame(sha1_file($this->source), sha1_file(self::$dir . '/' . $id . '.bin'));
    }
}

/**
 * Exposes the tus upload, sends 1 MB chunks and records retries instead of sleeping.
 */
class TestableVimeo extends Vimeo
{
    /** @var int[] */
    public $waits = array();

    public function __construct()
    {
        parent::__construct('client_id', 'client_secret', 'token');
    }

    public function uploadTus(string $file_path, array $attempt): string
    {
        return $this->perform_upload_tus($file_path, filesize($file_path), $attempt);
    }

    protected function getTusUpload(string $url, string $file_path): TusUpload
    {
        return new SmallChunkTusUpload($url, $file_path, $this->getCURLOptions() + array(CURLOPT_TIMEOUT => 30));
    }

    protected function waitBeforeRetry(int $failures): void
    {
        $this->waits[] = $failures;
    }
}

class SmallChunkTusUpload extends TusUpload
{
    public function uploadChunk(int $offset, int $chunk_size): int
    {
        return parent::uploadChunk($offset, min($chunk_size, 1048576));
    }
}
