import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { once } from 'node:events';
import { mkdtemp, readFile, readdir, rm, stat } from 'node:fs/promises';
import { createServer } from 'node:http';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { Client } from '@modelcontextprotocol/client';
import { StdioClientTransport } from '@modelcontextprotocol/client/stdio';

const cli = fileURLToPath(new URL('../src/index.js', import.meta.url));
const username = 'synthetic-member';
const password = 'synthetic-password';
const testConfig = { timeout: 10_000 };

function json(response, body, status = 200) {
  response.writeHead(status, { 'Content-Type': 'application/json; charset=utf-8' });
  response.end(JSON.stringify(body));
}

function success(response, data, status) {
  json(response, { data }, status);
}

function error(response, status, code, message, fields) {
  json(response, { error: { code, message, fields } }, status);
}

async function body(request) {
  const chunks = [];
  for await (const chunk of request) chunks.push(chunk);
  return JSON.parse(Buffer.concat(chunks).toString('utf8'));
}

async function harness(t, route, env = {}, modern = true) {
  const exportDir = await mkdtemp(join(tmpdir(), 'chronos-mcp-test-'));
  const requests = [];
  const errors = [];
  const sessions = new Map();
  let logins = 0;
  let anonymous = 0;
  const http = createServer(async (req, res) => {
    const url = new URL(req.url, origin);
    const path = url.pathname;
    const key = `${req.method} ${path}`;
    const query = Object.fromEntries(url.searchParams);
    requests.push(key);
    try {
      if (key === 'GET /api/ui/session') {
        assert.equal(req.headers.cookie, undefined);
        const session = ++anonymous;
        res.setHeader('Set-Cookie', [
          'unrelated=ignore; Path=/',
          `ChronosFixtureSession=anon-${session}; Path=/; HttpOnly; SameSite=Lax`,
        ]);
        success(res, { user: null, csrfToken: session.toString(16).padStart(64, '0') });
      } else if (key === 'POST /api/ui/session') {
        assert.equal(req.headers.origin, origin);
        assert.equal(req.headers['content-type'], 'application/json');
        const old = /^ChronosFixtureSession=anon-(\d+)$/.exec(req.headers.cookie)?.[1];
        assert.ok(old);
        assert.equal(req.headers['x-csrf-token'], Number(old).toString(16).padStart(64, '0'));
        assert.deepEqual(await body(req), { username, password, remember: false });
        const session = ++logins;
        const cookie = `ChronosFixtureSession=auth-${session}`;
        const csrfToken = (session + 100).toString(16).padStart(64, '0');
        sessions.set(cookie, csrfToken);
        res.setHeader('Set-Cookie', [
          `ChronosFixtureSession=old-${session}; Path=/`,
          'rememberMe=deleted; Expires=Thu, 01 Jan 1970 00:00:00 GMT; Path=/',
          `${cookie}; Path=/; HttpOnly; SameSite=Lax`,
        ]);
        success(res, { user: { id: 9 }, csrfToken, impersonation: { active: false } });
      } else {
        const csrfToken = sessions.get(req.headers.cookie);
        assert.ok(csrfToken, 'requires authenticated session cookie');
        if (req.method !== 'GET') {
          assert.equal(req.headers.origin, origin);
          assert.equal(req.headers['x-csrf-token'], csrfToken);
          assert.equal(req.headers['content-type'], 'application/json');
        }
        await route({ req, res, key, query, session: req.headers.cookie, origin });
      }
    } catch (cause) {
      errors.push(cause);
      if (res.headersSent) res.destroy();
      else error(res, 500, 'fixture_error', 'Mock request did not match its contract.');
    }
  });

  http.listen(0, '127.0.0.1');
  await once(http, 'listening');
  const origin = `http://127.0.0.1:${http.address().port}`;
  const client = new Client({ name: 'chronos-fixture', version: '1.0.0' },
    modern ? { versionNegotiation: { mode: 'auto' } } : {});
  t.after(async () => {
    await client.close();
    http.closeAllConnections();
    await new Promise(resolve => http.close(resolve));
    await rm(exportDir, { recursive: true, force: true });
    assert.deepEqual(errors, []);
  });
  await client.connect(new StdioClientTransport({
    command: process.execPath,
    args: [cli],
    env: {
      CHRONOS_URL: origin,
      CHRONOS_USERNAME: username,
      CHRONOS_PASSWORD: password,
      CHRONOS_SESSION_COOKIE: 'ChronosFixtureSession',
      CHRONOS_EXPORT_DIR: exportDir,
      ...env,
    },
  }));

  const call = (name, args = {}, options) =>
    client.callTool({ name: `chronos_${name}`, arguments: args }, options);
  async function get(name, args) {
    const result = await call(name, args);
    assert.ok(!result.isError, JSON.stringify(result.content));
    assert.deepEqual(JSON.parse(result.content[0].text), result.structuredContent);
    return result.structuredContent;
  }

  return { client, call, get, requests, exportDir };
}

