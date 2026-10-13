<?php

/**
 * Router for PHP's built-in web server that implements the tus 1.0.0 core protocol for tests.
 *
 * Each upload is stored as `<TUS_TEST_DIR>/<id>.bin`, where `<id>` is the last path segment.
 * Tests control failures through files next to it:
 * - `<id>.fail`: the next N PATCH requests fail with HTTP 500 without storing anything.
 * - `<id>.partial`: the next N PATCH requests store half of their bytes, then fail with HTTP 500.
 */

$dir = getenv('TUS_TEST_DIR') ?: sys_get_temp_dir() . '/vimeo-tus-test';
$id = basename((string)parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH));
$data = $dir . '/' . $id . '.bin';
$offset = file_exists($data) ? filesize($data) : 0;

/**
 * Consumes one of the remaining failures stored in `$file`.
 */
$take = function (string $file): bool {
    $left = file_exists($file) ? (int)file_get_contents($file) : 0;
    if ($left <= 0) {
        return false;
    }
    file_put_contents($file, (string)($left - 1));

    return true;
};

header('Tus-Resumable: 1.0.0');

if (($_SERVER['HTTP_TUS_RESUMABLE'] ?? '') !== '1.0.0') {
    http_response_code(412);
    return true;
}

switch ($_SERVER['REQUEST_METHOD'] ?? '') {
    case 'HEAD':
        header('Upload-Offset: ' . $offset);
        header('Cache-Control: no-store');
        http_response_code(200);
        return true;

    case 'PATCH':
        if (($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '') !== 'application/offset+octet-stream') {
            http_response_code(415);
            return true;
        }
        if ((int)($_SERVER['HTTP_UPLOAD_OFFSET'] ?? -1) !== $offset) {
            http_response_code(409);
            return true;
        }
        if ($take($dir . '/' . $id . '.fail')) {
            http_response_code(500);
            return true;
        }

        $body = (string)file_get_contents('php://input');
        $partial = $take($dir . '/' . $id . '.partial');
        if ($partial) {
            $body = substr($body, 0, (int)(strlen($body) / 2));
        }
        file_put_contents($data, $body, FILE_APPEND);
        clearstatcache();

        if ($partial) {
            http_response_code(500);
            return true;
        }

        header('Upload-Offset: ' . filesize($data));
        http_response_code(204);
        return true;
}

http_response_code(405);
return true;
