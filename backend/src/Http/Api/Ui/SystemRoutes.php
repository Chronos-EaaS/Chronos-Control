<?php
declare(strict_types=1);

namespace Chronos\Http\Api\Ui;

use Chronos\Http\JsonResponse;

use Chronos\Http\HttpError;
use Chronos\Http\Request;
use Chronos\Application\Shared\Input;
use Chronos\Application\Shared\Context;
use Chronos\Infrastructure\Persistence\Store;
use Chronos\Infrastructure\Security\Sessions;

use Chronos\Application\Results\{PreparedDownload, ResultConfigurations, ResultDownloads, ResultPresentation};
use Chronos\Application\Systems\{ConfigurationStore, RepositoryService, SystemFiles, SystemSettings};

/** HTTP dispatch only; domain operations accept explicit input rather than Request. */
final class SystemRoutes
{
    private readonly ConfigurationStore $definitions;
    private readonly SystemSettings $settings;
    private readonly SystemFiles $files;
    private readonly ResultConfigurations $configurations;
    private readonly ResultPresentation $results;
    private readonly ResultDownloads $downloads;
    private readonly RepositoryService $repositories;

    public function __construct(private readonly Request $request, Store $db, array $actor, string $root, Sessions $sessions)
    {
        $context = new Context($db, $actor, $root);
        $this->definitions = new ConfigurationStore($context, $root);
        $this->settings = new SystemSettings($context, $root);
        $this->files = new SystemFiles($context, $root);
        $this->configurations = new ResultConfigurations($context, $root);
        $this->results = new ResultPresentation($context, $root);
        $this->downloads = new ResultDownloads($context);
        $this->repositories = new RepositoryService($context, $root);
    }

    public function handle(string $path): ?array
    {
        if (preg_match('#^/experiments/([1-9][0-9]*)/result-configurations/push$#D', $path, $match)) {
            $this->request->assertMethod(['POST']); $this->emptyBody();
            return JsonResponse::success($this->repositories->pushExperiment(Input::id($match[1], 'experimentId')));
        }
        if (preg_match('#^/experiments/([1-9][0-9]*)/result-configurations$#D', $path, $match)) {
            $this->request->assertMethod(['GET', 'PUT']);
            $id = Input::id($match[1], 'experimentId');
            return JsonResponse::success($this->request->method === 'PUT'
                ? $this->configurations->saveExperiment($id, $this->request->json())
                : $this->configurations->experiment($id));
        }
        if (preg_match('#^/systems/([1-9][0-9]*)(?:/(parameters|result-configurations|settings|environments|logo|export|import|history|repository|repository/update|repository/push|result-configurations/migrate))?$#D', $path, $match)) {
            return $this->system(Input::id($match[1], 'systemId'), $match[2] ?? '');
        }
        if (preg_match('#^/(evaluations|jobs)/([1-9][0-9]*)/(results|download|results.csv)$#D', $path, $match)) {
            $this->request->assertMethod(['GET']); $id = Input::id($match[2], 'id');
            $job = $match[1] === 'jobs';
            if ($match[3] === 'download') return $this->download($job ? $this->downloads->job($id) : $this->downloads->evaluation($id));
            if ($match[3] === 'results.csv') return $this->download($job ? $this->downloads->jobCsv($id) : $this->downloads->evaluationCsv($id));
            return JsonResponse::success($job ? $this->results->job($id, $this->request->query) : $this->results->evaluation($id, $this->request->query));
        }
        return null;
    }

    private function system(int $id, string $action): array
    {
        switch ($action) {
            case '':
                $this->request->assertMethod(['GET', 'PATCH']);
                return JsonResponse::success($this->request->method === 'PATCH' ? $this->settings->update($id, $this->request->json()) : $this->settings->read($id));
            case 'parameters':
                $this->request->assertMethod(['GET', 'PUT']);
                return JsonResponse::success($this->request->method === 'PUT' ? $this->definitions->saveParameters($id, $this->request->json()) : $this->definitions->parameters($id));
            case 'result-configurations':
                $this->request->assertMethod(['GET', 'PUT']);
                return JsonResponse::success($this->request->method === 'PUT' ? $this->configurations->saveSystem($id, $this->request->json()) : $this->configurations->system($id));
            case 'result-configurations/migrate':
                $this->request->assertMethod(['POST']); $this->emptyBody();
                return JsonResponse::success($this->configurations->migrate($id));
            case 'settings':
                $this->request->assertMethod(['GET', 'PUT']);
                return JsonResponse::success($this->request->method === 'PUT' ? $this->settings->replaceSettings($id, $this->request->json()) : $this->settings->settings($id));
            case 'environments':
                $this->request->assertMethod(['GET', 'PUT']);
                return JsonResponse::success($this->request->method === 'PUT' ? $this->settings->replaceEnvironments($id, $this->request->json()) : $this->settings->environments($id));
            case 'logo':
                $this->request->assertMethod(['GET', 'PUT']);
                return $this->request->method === 'PUT' ? JsonResponse::success($this->files->saveLogo($id, $this->request->json())) : $this->download($this->files->logo($id));
            case 'import':
                $this->request->assertMethod(['POST']);
                return JsonResponse::success($this->files->import($id, $this->request->json()));
            case 'export':
                $this->request->assertMethod(['GET']);
                return $this->download($this->files->export($id));
            case 'repository/push':
            case 'repository/update':
                $this->request->assertMethod(['POST']); $this->emptyBody();
                if ($action === 'repository/push') $this->repositories->push($id); else $this->repositories->update($id);
                return JsonResponse::success($this->repositories->history($id, $this->request->query));
            case 'repository':
            case 'history':
                $this->request->assertMethod(['GET']);
                return JsonResponse::success($this->repositories->history($id, $this->request->query));
        }
        throw new HttpError(404, 'not_found', 'The system resource does not exist.');
    }

    private function emptyBody(): void { Input::fields($this->request->json(), []); }

    private function download(PreparedDownload $download): array
    {
        $headers = ['Content-Type' => $download->contentType, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'no-store'];
        if ($download->filename !== null) $headers['Content-Disposition'] = 'attachment; filename="' . $download->filename . '"';
        return ['status' => 200, 'headers' => $headers, 'stream' => $download->stream];
    }

}