const epoch = 'e'.repeat(64);
const csv = Buffer.from('\uFEFFjobId;status;result.throughput\r\n42;3;12.5\r\n');
const log = 'setup\nfinished – ✓\n';
const job = { id: 42, evaluationId: 31, status: 3, statusName: 'finished', progress: 100 };
const results = { id: 42, status: 3, parameters: { workers: 2, version: '2.0' }, result: { throughput: 12.5 } };

test('stdio discovery, configuration, scheduling, polling, log and evidence retrieval', testConfig, async t => {
  let experiment;
  let scheduled = false;
  let polls = 0;
  const evaluation = active => ({
    id: 31, experimentId: 21, progress: active ? 0 : 100, counts: {
      total: 1, active: active ? 1 : 0, finished: active ? 0 : 1, aborted: 0, failed: 0,
    },
  });
  const api = await harness(t, async ({ req, res, key, query }) => {
    switch (key) {
      case 'GET /api/ui/systems': {
        assert.deepEqual(query, { sort: 'id', page: '1', pageSize: '20', search: 'fixture & test' });
        success(res, { items: [{ id: 1, name: 'Fixture & test' }], page: 1, pageSize: 20, total: 1 });
        break;
      }
      case 'GET /api/ui/projects': {
        assert.equal(query.sort, 'id');
        assert.equal(query.pageSize, '1');
        const page = Math.min(Number(query.page), 2);
        success(res, { items: [{ id: 5 + page, systemId: 1 }], page, pageSize: 1, total: 2 });
        break;
      }
      case 'GET /api/ui/projects/6/experiments':
        success(res, { items: experiment ? [experiment] : [], total: experiment ? 1 : 0, page: 1, pageSize: 20 });
        break;
      case 'GET /api/ui/projects/6/experiment-options':
        assert.deepEqual(query, {});
        success(res, {
          project: { id: 6, systemId: 1 }, maxJobs: 50, values: [],
          groups: [{ elements: [{ parameter: 'workers', type: 'integer', default: '1' },
            { parameter: 'version', type: 'software-versions-list' }] }],
          defaults: { runs: 1, distribution: 'alter', warmUp: false, environment: 'system-local' },
        });
        break;
      case 'POST /api/ui/projects/6/experiments':
        assert.equal(experiment, undefined);
        assert.deepEqual(await body(req), {
          name: 'Fixture sweep', runs: 1, distribution: 'alter', warmUp: false, environment: 'system-local',
          parameters: { workers: 2, 'version-numberOfVersions': 1, 'version-version-0': '1.0' },
        });
        experiment = { id: 21, projectId: 6, systemId: 1, name: 'Fixture sweep' };
        success(res, experiment, 201);
        break;
      case 'GET /api/ui/experiments/21/run-options':
        assert.ok(experiment);
        success(res, { environments: [{ key: 'system-local', type: 'system' }],
          versions: [{ parameter: 'version', values: ['1.0'] }], defaultEnvironment: 'system-local' });
        break;
      case 'POST /api/ui/experiments/21/evaluations':
        assert.ok(!scheduled);
        assert.deepEqual(await body(req), { name: 'Pilot', environment: 'system-local', versions: { version: ['2.0'] } });
        scheduled = true;
        success(res, evaluation(true), 201);
        break;
      case 'GET /api/ui/evaluations/31':
        assert.ok(scheduled);
        success(res, evaluation(polls++ === 0));
        break;
      case 'GET /api/ui/evaluations/31/jobs':
        assert.deepEqual(query, { sort: 'id', page: '1', pageSize: '20', view: 'summary', status: 'finished' });
        success(res, { items: [job], page: 1, pageSize: 20, total: 1 });
        break;
      case 'GET /api/ui/jobs/42/log': {
        const offset = Number(query.offset);
        if (offset) assert.equal(query.epoch, epoch);
        const bytes = Buffer.from(log);
        const nextOffset = offset === 0 ? 6 : bytes.length;
        success(res, { text: bytes.subarray(offset, nextOffset).toString(), offset, nextOffset, epoch,
          reset: false, truncated: nextOffset < bytes.length, complete: nextOffset === bytes.length, size: bytes.length });
        break;
      }
      case 'GET /api/ui/evaluations/31/results':
        assert.deepEqual(query, { page: '1', pageSize: '1', view: 'raw', sort: 'id' });
        success(res, { jobs: [results], totalJobs: 1, jobPage: { page: 1, pageSize: 1, total: 1, sort: 'id' } });
        break;
      case 'GET /api/ui/evaluations/31/results.csv':
        res.writeHead(200, { 'Content-Type': 'text/csv; charset=utf-8', 'Content-Disposition': 'attachment; filename="../../ignored"' });
        res.end(csv);
        break;
      case 'GET /api/ui/jobs/42/log/download':
        res.writeHead(200, { 'Content-Type': 'text/plain; charset=utf-8' });
        res.end(log);
        break;
      default: assert.fail(`Unexpected route: ${key}`);
    }
  });
  const { tools } = await api.client.listTools();
  assert.equal(tools.find(item => item.name === 'chronos_create_experiment').annotations.idempotentHint, false);
  assert.equal(tools.find(item => item.name === 'chronos_start_evaluation').annotations.readOnlyHint, false);
  assert.equal(tools.find(item => item.name === 'chronos_get_job_log').annotations.readOnlyHint, true);
  assert.equal(tools.find(item => item.name === 'chronos_abort_evaluation').annotations.destructiveHint, true);
  assert.equal(api.requests.length, 0);

  const [systems, first] = await Promise.all([
    api.get('list_systems', { search: 'fixture & test' }),
    api.get('list_projects', { pageSize: 1 }),
  ]);
  assert.equal(systems.items[0].id, 1);
  assert.equal(api.requests.filter(key => key === 'POST /api/ui/session').length, 1);
  assert.equal(first.nextPage, 2);
  assert.equal((await api.get('list_projects', { pageSize: 1, page: first.nextPage })).items[0].id, 7);
  const clamped = await api.get('list_projects', { pageSize: 1, page: 99 });
  assert.equal(clamped.page, 2);
  assert.equal(clamped.nextPage, null);

  assert.deepEqual((await api.get('list_experiments', { projectId: 6 })).items, []);
  const options = await api.get('get_experiment_options', { projectId: 6 });
  assert.equal(options.maxJobs, 50);
  assert.deepEqual(api.requests.filter(key => key.startsWith('POST')), ['POST /api/ui/session']);
  const created = await api.get('create_experiment', {
    projectId: 6, name: 'Fixture sweep', ...options.defaults,
    parameters: { workers: 2, 'version-numberOfVersions': 1, 'version-version-0': '1.0' },
  });
  const run = await api.get('get_run_options', { experimentId: created.id });
  const started = await api.get('start_evaluation', {
    experimentId: created.id, name: 'Pilot', environment: run.environments[0].key, versions: { version: ['2.0'] },
  });
  assert.equal(started.counts.active, 1);
  assert.equal((await api.get('get_evaluation', { evaluationId: started.id })).counts.active, 1);
  assert.equal((await api.get('get_evaluation', { evaluationId: started.id })).counts.finished, 1);
  const jobs = await api.get('list_jobs', { evaluationId: started.id, status: 'finished' });
  const jobId = jobs.items[0].id;
  const head = await api.get('get_job_log', { jobId, limit: 6 });
  const tail = await api.get('get_job_log', { jobId, offset: head.nextOffset, epoch: head.epoch });
  assert.equal(head.text + tail.text, log);
  assert.equal(tail.complete, true);
  const resultPage = await api.get('get_results', { scope: 'evaluation', id: started.id, pageSize: 1 });
  assert.deepEqual(resultPage.jobs, [results]);
  assert.equal(resultPage.jobPage.nextPage, null);

  const evidence = await api.get('export_evidence', { scope: 'evaluation', id: started.id, format: 'csv' });
  assert.ok(evidence.path.startsWith(`${api.exportDir}/chronos-`));
  assert.deepEqual(await readFile(evidence.path), csv);
  assert.equal(evidence.sizeBytes, csv.length);
  assert.equal(evidence.sha256, createHash('sha256').update(csv).digest('hex'));
  assert.equal((await stat(evidence.path)).mode & 0o777, 0o600);
  const fullLog = await api.get('export_evidence', { scope: 'job', id: jobId, format: 'log' });
  assert.equal(await readFile(fullLog.path, 'utf8'), log);
});

