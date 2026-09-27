<?php

$publicPath = getcwd();

$uri = urldecode(
    parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? ''
);

// This file allows us to emulate Apache's "mod_rewrite" functionality from the
// built-in PHP web server. This provides a convenient way to test a Laravel
// application without having installed a "real" web server software here.
//
// `php artisan serve`'s built-in PHP server serves an existing file (like
// anything under the `public/storage` symlink) directly, below, WITHOUT ever
// routing through `index.php` — so Laravel's `HandleCors` middleware and
// `config/cors.php` never run for it. That's fine for a same-origin caller,
// but the guest app running as Flutter Web has its own dev-server origin, so
// the browser needs an explicit `Access-Control-Allow-Origin` header on these
// static asset responses (e.g. `cover_url` / `gallery[].url` / `logo_url`) or
// it silently refuses to read the image bytes. Dev-only: a real deployment's
// web server (nginx/Apache) or CDN is configured for this separately.
if ($uri !== '/' && file_exists($publicPath.$uri)) {
    // `header()` calls made here do not reliably survive the built-in
    // server's own static-file responder once this script returns `false`,
    // so `/storage/*` is served explicitly instead — the only way to
    // guarantee the CORS header actually reaches the browser.
    if (str_starts_with($uri, '/storage/')) {
        $path = $publicPath.$uri;
        header('Access-Control-Allow-Origin: *');
        header('Content-Type: '.(mime_content_type($path) ?: 'application/octet-stream'));
        header('Content-Length: '.filesize($path));
        readfile($path);

        return true;
    }

    return false;
}

$formattedDateTime = date('D M j H:i:s Y');

$requestMethod = $_SERVER['REQUEST_METHOD'];
$remoteAddress = $_SERVER['REMOTE_ADDR'].':'.$_SERVER['REMOTE_PORT'];

file_put_contents('php://stdout', "[$formattedDateTime] $remoteAddress [$requestMethod] URI: $uri\n");

require_once $publicPath.'/index.php';
