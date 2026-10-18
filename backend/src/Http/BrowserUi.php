<?php
declare(strict_types=1);

namespace Chronos\Http;

use Chronos\Http\Api\Ui\ApiPath;

/** Public SPA routing contains no database, session, or write operations. */
final class BrowserUi
{
    public static function legacyLocation(string $path): ?string
    {
        $path = rtrim($path, '/') ?: '/';
        $fixed = ['/home/main' => '/', '/user/login' => '/login', '/user/logout' => '/profile', '/project/overview' => '/projects', '/project/create' => '/projects?create=1', '/admin/systems' => '/systems', '/admin/createSystem' => '/systems?create=1', '/admin/main' => '/admin', '/admin/newUser' => '/admin?createUser=1', '/admin/update' => '/admin', '/admin/demo' => '/admin', '/admin/mountDataDirectory' => '/admin', '/cem/overview' => '/cem', '/evaluation/overview' => '/status', '/job/jobs' => '/status?view=jobs'];
        if (isset($fixed[$path])) return $fixed[$path];
        if (preg_match('#^/(project|experiment|evaluation|job)/detail/id=([1-9][0-9]*)(?:/.*)?$#D', $path, $match)) return '/' . $match[1] . 's/' . $match[2];
        if (preg_match('#^/admin/system(?:Import|Update)?/id=([1-9][0-9]*)(?:/.*)?$#D', $path, $match)) return '/systems/' . $match[1];
        if (preg_match('#^/admin/systemExport/id=([1-9][0-9]*)$#D', $path, $match)) return '/api/ui/systems/' . $match[1] . '/export';
        if (preg_match('#^/user/edit/id=([1-9][0-9]*)$#D', $path, $match)) return '/admin/users/' . $match[1];
        if ($path === '/user/edit') return '/profile';
        if (preg_match('#^/builder/(?:build|index)/systemId=([1-9][0-9]*)$#D', $path, $match)) return '/systems/' . $match[1] . '?tab=parameters';
        if (preg_match('#^/builder/create/projectId=([1-9][0-9]*)(?:/copy=([1-9][0-9]*))?$#D', $path, $match)) return '/projects/' . $match[1] . '/experiments/new' . (isset($match[2]) ? '?copy=' . $match[2] : '');
        if (preg_match('#^/results/build/(systemId|experimentId)=([1-9][0-9]*)/type=([12])/resultId=([^/]+)$#D', $path, $match)) return '/' . ($match[1] === 'systemId' ? 'systems' : 'experiments') . '/' . $match[2] . '?tab=results&configuration=' . rawurlencode(rawurldecode($match[4])) . '&scope=' . ($match[3] === '1' ? 'all' : 'job');
        if (preg_match('#^/results/show/id=([1-9][0-9]*)$#D', $path, $match)) return '/evaluations/' . $match[1] . '?tab=results';
        if (preg_match('#^/evaluation/download/id=([1-9][0-9]*)$#D', $path, $match)) return '/api/ui/evaluations/' . $match[1] . '/download';
        if (preg_match('#^/cem/detail/id=([^/]+)$#D', $path, $match)) return '/cem/nodes/' . rawurlencode(rawurldecode($match[1]));
        return null;
    }

    public static function serve(string $root, string $path): bool
    {
        $directory = $root . '/webroot/ui';
        if (preg_match('#^/api/v1(?:/|$)#D', $path)) return false;
        if (ApiPath::relative($path) !== null) return false;
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        if (str_starts_with($path, '/api/')) {
            http_response_code(404);
            header('Content-Type: application/json; charset=utf-8');
            echo '{"error":{"code":"api_not_available","message":"Use the authenticated browser API at /api/ui/."}}';
            return true;
        }
        $location = self::legacyLocation($path);
        if ($location !== null) { header('Location: ' . $location, true, 302); return true; }
        if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
            http_response_code(405); header('Allow: GET, HEAD'); return true;
        }
        if (!is_file($directory . '/index.html')) {
            http_response_code(503); header('Retry-After: 60');
            header('Content-Type: text/html; charset=utf-8');
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Chronos UI unavailable</title><body><main><h1>Chronos UI build is unavailable</h1><p>Install the matching Chronos build package and reload this page. Your stored experiments and results are retained.</p></main></body></html>';
            return true;
        }
        // Only build output is public. System definitions, uploaded archives and
        // server files are delivered exclusively through authorized API routes.
        $relative = preg_replace('#^/ui/#', '/', $path);
        $file = realpath($directory . $relative);
        $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
        $mime = ['js' => 'text/javascript', 'css' => 'text/css', 'json' => 'application/json', 'svg' => 'image/svg+xml', 'png' => 'image/png', 'ico' => 'image/x-icon', 'woff2' => 'font/woff2', 'txt' => 'text/plain'];
        if ($file !== false && str_starts_with($file, realpath($directory) . '/') && is_file($file) && isset($mime[$extension])) {
            header('Content-Type: ' . $mime[$extension] . '; charset=utf-8');
            if (preg_match('/-[A-Za-z0-9_-]{8,}\.(?:js|css)$/D', $relative)) header('Cache-Control: public, max-age=31536000, immutable');
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') readfile($file);
            return true;
        }
        if ($extension !== '' || preg_match('#^/(?:data|media)/#', $path)) {
            http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); echo 'File not found.'; return true;
        }
        header('Content-Type: text/html; charset=utf-8');
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') readfile($directory . '/index.html');
        return true;
    }
}