test('maps API errors and retries transient reads without replaying writes', testConfig, async t => {
  let reads = 0;
  let starts = 0;
  const api = await harness(t, async ({ req, res, key }) => {
    switch (key) {
      case 'GET /api/ui/projects/6':
        if (++reads === 1) error(res, 503, 'unavailable', 'fixture failure');
        else success(res, { id: 6 });
        break;
      case 'GET /api/ui/projects/7':
        error(res, 403, 'forbidden', 'You do not have access.');
        break;
      case 'POST /api/ui/projects/6/experiments':
        assert.deepEqual(await body(req), { name: 'Invalid settings' });
        error(res, 422, 'validation_failed', 'Please check the submitted values.', { parameters: 'Choose a version.' });
        break;
      case 'POST /api/ui/experiments/21/evaluations':
        await body(req);
        if (++starts === 1) req.socket.destroy();
        else error(res, 503, 'unavailable', 'fixture failure');
        break;
      default: assert.fail(`Unexpected route: ${key}`);
    }
  }, {}, false);
  const invalid = await api.call('create_experiment', { projectId: 0, name: 'Try', tyop: true });
  assert.equal(invalid.isError, true);
  assert.equal(api.requests.length, 0);
  assert.equal((await api.get('get_project', { projectId: 6 })).id, 6);
  assert.equal(reads, 2);
  const forbidden = await api.call('get_project', { projectId: 7 });
  assert.equal(forbidden.isError, true);
  assert.equal(forbidden.structuredContent.error.status, 403);
  const rejected = await api.call('create_experiment', { projectId: 6, name: 'Invalid settings' });
  assert.equal(rejected.isError, true);
  assert.deepEqual(rejected.structuredContent.error.fields, { parameters: 'Choose a version.' });

  const args = { experimentId: 21, environment: 'system-local' };
  const disconnected = await api.call('start_evaluation', args);
  assert.equal(disconnected.isError, true);
  assert.equal(disconnected.structuredContent.error.code, 'write_outcome_unknown');
  assert.equal(starts, 1);
  const unavailable = await api.call('start_evaluation', args);
  assert.equal(unavailable.isError, true);
  assert.equal(unavailable.structuredContent.error.code, 'write_outcome_unknown');
  assert.equal(unavailable.structuredContent.error.status, 503);
  assert.equal(starts, 2);
});

