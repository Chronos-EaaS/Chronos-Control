<?php
declare(strict_types=1);

namespace Chronos\Application\Maintenance;

use Chronos\Application\Shared\Context;
use Chronos\Http\HttpError;
use Chronos\Application\Shared\Input;
use Chronos\Application\Security\Policy;
use Chronos\Infrastructure\Persistence\Store;
use Chronos\Infrastructure\Security\Sessions;
use Chronos\Infrastructure\VersionControl\Repository;
use Chronos\Infrastructure\Persistence\GlobalSettings;

final class MaintenanceService
{
    private readonly Store $db;
    private readonly array $actor;
    private readonly string $root;
    private readonly GlobalSettings $settings;

    public function __construct(private readonly Context $context, private readonly Sessions $sessions, private readonly AdminOperationExecutor $operations)
    {
        $this->db = $context->db;
        $this->actor = $context->actor;
        $this->root = $context->root;
        $this->settings = new GlobalSettings($this->db);
    }

    public function storage(): array
    {
        $path = defined('UPLOADED_DATA_PATH') ? rtrim(UPLOADED_DATA_PATH, '/') : $this->root . '/webroot/data';
        $exists = is_dir($path);
        $stat = $exists ? @stat($path) : false;
        $parent = $exists ? @stat(dirname($path)) : false;
        $free = $exists ? @disk_free_space($path) : false;
        $total = $exists ? @disk_total_space($path) : false;
        return [
            'path' => $path, 'exists' => $exists, 'readable' => $exists && is_readable($path),
            'writable' => $exists && is_writable($path),
            'mounted' => $stat !== false && $parent !== false ? $stat['dev'] !== $parent['dev'] : null,
            'freeBytes' => $free === false ? null : (int) $free, 'totalBytes' => $total === false ? null : (int) $total,
            'checkedAt' => date(DATE_ATOM),
        ];
    }

    private function branches(): array
    {
        [$code, $output] = Repository::run($this->root, ['for-each-ref', '--format=%(refname:short)', 'refs/heads', 'refs/remotes/origin']);
        $branches = $code === 0 ? preg_split('/\R/', trim($output)) : [];
        $branches = array_map(static fn(string $branch): string => preg_replace('#^origin/#', '', $branch), $branches ?: []);
        $valid = static fn(string $branch): bool => $branch !== 'HEAD' && preg_match('#^[A-Za-z0-9][A-Za-z0-9._/-]{0,199}$#D', $branch) === 1
            && !str_contains($branch, '..') && !str_contains($branch, '//') && !str_ends_with($branch, '/') && !str_ends_with($branch, '.lock');
        $branches = array_values(array_unique(array_filter($branches, $valid)));
        $selected = $this->settings->get('vcs', 'repoBranch', 'master');
        if ($valid($selected) && !in_array($selected, $branches, true)) {
            $branches[] = $selected;
        }
        sort($branches, SORT_NATURAL | SORT_FLAG_CASE);
        return $branches;
    }

    private function branch(mixed $value): string
    {
        $branch = Input::text($value, 'branch', 200);
        if (!preg_match('#^[A-Za-z0-9][A-Za-z0-9._/-]{0,199}$#D', $branch) || $branch === 'HEAD'
            || str_contains($branch, '..') || str_contains($branch, '//') || str_ends_with($branch, '/') || str_ends_with($branch, '.lock')) {
            Input::invalid('branch', 'Enter a valid repository branch. The update plan verifies its published build.');
        }
        return $branch;
    }

    private function simulationsAvailable(): bool
    {
        return getenv('CHRONOS_TEST_STACK') === '1' && is_file('/.dockerenv');
    }

    public function maintenance(): array
    {
        $capabilities = $this->operations->capabilities();
        return [
            'branches' => $this->branches(), 'selectedBranch' => $this->settings->get('vcs', 'repoBranch', 'master'),
            'installedVersion' => defined('APP_VERSION') ? APP_VERSION : 'unknown',
            'simulationAvailable' => $this->simulationsAvailable(), 'executionAvailable' => in_array(true, $capabilities, true),
            'executionMessage' => 'Operation availability depends on the configured deployment, storage and demo services.',
            'capabilities' => $capabilities,
            'actions' => ['update', 'rollback', 'mount', 'reset-demo', 'reset-local-systems'],
        ];
    }

