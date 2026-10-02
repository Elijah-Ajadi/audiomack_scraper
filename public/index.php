<?php
declare(strict_types=1);

use Audiomack\AudiomackScraper;

require dirname(__DIR__) . '/vendor/autoload.php';

$startedAt = hrtime(true);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

$respond = static function (array $body, int $status = 200) use ($startedAt): never {
    $elapsed = (hrtime(true) - $startedAt) / 1_000_000_000;
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Process-Time: ' . number_format($elapsed, 4, '.', '') . 's');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
};

$respondHtml = static function (string $body, int $status = 200) use ($startedAt): never {
    $elapsed = (hrtime(true) - $startedAt) / 1_000_000_000;
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    header('X-Process-Time: ' . number_format($elapsed, 4, '.', '') . 's');
    echo $body;
    exit;
};

if ($method !== 'GET') {
    header('Allow: GET');
    $respond(['detail' => 'Method not allowed'], 405);
}

if ($path === '/docs') {
    $respondHtml(<<<'HTML'
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>Audiomack API - Swagger UI</title>
            <link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist@5/swagger-ui.css">
            <style>
                html { box-sizing: border-box; overflow-y: scroll; }
                *, *::before, *::after { box-sizing: inherit; }
                body { margin: 0; background: #f5f7f8; }
                .swagger-ui .topbar { background: #17191c; }
                .swagger-ui .topbar-wrapper .link { display: none; }
                .swagger-ui .information-container { padding-top: 20px; }
            </style>
        </head>
        <body>
            <div id="swagger-ui"></div>
            <script src="https://unpkg.com/swagger-ui-dist@5/swagger-ui-bundle.js"></script>
            <script>
                window.ui = SwaggerUIBundle({
                    url: '/openapi.json',
                    dom_id: '#swagger-ui',
                    deepLinking: true,
                    displayRequestDuration: true,
                    presets: [SwaggerUIBundle.presets.apis],
                    layout: 'BaseLayout'
                });
            </script>
        </body>
        </html>
        HTML);
}

if ($path === '/openapi.json') {
    $respond(Audiomack\OpenApiSpec::document());
}

if ($path === '/') {
    $respond(['success' => true, 'message' => 'Audiomack API is running']);
}

if ($path !== '/song' && $path !== '/album') {
    $respond(['detail' => 'Not found'], 404);
}

if (!isset($_GET['url']) || !is_string($_GET['url'])) {
    $respond(['detail' => 'A URL query parameter is required.'], 422);
}

$url = $_GET['url'];
if (!str_contains($url, 'audiomack.com')) {
    $respond(['detail' => 'Invalid Audiomack URL'], 400);
}
if ($path === '/song' && !str_contains(strtolower($url), '/song/')) {
    $respond([
        'detail' => 'URL must be an Audiomack song URL. For album tracks, use /album with the track parameter, for example /album?url=...&track=1.',
    ], 400);
}

$scraper = new AudiomackScraper();
$startedExtraction = hrtime(true);

try {
    if ($path === '/song') {
        $result = $scraper->extractTrackInfo($url);
    } else {
        if (!str_contains(strtolower($url), '/album/')) {
            $respond(['detail' => 'URL must be an Audiomack album URL'], 400);
        }

        $selectedTrack = null;
        if (isset($_GET['track'])) {
            $selectedTrack = filter_var($_GET['track'], FILTER_VALIDATE_INT);
            if ($selectedTrack === false) {
                $respond(['detail' => 'track must be an integer'], 422);
            }
            if ($selectedTrack < 1) {
                $respond(['detail' => 'track must be 1 or greater'], 400);
            }
        }

        $result = $scraper->extractAlbumInfo($url, $selectedTrack);
    }

    $elapsed = (hrtime(true) - $startedExtraction) / 1_000_000_000;
    $respond([
        'success' => true,
        'execution_time' => number_format($elapsed, 2, '.', '') . 's',
        'data' => $result,
    ]);
} catch (RuntimeException $error) {
    $status = $path === '/album' && (
        str_contains($error->getMessage(), 'Track number')
        || str_contains($error->getMessage(), 'Track ')
        || str_contains($error->getMessage(), 'Album URL')
        || str_contains($error->getMessage(), 'URL must be')
    ) ? 404 : 500;
    $respond(['detail' => $error->getMessage()], $status);
} catch (Throwable $error) {
    $respond(['detail' => $error->getMessage()], 500);
}