test('renews stale sessions for reads and invalidates a rejected write without replay', testConfig, async t => {
  const api = await harness(t, async ({ req, res, key, session }) => {
    if (key === 'GET /api/ui/projects/6') {
      if (session.endsWith('auth-1')) error(res, 401, 'authentication_required', 'Please sign in.');
      else success(res, { id: 6 });
    } else if (key === 'POST /api/ui/evaluations/31/actions') {
      assert.deepEqual(await body(req), { action: 'abort' });
      if (session.endsWith('auth-2')) error(res, 403, 'csrf_invalid', 'Reload the session.');
      else success(res, { changedJobs: 1, evaluation: { id: 31 } });
    } else {
      assert.fail(`Unexpected route: ${key}`);
    }
  });
  await api.get('get_project', { projectId: 6 });
  const stale = await api.call('abort_evaluation', { evaluationId: 31 });
  assert.equal(stale.isError, true);
  assert.equal(stale.structuredContent.error.code, 'csrf_invalid');
  assert.equal(api.requests.filter(key => key === 'POST /api/ui/evaluations/31/actions').length, 1);
  assert.equal((await api.get('abort_evaluation', { evaluationId: 31 })).changedJobs, 1);
  assert.equal(api.requests.filter(key => key === 'POST /api/ui/session').length, 3);
});