    public function planOperation(array $body): array
    {
        Input::fields($body, ['action', 'branch']);
        $action = Input::text($body['action'] ?? null, 'action', 30);
        $definitions = [
            'update' => ['Review application update', 'UPDATE CHRONOS', ['Verify the required PHP runtime, selected CI package and build identity.', 'Create a recoverable deployment snapshot.', 'Install the verified files, record the selected branch and reopen the application.']],
            'rollback' => ['Review application rollback', 'ROLL BACK CHRONOS', ['Verify the selected deployment snapshot and backup file hashes.', 'Restore the previous package files and release metadata.', 'Restore the previous entrypoint last, then reopen the application.']],
            'mount' => ['Review storage mount', 'MOUNT STORAGE', ['Verify the configured data directory and mount configuration.', 'Mount the configured storage using the approved host executor.', 'Verify the resulting mount and file access.']],
            'reset-demo' => ['Review demo reset', 'RESET DEMO', ['Replace demo application data with its initial dataset.', 'Reset demo system definitions in the demo environment.', 'Invalidate affected user sessions.']],
            'reset-local-systems' => ['Review local system reset', 'RESET LOCAL SYSTEMS', ['Identify systems without a remote repository.', 'Back up their current definitions.', 'Reinitialize only the selected local system definitions.']],
        ];
        if (!isset($definitions[$action])) {
            Input::invalid('action', 'Choose a supported maintenance operation.');
        }
        $branch = $action === 'update' ? $this->branch($body['branch'] ?? $this->settings->get('vcs', 'repoBranch', 'master')) : null;
        [$title, $confirmation, $steps] = $definitions[$action];
        $execution = $this->operations->plan($action, ['branch' => $branch]);
        $id = bin2hex(random_bytes(16));
        $plan = [
            'id' => $id, 'action' => $action, 'title' => $title, 'branch' => $branch,
            'steps' => $steps, 'confirmation' => $confirmation, 'createdAt' => date(DATE_ATOM),
            'expiresAt' => date(DATE_ATOM, time() + 600), 'state' => 'planned',
            'impact' => $execution['impact'], 'resources' => $execution['resources'] ?? [],
            'unavailableReason' => $execution['unavailableReason'] ?? null,
            'canSimulate' => $this->simulationsAvailable(), 'canExecute' => (bool) ($execution['canExecute'] ?? false),
        ];
        $saved = $_SESSION['v3AdminPlans'] ?? [];
        $saved = is_array($saved) ? array_filter($saved, static fn(array $item): bool => $item['expires'] > time()) : [];
        if (count($saved) >= 10) {
            $saved = array_slice($saved, -9, null, true);
        }
        $saved[$id] = ['actorId' => $this->actor['id'], 'expires' => time() + 600, 'plan' => $plan, 'execution' => $execution];
        $_SESSION['v3AdminPlans'] = $saved;
        return $plan;
    }

    public function runPlan(string $id, string $mode, array $body): array
    {
        $stored = $_SESSION['v3AdminPlans'][$id] ?? null;
        if (!is_array($stored) || ($stored['actorId'] ?? null) !== $this->actor['id'] || ($stored['expires'] ?? 0) <= time()) {
            throw new HttpError(404, 'plan_expired', 'This operation plan expired. Review a new plan before continuing.');
        }
        Input::fields($body, ['confirmation']);
        if (!is_string($body['confirmation'] ?? null) || !hash_equals($stored['plan']['confirmation'], $body['confirmation'])) {
            Input::invalid('confirmation', 'Enter the confirmation phrase shown in the operation plan.');
        }
        if ($stored['plan']['state'] !== 'planned') {
            throw new HttpError(409, 'plan_finished', 'This operation has already been submitted. Review a new plan before continuing.');
        }
        $plan = $stored['plan'];
        if ($mode === 'execute') {
            if (!$plan['canExecute']) {
                throw new HttpError(503, 'executor_unavailable', $plan['unavailableReason'] ?? 'This operation is not configured. No changes were applied.');
            }
            $_SESSION['v3AdminPlans'][$id]['plan']['state'] = 'running';
            try {
                $result = $this->operations->execute($plan['action'], $stored['execution']);
                $plan['state'] = 'completed';
                $plan['completedAt'] = date(DATE_ATOM);
                $plan['output'] = $result['output'];
                $plan['signedOut'] = (bool) ($result['signedOut'] ?? false);
                $_SESSION['v3AdminPlans'][$id]['plan'] = $plan;
                if ($plan['signedOut']) {
                    $this->sessions->logout();
                } else {
                    $this->context->event('user', $this->actor['id'], 'Maintenance operation completed', $plan['title'] . ' completed.');
                }
                return $plan;
            } catch (\Throwable $error) {
                $_SESSION['v3AdminPlans'][$id]['plan']['state'] = 'failed';
                throw $error;
            }
        }
        Policy::require($this->simulationsAvailable());
        // Simulation validates the complete review/confirmation lifecycle but
        // deliberately does not invoke git, mount, demo reset or a shell runner.
        $plan['state'] = 'simulated';
        $plan['completedAt'] = date(DATE_ATOM);
        $plan['output'] = 'Simulation completed. No application, storage or system data was changed.';
        $_SESSION['v3AdminPlans'][$id]['plan'] = $plan;
        return $plan;
    }

    public function selectBranch(array $body): array
    {
        Input::fields($body, ['branch']);
        $branch = $this->branch($body['branch'] ?? null);
        $this->db->transaction(function () use ($branch): void {
            $this->settings->put('vcs', 'repoBranch', $branch);
            $this->context->event('user', $this->actor['id'], 'Update branch selected', 'The application update branch was changed.');
        });
        return $this->maintenance();
    }
}
