<?php
declare(strict_types=1);

// The browser API uses its own authenticated JSON boundary.
// It must never be included for agent requests.
use Chronos\Http\HttpError;
use Chronos\Http\Api\Ui\Kernel;
use Chronos\Http\Request;
use Chronos\Infrastructure\Security\Sessions;

$uiComplete = false;
ob_start(static function (string $output) use (&$uiComplete): string {
    // An interrupted request must still produce a generic JSON response.
    if (!$uiComplete) {
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
    $uiSession = new Sessions($auth);
    $uiResponse = (new Kernel(Request::fromGlobals(), $uiSession, SERVER_ROOT))->handle();
} catch (HttpError $uiError) {
    $uiResponse = ['status' => $uiError->status, 'body' => $uiError->body()];
    foreach ($uiError->headers as $uiName => $uiValue) {
        header($uiName . ': ' . $uiValue);
    }
} catch (Throwable $uiError) {
    error_log('Chronos UI API request failed: ' . get_class($uiError));
    $uiResponse = ['status' => 500, 'body' => ['error' => ['code' => 'internal_error', 'message' => 'The request could not be completed.']]];
}
if (isset($uiSession)) {
    $uiSession->hardenCookies();
}
ob_clean();
http_response_code($uiResponse['status']);
if (isset($uiResponse['stream'])) {
    foreach ($uiResponse['headers'] ?? [] as $uiName => $uiValue) {
        header($uiName . ': ' . $uiValue);
    }
    $uiComplete = true;
    ob_end_clean();
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    ($uiResponse['stream'])();
    exit;
}
$uiJson = json_encode($uiResponse['body'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
$uiComplete = true;
echo $uiJson;
ob_end_flush();
exit;