test('cancels an in-flight Chronos write over stdio without replaying it', testConfig, async t => {
  const arrived = Promise.withResolvers();
  const closed = Promise.withResolvers();
  const api = await harness(t, async ({ req, res, key }) => {
    assert.equal(key, 'POST /api/ui/experiments/21/evaluations');
    await body(req);
    res.once('close', closed.resolve);
    arrived.resolve();
  });
  const controller = new AbortController();
  const cancelled = assert.rejects(
    api.call('start_evaluation', { experimentId: 21, environment: 'system-local' }, { signal: controller.signal }),
    /cancel fixture/,
  );
  await arrived.promise;
  controller.abort('cancel fixture');
  await cancelled;
  await closed.promise;
  assert.equal(api.requests.filter(key => key === 'POST /api/ui/experiments/21/evaluations').length, 1);
});

test('caps chunked evidence exports and removes incomplete files', testConfig, async t => {
  const api = await harness(t, async ({ res, key }) => {
    assert.equal(key, 'GET /api/ui/jobs/42/download');
    res.writeHead(200, { 'Content-Type': 'application/zip' });
    res.write(Buffer.alloc(20));
    res.end(Buffer.alloc(20));
  }, { CHRONOS_MAX_EXPORT_BYTES: '32' });
  const result = await api.call('export_evidence', { scope: 'job', id: 42, format: 'zip' });
  assert.equal(result.isError, true);
  assert.equal(result.structuredContent.error.code, 'export_too_large');
  assert.deepEqual(await readdir(api.exportDir), []);
});

