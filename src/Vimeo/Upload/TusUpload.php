<?php

namespace Vimeify\Vimeo\Upload;

use Vimeify\Vimeo\Exceptions\VimeoUploadException;
use Vimeify\Vimeo\Vimeo;

/**
 * Uploads a file to an existing tus upload URL.
 *
 * Vimeo creates the upload when it creates the video and returns its `upload_link`, so only the
 * HEAD (offset) and PATCH (chunk) requests of the tus 1.0.0 core protocol are needed. Chunks are
 * streamed from the file, so memory use does not grow with the chunk size.
 *
 * @link https://tus.io/protocols/resumable-upload#core-protocol
 */
class TusUpload
{
    const TUS_VERSION = '1.0.0';

    /** @var string */
    private $url;

    /** @var string */
    private $file_path;

    /** @var array */
    private $curl_opts;

    /**
     * @param string $url The tus upload URL (Vimeo's `upload.upload_link`).
     * @param string $file_path Path to the file to upload.
     * @param array $curl_opts cURL options such as SSL, proxy and connection settings.
     */
    public function __construct(string $url, string $file_path, array $curl_opts = array())
    {
        $this->url = $url;
        $this->file_path = $file_path;
        $this->curl_opts = $curl_opts;
    }

    /**
     * Returns how many bytes of the file the server already has.
     *
     * @return int
     * @throws VimeoUploadException
     */
    public function getOffset(): int
    {
        $response = $this->send('HEAD');

        if ($response['status'] !== 200 && $response['status'] !== 204) {
            throw new VimeoUploadException('Unable to read the upload offset (HTTP ' . $response['status'] . ').');
        }

        return $this->readOffset($response['headers']);
    }

    /**
     * Sends up to `$chunk_size` bytes of the file, starting at `$offset`.
     *
     * @param int $offset The byte the server expects next.
     * @param int $chunk_size The most bytes to send.
     * @return int The new offset reported by the server.
     * @throws VimeoUploadException
     */
    public function uploadChunk(int $offset, int $chunk_size): int
    {
        $file_size = filesize($this->file_path);
        if ($file_size === false) {
            throw new VimeoUploadException('Unable to read the size of ' . $this->file_path . '.');
        }

        $length = min(max($chunk_size, 1), $file_size - $offset);
        if ($length <= 0) {
            return $offset;
        }

        $handle = fopen($this->file_path, 'rb');
        if ($handle === false || fseek($handle, $offset) !== 0) {
            throw new VimeoUploadException('Unable to read ' . $this->file_path . '.');
        }

        try {
            $response = $this->send('PATCH', array(
                'Upload-Offset: ' . $offset,
                'Content-Type: application/offset+octet-stream',
            ), $handle, $length);
        } finally {
            fclose($handle);
        }

        if ($response['status'] !== 204 && $response['status'] !== 200) {
            throw new VimeoUploadException('Unable to upload the file (HTTP ' . $response['status'] . ').');
        }

        $new_offset = $this->readOffset($response['headers']);
        if ($new_offset <= $offset) {
            throw new VimeoUploadException('The upload server did not accept any bytes.');
        }

        return $new_offset;
    }

    /**
     * @param string $method
     * @param array $headers
     * @param resource|null $body File handle positioned at the first byte to send.
     * @param int $length Bytes to send from `$body`.
     * @return array{status: int, headers: array}
     * @throws VimeoUploadException
     */
    private function send(string $method, array $headers = array(), $body = null, int $length = 0): array
    {
        $opts = array(
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => array_merge(array('Tus-Resumable: ' . self::TUS_VERSION, 'Expect:'), $headers),
            CURLOPT_HEADER => true,
            CURLOPT_RETURNTRANSFER => true,
        );

        if ($method === 'HEAD') {
            $opts[CURLOPT_NOBODY] = true;
        }

        if ($body !== null) {
            $opts[CURLOPT_UPLOAD] = true;
            $opts[CURLOPT_INFILE] = $body;
            $opts[CURLOPT_INFILESIZE] = $length;
            // A chunk can take far longer than an API call: drop the overall timeout and give
            // up only when the transfer stalls for a minute.
            $opts[CURLOPT_TIMEOUT] = 0;
            $opts[CURLOPT_LOW_SPEED_LIMIT] = 1;
            $opts[CURLOPT_LOW_SPEED_TIME] = 60;
        }

        $curl = curl_init($this->url);
        curl_setopt_array($curl, $opts + $this->curl_opts);
        $response = curl_exec($curl);
        $info = curl_getinfo($curl);
        $error = curl_error($curl);
        curl_close($curl);

        if (!is_string($response) || empty($info['http_code'])) {
            throw new VimeoUploadException('Unable to reach the upload server.' . ($error !== '' ? ' [' . $error . ']' : ''));
        }
        $header_size = (int)$info['header_size'];

        return array(
            'status' => (int)$info['http_code'],
            'headers' => Vimeo::parse_headers((string)substr((string)$response, 0, $header_size)),
        );
    }

    /**
     * @param array $headers
     * @return int
     * @throws VimeoUploadException
     */
    private function readOffset(array $headers): int
    {
        foreach ($headers as $name => $value) {
            if (strtolower($name) === 'upload-offset' && is_numeric($value)) {
                return (int)$value;
            }
        }

        throw new VimeoUploadException('The upload server did not report an Upload-Offset.');
    }
}
