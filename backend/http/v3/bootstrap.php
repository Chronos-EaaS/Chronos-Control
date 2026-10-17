<?php
declare(strict_types=1);

// This boundary is deliberately independent of the legacy API serializer/router.
// It must never be included for /api/v1 or /api/ui requests.
use Chronos\Http\HttpError;
use Chronos\Http\Api\V3\Kernel;
use Chronos\Http\Request;
use Chronos\Infrastructure\Security\Sessions;

$v3Complete = false;
ob_start(static function (string $output) use (&$v3Complete): string {
    // Legacy PDO factories die on connection failure. Suppress their raw output
    // and still provide a generic API error when an uncatchable early exit occurs.
    if (!$v3Complete) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        return '{"error":{"code":"internal_error","message":"The request could not be completed."}}';
    }
    return $output;
});
ini_set('display_errors', '0');
date_default_timezone_set('Europe/Zurich');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Vary: Cookie');

try {
    $v3Session = new Sessions($auth);
    $v3Response = (new Kernel(Request::fromGlobals(), $v3Session, SERVER_ROOT))->handle();
} catch (HttpError $v3Error) {
    $v3Response = ['status' => $v3Error->status, 'body' => $v3Error->body()];
    foreach ($v3Error->headers as $v3Name => $v3Value) {
        header($v3Name . ': ' . $v3Value);
    }
} catch (Throwable $v3Error) {
    error_log('Chronos v3 request failed: ' . get_class($v3Error));
    $v3Response = ['status' => 500, 'body' => ['error' => ['code' => 'internal_error', 'message' => 'The request could not be completed.']]];
}
if (isset($v3Session)) {
    $v3Session->hardenCookies();
}
ob_clean();
http_response_code($v3Response['status']);
if (isset($v3Response['stream'])) {
    foreach ($v3Response['headers'] ?? [] as $v3Name => $v3Value) {
        header($v3Name . ': ' . $v3Value);
    }
    $v3Complete = true;
    ob_end_clean();
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    ($v3Response['stream'])();
    exit;
}
$v3Json = json_encode($v3Response['body'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
$v3Complete = true;
echo $v3Json;
ob_end_flush();
exit;