test('publishes benchmark and binding revisions, schedules repetitions, and exports attempt evidence', testConfig, async t => {
  const contract = {
    parameters: { workers: { type: 'integer', default: 2 }, enabled: { type: 'boolean', default: false } },
    measurement: 'Measure the duration of the operation.',
    lifecycle: { warmUp: 'disabled' }, results: { durationMs: { type: 'number', unit: 'ms' } },
  };
  const benchmarkBody = { key: 'synthetic', version: '1.0', name: 'Synthetic duration', contract };
  const benchmark = { id: 11, ...benchmarkBody };
  const definition = {
    systemVersion: '2.0', adapter: 'chronos-command@1',
    parameterMap: { workers: 'threads', enabled: 'flag' }, constants: { fixed: true },
    artifacts: { schemaVersion: 1, artifacts: [{ role: 'driver', uri: 'fixture:driver', sha256: 'd'.repeat(64), sizeBytes: 10 }] },
  };
  const bindingBody = { systemId: 2, version: '1.0', definition };
  const binding = { id: 18, ...bindingBody, systemName: 'Fixture adapter', benchmark,
    definition: { ...definition, systemIdentifier: 'fixture-adapter' } };
  const experimentBody = { name: 'Bound experiment', bindingId: 18, planning: 'explicit' };
  const jobs = [{ parameters: {} }, { parameters: { enabled: false } }, { parameters: {} }];
  const startBody = { environment: 'system-local', jobs, name: 'Repetitions' };
  const comparison = { id: 3, name: 'Pilot vs repeat', evaluations: [
    { id: 31, name: 'Repetitions', systemName: 'Fixture adapter', experimentName: 'Bound experiment', planned: 3, finished: 1 },
    { id: 5, name: 'Pilot', systemName: 'Fixture', experimentName: 'Baseline', planned: 3, finished: 3 },
  ] };
  const archive = Buffer.from('504b0506000000000000000000000000000000000000', 'hex');
  const archiveMetadata = { sizeBytes: archive.length, sha256: createHash('sha256').update(archive).digest('hex') };
  const evidence = {
    repetitionId: 42,
    specification: { schemaVersion: 1, protocol: 'attempt-v1', system: { id: 2, identifier: 'fixture-adapter', version: '2.0' },
      benchmark, binding: { id: 18, version: '1.0' }, adapter: definition.adapter, artifacts: definition.artifacts,
      environment: 'system-local', phases: 2, parameters: { workers: 2, enabled: false },
      effectiveParameters: { threads: 2, flag: false, fixed: true }, setup: [], cdl: '<cdl/>', sha256: '2'.repeat(64) },
    attempts: [
      { id: 140, status: 'failed', selected: false, reason: 'Synthetic failure', archive: null, archiveUrl: null, logUrl: null },
      { id: 141, status: 'finished', selected: true, reason: null, result: { durationMs: 12.5 }, archive: archiveMetadata,
        archiveUrl: '/api/ui/jobs/42/attempts/141/archive', logUrl: '/api/ui/jobs/42/attempts/141/log' },
    ],
    resultSelection: 'successful-attempt', selectedAttemptId: 141,
  };
  let publishedBenchmark;
  let publishedBinding;
  let comparisonCreated;
  const api = await harness(t, async ({ req, res, key, query }) => {
    switch (key) {
      case 'GET /api/ui/benchmarks':
        assert.equal(query.page, '1');
        assert.ok(['1', '5'].includes(query.pageSize));
        success(res, { benchmarks: publishedBenchmark ? [benchmark] : [], bindings: publishedBinding ? [binding] : [],
          systems: [{ id: 2, name: 'Fixture adapter' }], page: 1, pageSize: Number(query.pageSize), nextPage: null });
        break;
      case 'POST /api/ui/benchmarks':
        assert.deepEqual(await body(req), benchmarkBody);
        if (publishedBenchmark) error(res, 409, 'version_exists', 'This version already exists.');
        else {
          publishedBenchmark = true;
          success(res, benchmark, 201);
        }
        break;
      case 'POST /api/ui/benchmarks/11/bindings':
        assert.deepEqual(await body(req), bindingBody);
        assert.ok(publishedBenchmark);
        publishedBinding = true;
        success(res, binding, 201);
        break;
      case 'GET /api/ui/projects/6/experiment-options':
        assert.deepEqual(query, { bindingId: '18', planning: 'explicit' });
        success(res, { project: { id: 6, systemId: 1 }, systemId: 2, binding, planning: 'explicit', groups: [], values: [], maxJobs: 50 });
        break;
      case 'POST /api/ui/projects/6/experiments':
        assert.deepEqual(await body(req), experimentBody);
        success(res, { id: 21, projectId: 6, systemId: 2, ...experimentBody }, 201);
        break;
      case 'POST /api/ui/experiments/21/evaluations':
        assert.deepEqual(await body(req), startBody);
        success(res, { id: 31, experimentId: 21, counts: { total: 3 } }, 201);
        break;
      case 'POST /api/ui/projects/6/comparisons':
        assert.deepEqual(await body(req), { name: comparison.name, evaluationIds: [31, 5] });
        comparisonCreated = true;
        success(res, comparison, 201);
        break;
      case 'GET /api/ui/projects/6/comparisons':
        assert.deepEqual(query, {});
        success(res, { items: comparisonCreated ? [comparison] : [] });
        break;
      case 'GET /api/ui/jobs/42/execution':
        success(res, evidence);
        break;
      case 'GET /api/ui/jobs/43/execution':
        success(res, { repetitionId: 43, specification: null, attempts: [], resultSelection: 'legacy-job-result', selectedAttemptId: null });
        break;
      case 'GET /api/ui/jobs/42/attempts/141/log':
        res.writeHead(200, { 'Content-Type': 'text/plain; charset=utf-8' });
        res.end(log);
        break;
      case 'GET /api/ui/jobs/42/attempts/141/archive':
        res.writeHead(200, { 'Content-Type': 'application/zip', 'Content-Disposition': 'attachment; filename="../../ignored"' });
        res.end(archive);
        break;
      case 'GET /api/ui/jobs/42/attempts/140/archive':
        error(res, 404, 'evidence_missing', 'This attempt has no stored archive.');
        break;
      default: assert.fail(`Unexpected route: ${key}`);
    }
  });

  const { tools } = await api.client.listTools();
  const explicit = tools.find(item => item.name === 'chronos_start_explicit_evaluation');
  assert.ok(explicit.inputSchema.required.includes('jobs'));
  assert.equal(explicit.annotations.idempotentHint, false);
  assert.equal((await api.call('start_explicit_evaluation', { experimentId: 21, environment: 'system-local', jobs: [] })).isError, true);
  assert.equal((await api.call('publish_benchmark', { ...benchmarkBody, contract: {
    ...contract, parameters: { workers: { type: 'integer', default: '2' } },
  } })).isError, true);
  assert.equal(api.requests.length, 0);
  assert.deepEqual(await api.get('get_capabilities'), { benchmarkWorkflows: true });
  assert.deepEqual(await api.get('publish_benchmark', benchmarkBody), benchmark);
  assert.equal((await api.call('publish_benchmark', benchmarkBody)).structuredContent.error.code, 'version_exists');
  assert.deepEqual(await api.get('publish_binding', { benchmarkRevisionId: 11, ...bindingBody }), binding);
  assert.deepEqual((await api.get('list_benchmarks')).bindings, [binding]);
  assert.equal((await api.get('get_experiment_options', { projectId: 6, bindingId: 18, planning: 'explicit' })).systemId, 2);
  assert.equal((await api.get('create_experiment', { projectId: 6, ...experimentBody })).bindingId, 18);
  assert.equal((await api.get('start_explicit_evaluation', { experimentId: 21, ...startBody })).counts.total, 3);
  assert.deepEqual(await api.get('list_comparisons', { projectId: 6 }), { items: [] });
  assert.equal((await api.call('create_comparison', { projectId: 6, name: comparison.name, evaluationIds: [31, 31] })).isError, true);
  assert.deepEqual(await api.get('create_comparison', { projectId: 6, name: comparison.name, evaluationIds: [31, 5] }), comparison);
  assert.deepEqual((await api.get('list_comparisons', { projectId: 6 })).items, [comparison]);
  assert.deepEqual(await api.get('get_job_execution', { jobId: 42 }), evidence);
  assert.equal((await api.get('get_job_execution', { jobId: 43 })).specification, null);

  const exportedLog = await api.get('export_attempt_evidence', { jobId: 42, attemptId: 141, format: 'log' });
  assert.equal(exportedLog.jobId, 42);
  assert.equal(exportedLog.attemptId, 141);
  assert.match(exportedLog.path, /\/attempt-141\.log$/);
  assert.equal(await readFile(exportedLog.path, 'utf8'), log);
  const exportedArchive = await api.get('export_attempt_evidence', { jobId: 42, attemptId: 141, format: 'zip' });
  assert.equal(exportedArchive.sha256, archiveMetadata.sha256);
  assert.equal(exportedArchive.sizeBytes, archiveMetadata.sizeBytes);
  assert.match(exportedArchive.path, /\/attempt-141\.zip$/);
  assert.deepEqual(await readFile(exportedArchive.path), archive);
  const missingArchive = await api.call('export_attempt_evidence', { jobId: 42, attemptId: 140, format: 'zip' });
  assert.equal(missingArchive.structuredContent.error.code, 'evidence_missing');
  assert.equal((await readdir(api.exportDir)).length, 2);
});

