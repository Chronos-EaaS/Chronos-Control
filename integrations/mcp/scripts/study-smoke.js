import assert from 'node:assert/strict';
import { execFile } from 'node:child_process';
import { randomUUID } from 'node:crypto';
import { mkdir, readFile, rename, symlink, writeFile } from 'node:fs/promises';
import { join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { parseArgs, promisify } from 'node:util';
import { Client } from '@modelcontextprotocol/client';
import { StdioClientTransport } from '@modelcontextprotocol/client/stdio';
import { ChronosApi } from '../src/api.js';
import { parseChronosJson } from '../src/integer.js';
import { configuration } from '../src/config.js';

// See the usage printed by --help. The fixture owns every Chronos record this test creates.
const { values } = parseArgs({ options: {
  'study-dir': { type: 'string' }, 'go-agent': { type: 'string' }, 'python-agent-src': { type: 'string' },
  output: { type: 'string' }, database: { type: 'boolean' }, 'docker-socket': { type: 'string' },
  'study-revision': { type: 'string' },
  help: { type: 'boolean' },
} });
if (values.help) {
  console.log(`CHRONOS_URL=http://127.0.0.1:<fixture-port> node integrations/mcp/scripts/study-smoke.js \\
  --study-dir /path/to/control/examples/benchmarks --go-agent /path/to/chronos-agent \\
  --python-agent-src /path/to/python-agent/src --output /path/to/new-private-directory \\
  [--study-revision REVISION] [--database] [--docker-socket /var/run/docker.sock]

Use a fresh V3 fixture with the integrated Control backend and the two attempt-v1 agents.
The runner creates named Systems and one project through the fixture's authenticated UI API.
The staged studies require Python 3.11+, gzip and zstd. Database runs also require the
locally provisioned images named in examples/benchmarks/database/README.md. The output keeps
the staged manifests, native CLI logs, exported attempt archives, and summary.json.`);
  process.exit(0);
}
for (const flag of ['study-dir', 'go-agent', 'python-agent-src', 'output']) {
  assert.ok(values[flag], `Required option --${flag}. See --help.`);
}
const run = promisify(execFile);
const cli = fileURLToPath(new URL('../src/index.js', import.meta.url));
const inspectZip = fileURLToPath(new URL('./inspect-study-evidence.py', import.meta.url));
const studyDir = resolve(values['study-dir']);
const go = resolve(values['go-agent']);
const pythonSrc = resolve(values['python-agent-src']);
const output = resolve(values.output);
const origin = new URL(process.env.CHRONOS_URL);
assert.equal(origin.protocol, 'http:', 'Use the disposable loopback HTTP fixture.');
assert.ok(['127.0.0.1', '[::1]'].includes(origin.hostname) && origin.port && origin.href === `${origin.origin}/`,
  'Use the origin and port of the disposable loopback fixture.');
const ready = await fetch(new URL('/healthz.txt', origin), { redirect: 'error', signal: AbortSignal.timeout(5000) });
assert.ok(ready.ok && (await ready.text()).trim() === 'chronos-test-ready', 'The Chronos test fixture is not ready.');

await mkdir(output, { mode: 0o700 });
const cache = join(output, 'artifact-cache');
const logs = join(output, 'cli');
await mkdir(logs);
// Moving this private symlink makes the first gzip run fail without changing its frozen specification.
const gzip = join(output, 'gzip');
await symlink((await run('which', ['gzip'])).stdout.trim(), gzip);

const client = new Client({ name: 'chronos-study-live-smoke', version: '1.0.0' }, { versionNegotiation: { mode: 'auto' } });
const fixtureEnv = { CHRONOS_URL: origin.origin, CHRONOS_USERNAME: 'owner', CHRONOS_PASSWORD: 'Chronos-test-2026!',
  CHRONOS_EXPORT_DIR: output };
const fixtureApi = new ChronosApi(configuration(fixtureEnv));
const adminApi = new ChronosApi(configuration({ ...fixtureEnv, CHRONOS_USERNAME: 'admin' }));
const environment = 'default';
const systemNames = { gzip: 'gzip', zstd: 'Zstandard', postgresql: 'PostgreSQL', mariadb: 'MariaDB' };
const agentEnv = { ...process.env, NO_PROXY: '127.0.0.1,::1', no_proxy: '127.0.0.1,::1' };
delete agentEnv.CHRONOS_AUTHORIZATION;
delete agentEnv.CHRONOS_ATTEMPT_TOKEN;

async function call(name, args = {}) {
  const response = await client.callTool({ name: `chronos_${name}`, arguments: args }, { timeout: 30_000 });
  assert.ok(!response.isError, JSON.stringify(response.structuredContent ?? response.content));
  return response.structuredContent;
}

async function provisionSystems(pilots, name) {
  const { user: owner } = await fixtureApi.get('/session', {}, AbortSignal.timeout(25_000));
  assert.equal(owner.username, 'owner');
  const systems = new Map();
  for (const pilot of pilots) {
    const created = await adminApi.post('/systems', {
      name: systemNames[pilot], description: 'Local benchmark study fixture', ownerId: owner.id,
    }, AbortSignal.timeout(25_000));
    const system = await fixtureApi.get(`/systems/${created.id}`, {}, AbortSignal.timeout(25_000));
    assert.equal(system.name, systemNames[pilot]);
    assert.equal(system.ownerId, owner.id);
    assert.equal(system.archived, false);
    assert.match(system.identifier, /^system-[a-f0-9]{24}$/);
    assert.equal(system.identifier, created.identifier);
    const available = await fixtureApi.get(`/systems/${system.id}/environments`, {}, AbortSignal.timeout(25_000));
    assert.ok(available.environments.includes(environment));
    systems.set(pilot, { id: system.id, identifier: system.identifier, name: system.name });
  }
  const records = [...systems.values()];
  for (const field of ['id', 'identifier', 'name']) {
    assert.equal(new Set(records.map(system => system[field])).size, pilots.length, `System ${field} must be distinct`);
  }
  const visible = [];
  let page = 1;
  while (page !== null) {
    const catalog = await call('list_benchmarks', { page });
    visible.push(...catalog.systems);
    page = catalog.nextPage;
  }
  for (const { id, name } of records) assert.ok(visible.some(system => system.id === id && system.name === name));

  const project = await fixtureApi.post('/projects', { name, systemId: systems.get('gzip').id }, AbortSignal.timeout(25_000));
  const actual = await call('get_project', { projectId: project.id });
  assert.equal(actual.name, name);
  assert.equal(actual.ownerId, owner.id);
  assert.equal(actual.systemId, systems.get('gzip').id);
  return { systems, project: { id: actual.id, name: actual.name, defaultSystemId: actual.systemId } };
}

async function invokeAgent(agent, pilot, identifier, label) {
  const worker = `study-${agent}-${pilot}`;
  const work = join(output, `${agent}-runs`);
  const args = agent === 'go' ? [go, ['-url', origin.origin, '-mode', 'attempt-v1', '-worker', worker,
    '-environment', environment, '-supports', identifier, '-artifact-cache', cache, '-work-dir', work, '-timeout', '3m', '-once'], agentEnv]
    : ['python3', ['-m', 'chronos_agent', '--url', origin.origin, '--protocol', 'attempt-v1', '--worker', worker,
      '--environment', environment, '--system', identifier, '--artifact-cache', cache, '--work-dir', work, '--timeout', '180', '--once'],
    { ...agentEnv, PYTHONPATH: pythonSrc, PYTHONDONTWRITEBYTECODE: '1' }];
  const [command, commandArgs, env] = args;
  let response;
  try {
    response = await run(command, commandArgs, { env, timeout: 240_000 });
  } catch (error) {
    if (!Number.isInteger(error.code)) throw error;
    response = error;
  }
  await Promise.all(['stdout', 'stderr'].map(stream => writeFile(join(logs, `${label}.${stream}.log`), response[stream])));
  return { worker, exitCode: response.code ?? 0, stdout: response.stdout, stderr: response.stderr };
}

async function checkWrongSystem(pilot, jobs, system, otherSystem) {
  assert.notEqual(system.identifier, otherSystem.identifier);
  const goProbe = await invokeAgent('go', pilot, otherSystem.identifier, `${pilot}-wrong-system-go`);
  assert.equal(goProbe.exitCode, 0, goProbe.stderr);
  assert.equal(goProbe.stdout.trim(), 'No job in queue.');
  const pythonProbe = await invokeAgent('python', pilot, otherSystem.identifier, `${pilot}-wrong-system-python`);
  assert.equal(pythonProbe.exitCode, 3, pythonProbe.stderr);
  assert.deepEqual(JSON.parse(pythonProbe.stdout), { outcome: 'empty' });
  for (const { id } of jobs) {
    const execution = await call('get_job_execution', { jobId: id });
    assert.deepEqual(execution.attempts, []);
  }
  return { queuedSystemId: system.id, advertisedSystemId: otherSystem.id,
    advertisedIdentifier: otherSystem.identifier, go: 'empty', python: 'empty' };
}

async function runAgent(agent, jobId, pilot, system, expectedFailure = false) {
  const label = `${pilot}-${jobId}-${agent}${expectedFailure ? '-failed' : ''}`;
  const { worker, exitCode, stdout, stderr } = await invokeAgent(agent, pilot, system.identifier, label);
  assert.equal(exitCode !== 0, expectedFailure, `${label}\n${stdout}\n${stderr}`);
  const execution = await call('get_job_execution', { jobId });
  const last = execution.attempts.at(-1);
  assert.equal(last?.worker.id, worker, `${label} did not claim repetition ${jobId}`);
  assert.equal(last.status, expectedFailure ? 'failed' : 'finished');
  assert.equal(last.selected, !expectedFailure);
  assert.equal(execution.selectedAttemptId, expectedFailure ? null : last.id);
  assert.deepEqual(execution.specification.system, system);
  if (agent === 'go') {
    assert.match(stdout, new RegExp(`^job ${jobId}, attempt ${last.id}:`, 'm'));
  } else {
    const report = JSON.parse(stdout);
    assert.equal(report.jobId, jobId);
    assert.equal(report.attemptId, last.id);
    assert.equal(report.outcome, expectedFailure ? 'failed' : 'finished');
  }
  return { execution, attempt: last };
}

async function inspect(pilot, jobId, execution, attempt) {
  const args = { jobId, attemptId: attempt.id };
  const archive = await call('export_attempt_evidence', { ...args, format: 'zip' });
  const log = await call('export_attempt_evidence', { ...args, format: 'log' });
  assert.equal(archive.sha256, attempt.archive.sha256);
  assert.equal(archive.sizeBytes, attempt.archive.sizeBytes);
  const { stdout } = await run('python3', ['-I', inspectZip, archive.path, pilot], { timeout: 30_000 });
  const evidence = parseChronosJson(stdout);
  assert.equal(evidence.repetitionId, jobId);
  assert.equal(evidence.attemptId, attempt.id);
  assert.equal(evidence.specificationSha256, execution.specification.sha256);
  assert.deepEqual(evidence.system, execution.specification.system);
  assert.deepEqual(evidence.binding, execution.specification.binding);
  assert.deepEqual(evidence.result, attempt.result);
  assert.ok(evidence.stderr.length > 0 && (await readFile(log.path, 'utf8')).includes(evidence.stderr));
  for (const artifact of execution.specification.artifacts.artifacts) {
    assert.deepEqual(attempt.observedArtifacts[artifact.role], { sha256: artifact.sha256, sizeBytes: artifact.sizeBytes });
  }
  return { attemptId: attempt.id, worker: attempt.worker.id, systemIdentifier: evidence.system.identifier, status: attempt.status,
    archive, log, entryCount: evidence.entryCount, studyRecord: evidence.studyRecord, result: evidence.result };
}

const version = `smoke-${randomUUID().slice(0, 8)}`;
const benchmarks = new Map();
async function runStudy({ pilot, agents, project, system, otherSystem }) {
  const path = join(output, `${pilot}-requests`);
  const batch = pilot === 'gzip' || pilot === 'zstd';
  const stageArgs = [join(studyDir, 'stage.py'), pilot, '--cache', cache, '--output', path, '--system-id', String(system.id),
    '--benchmark-version', version];
  if (pilot === 'gzip') stageArgs.push('--executable', gzip);
  if (!batch && values['docker-socket']) stageArgs.push('--docker-socket', resolve(values['docker-socket']));
  if (values['study-revision']) stageArgs.push('--source-revision', values['study-revision']);
  await run('python3', stageArgs, { timeout: 60_000 });
  const request = async name => parseChronosJson(await readFile(join(path, `${name}.json`), 'utf8'));
  const [benchmarkBody, bindingBody, experimentBody, evaluationBody] = await Promise.all(
    ['benchmark', 'binding', 'experiment', 'evaluation'].map(request));
  let benchmark = benchmarks.get(benchmarkBody.key);
  if (!benchmark) {
    benchmark = await call('publish_benchmark', benchmarkBody);
    benchmarks.set(benchmarkBody.key, benchmark);
  }
  const binding = await call('publish_binding', { ...bindingBody, benchmarkRevisionId: benchmark.id });
  assert.equal(binding.systemId, system.id);
  assert.equal(binding.systemName, system.name);
  assert.equal(binding.definition.systemIdentifier, system.identifier);
  assert.equal(binding.benchmark.id, benchmark.id);
  const experiment = await call('create_experiment', { ...experimentBody, projectId: project.id, bindingId: binding.id });
  assert.equal(experiment.projectId, project.id);
  assert.equal(experiment.bindingId, binding.id);
  assert.equal(experiment.systemId, system.id);
  const evaluation = await call('start_explicit_evaluation', { ...evaluationBody, name: experiment.name,
    experimentId: experiment.id, jobs: agents.map(() => evaluationBody.jobs[0]) });
  assert.equal(evaluation.projectId, project.id);
  assert.equal(evaluation.systemId, system.id);
  const { items: jobs } = await call('list_jobs', { evaluationId: evaluation.id });
  assert.equal(jobs.length, agents.length);
  assert.equal(new Set(jobs.map(job => job.id)).size, jobs.length);
  const frozen = await Promise.all(jobs.map(job => call('get_job_execution', { jobId: job.id })));
  const expectedSystem = { id: system.id, identifier: system.identifier, version: bindingBody.definition.systemVersion };
  const expectedBinding = { id: binding.id, version: binding.version };
  const specificationSha256 = frozen[0].specification.sha256;
  assert.ok(frozen.every(item => item.attempts.length === 0 && item.specification.sha256 === specificationSha256));
  for (const [index, item] of frozen.entries()) {
    assert.equal(item.repetitionId, jobs[index].id);
    assert.deepEqual(item.specification.setup, {});
    assert.deepEqual(item.specification.system, expectedSystem);
    assert.deepEqual(item.specification.binding, expectedBinding);
  }

  const failed = new Map();
  let routingProbe = null;
  if (otherSystem) {
    routingProbe = await checkWrongSystem(pilot, jobs, system, otherSystem);
    const jobId = jobs[0].id;
    const held = `${gzip}.held`;
    await rename(gzip, held);
    try {
      const { execution, attempt } = await runAgent(agents[0], jobId, pilot, expectedSystem, true);
      assert.equal(execution.specification.sha256, specificationSha256);
      failed.set(jobId, await inspect(pilot, jobId, execution, attempt));
    } finally {
      await rename(held, gzip);
    }
    const rescheduled = await fixtureApi.post(`/evaluations/${evaluation.id}/actions`, { action: 'reschedule' }, AbortSignal.timeout(25_000));
    assert.equal(rescheduled.changedJobs, 1);
  }

  const repetitions = [];
  for (const [index, job] of jobs.entries()) {
    const { execution, attempt } = await runAgent(agents[index], job.id, pilot, expectedSystem);
    assert.equal(execution.specification.sha256, specificationSha256);
    const selected = await inspect(pilot, job.id, execution, attempt);
    const failedAttempt = failed.get(job.id);
    assert.deepEqual(execution.attempts.map(item => item.id), failedAttempt ? [failedAttempt.attemptId, attempt.id] : [attempt.id]);
    if (failedAttempt) assert.equal(execution.attempts[0].selected, false);
    repetitions.push({ jobId: job.id, selected, failed: failedAttempt ?? null });
  }
  const finished = await call('get_evaluation', { evaluationId: evaluation.id });
  assert.equal(finished.counts.finished, jobs.length);
  assert.equal(finished.systemId, system.id);
  assert.equal(finished.systemName, system.name);
  const results = await call('get_results', { scope: 'evaluation', id: evaluation.id });
  assert.deepEqual(results.jobs.map(job => job.result), repetitions.map(item => item.selected.result));
  return { pilot, system: { ...system, version: expectedSystem.version }, benchmarkId: benchmark.id, bindingId: binding.id,
    experimentId: experiment.id, evaluationId: evaluation.id, specificationSha256, repetitions, routingProbe };
}

async function compare(project, studies, name) {
  const evaluationIds = studies.map(study => study.evaluationId);
  const comparison = await call('create_comparison', { projectId: project.id, name, evaluationIds });
  assert.deepEqual(comparison.evaluations.map(evaluation => evaluation.id), evaluationIds);
  assert.deepEqual(comparison.evaluations.map(evaluation => evaluation.systemName), studies.map(study => study.system.name));
  assert.deepEqual(comparison.evaluations.map(evaluation => evaluation.finished), studies.map(study => study.repetitions.length));
  const saved = await call('list_comparisons', { projectId: project.id });
  assert.deepEqual(saved.items.find(item => item.id === comparison.id), comparison);
  return comparison;
}

try {
  await client.connect(new StdioClientTransport({ command: process.execPath, args: [cli], env: fixtureEnv }));
  assert.deepEqual(await call('get_capabilities'), { benchmarkWorkflows: true });
  const pilots = values.database ? Object.keys(systemNames) : ['gzip', 'zstd'];
  const { systems, project } = await provisionSystems(pilots, `Benchmark study ${version}`);
  const gzipStudy = await runStudy({ pilot: 'gzip', agents: ['go', 'python'], project, system: systems.get('gzip'), otherSystem: systems.get('zstd') });
  const zstdStudy = await runStudy({ pilot: 'zstd', agents: ['python', 'go'], project, system: systems.get('zstd') });
  const batch = [gzipStudy, zstdStudy];
  const corpusDigests = batch.flatMap(study => study.repetitions.map(item => item.selected.result.restoredSha256));
  assert.equal(new Set(corpusDigests).size, 1);
  const batchComparison = await compare(project, batch, `Batch ${version}`);
  const summary = { studyRevision: values['study-revision'] ?? null, project, batch,
    batchComparisonId: batchComparison.id, comparisons: [batchComparison] };
  if (values.database) {
    const database = [
      await runStudy({ pilot: 'postgresql', agents: ['go'], project, system: systems.get('postgresql') }),
      await runStudy({ pilot: 'mariadb', agents: ['python'], project, system: systems.get('mariadb') }),
    ];
    assert.equal(database[0].repetitions[0].selected.result.datasetSha256, database[1].repetitions[0].selected.result.datasetSha256);
    const databaseComparison = await compare(project, database, `Database ${version}`);
    Object.assign(summary, { database, databaseComparisonId: databaseComparison.id });
    summary.comparisons.push(databaseComparison);
  }
  const destination = join(output, 'summary.json');
  await writeFile(destination, `${JSON.stringify(summary, null, 2)}\n`, { flag: 'wx' });
  console.log(destination);
} finally {
  await client.close();
}