test('reports missing benchmark support and prevents writes through older workflow routes', testConfig, async t => {
  const api = await harness(t, async ({ res, key }) => {
    assert.equal(key, 'GET /api/ui/benchmarks');
    error(res, 404, 'not_found', 'This API resource does not exist.');
  });
  assert.deepEqual(await api.get('get_capabilities'), { benchmarkWorkflows: false });
  const unsupported = { status: 404, code: 'unsupported_capability', capability: 'benchmarkWorkflows' };
  for (const [tool, args] of [
    ['list_benchmarks', {}],
    ['create_experiment', { projectId: 6, name: 'Bound', bindingId: 18, planning: 'explicit' }],
    ['start_explicit_evaluation', { experimentId: 21, environment: 'system-local', jobs: [{ parameters: {} }] }],
  ]) {
    const response = await api.call(tool, args);
    assert.equal(response.isError, true);
    const { message, ...detail } = response.structuredContent.error;
    assert.match(message, /versioned benchmark workflows/);
    assert.deepEqual(detail, unsupported);
  }
  assert.deepEqual(api.requests.filter(key => key.startsWith('POST')), ['POST /api/ui/session']);
});

test('does not replay an ambiguous benchmark publication', testConfig, async t => {
  let writes = 0;
  const benchmark = { key: 'ambiguous', version: '1.0', name: 'Ambiguous write', contract: {
    lifecycle: { warmUp: 'optional' }, measurement: 'Measure duration.', results: { durationMs: { type: 'number' } },
  } };
  const api = await harness(t, async ({ req, res, key }) => {
    if (key === 'GET /api/ui/benchmarks') {
      success(res, { benchmarks: [], bindings: [], systems: [], page: 1, pageSize: 1, nextPage: null });
    } else {
      assert.equal(key, 'POST /api/ui/benchmarks');
      assert.deepEqual(await body(req), benchmark);
      writes++;
      req.socket.destroy();
    }
  });
  const response = await api.call('publish_benchmark', benchmark);
  assert.equal(response.structuredContent.error.code, 'write_outcome_unknown');
  assert.equal(writes, 1);
});

test('round-trips frozen numeric literals and publishes the same wide integers over HTTP', testConfig, async t => {
  const token = '9007199254740993';
  const minimum = '-9223372036854775808';
  const historical = '{"parameters":{"count":9007199254740993,"label":"9007199254740993"}}';
  const digest = createHash('sha256').update(historical).digest('hex');
  const api = await harness(t, async ({ req, res, key }) => {
    if (key === 'GET /api/ui/benchmarks') {
      success(res, { benchmarks: [], bindings: [], systems: [], page: 1, pageSize: 1, nextPage: null });
    } else if (key === 'GET /api/ui/jobs/42/execution') {
      res.writeHead(200, { 'Content-Type': 'application/json' });
      res.end('{"data":{"repetitionId":42,"attempts":[{"result":{"clock":' + minimum +
        '}}],"specification":' + historical.slice(0, -1) + ',"sha256":"' + digest + '"}}}');
    } else {
      assert.equal(key, 'POST /api/ui/experiments/7/evaluations');
      const chunks = [];
      for await (const chunk of req) chunks.push(chunk);
      const text = Buffer.concat(chunks).toString('utf8');
      assert.ok(text.includes('"jobs":[' + historical + ',{"parameters":{"count":' + minimum + '}}]'), text);
      success(res, { id: 8 }, 201);
    }
  });
  const evidence = await api.get('get_job_execution', { jobId: 42 });
  assert.deepEqual(evidence.specification.parameters, { count: { $integer: token }, label: token });
  assert.equal(evidence.specification.sha256, digest);
  assert.deepEqual(evidence.attempts[0].result.clock, { $integer: minimum });
  const args = { experimentId: 7, environment: 'system-default' };
  assert.ok((await api.call('start_explicit_evaluation', { ...args, jobs: [{ parameters: { count: Number(token) } }] })).isError);
  assert.ok((await api.call('start_explicit_evaluation', { ...args, jobs: [{ parameters: { count: { $integer: '9223372036854775808' } } }] })).isError);
  assert.deepEqual(await api.get('start_explicit_evaluation', { ...args, jobs: [
    { parameters: evidence.specification.parameters }, { parameters: { count: evidence.attempts[0].result.clock } },
  ] }), { id: 8 });
});

test('can traverse a catalog larger than the JSON limit in small pages', testConfig, async t => {
  const items = Array.from({ length: 48 }, (_, index) => ({ id: index + 1, description: 'x'.repeat(48 * 1024) }));
  assert.ok(Buffer.byteLength(JSON.stringify(items)) > 2 * 1024 ** 2);
  const api = await harness(t, async ({ key, res, query }) => {
    assert.equal(key, 'GET /api/ui/benchmarks');
    const page = Number(query.page), pageSize = Number(query.pageSize);
    assert.ok(page >= 1 && pageSize <= 5);
    const offset = (page - 1) * pageSize;
    success(res, { page, pageSize, nextPage: offset + pageSize < items.length ? page + 1 : null,
      benchmarks: items.slice(offset, offset + pageSize), bindings: [], systems: [] });
  });
  assert.deepEqual(await api.get('get_capabilities'), { benchmarkWorkflows: true });
  let page = 1;
  const seen = [];
  while (page !== null) {
    const catalog = await api.get('list_benchmarks', { page, pageSize: 5 });
    seen.push(...catalog.benchmarks.map(item => item.id));
    page = catalog.nextPage;
  }
  assert.deepEqual(seen, items.map(item => item.id));
});
