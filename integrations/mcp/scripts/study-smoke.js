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
const agentEnv = { ...process.env, NO_PROXY: '127.0.0.1,::1', no_proxy: '127.0.0.1,::1' };
delete agentEnv.CHRONOS_AUTHORIZATION;
delete agentEnv.CHRONOS_ATTEMPT_TOKEN;

async function call(name, args = {}) {
  const response = await client.callTool({ name: `chronos_${name}`, arguments: args }, { timeout: 30_000 });
  assert.ok(!response.isError, JSON.stringify(response.structuredContent ?? response.content));
  return response.structuredContent;
}

async function runAgent(agent, jobId, pilot, expectedFailure = false) {
  const worker = `study-${agent}-${pilot}`;
  const work = join(output, `${agent}-runs`);
  const args = agent === 'go' ? [go, ['-url', origin.origin, '-mode', 'attempt-v1', '-worker', worker,
    '-environment', 'local', '-supports', 'fixture-system', '-artifact-cache', cache, '-work-dir', work, '-timeout', '3m', '-once'], agentEnv]
    : ['python3', ['-m', 'chronos_agent', '--url', origin.origin, '--protocol', 'attempt-v1', '--worker', worker,
      '--environment', 'local', '--system', 'fixture-system', '--artifact-cache', cache, '--work-dir', work, '--timeout', '180', '--once'],
    { ...agentEnv, PYTHONPATH: pythonSrc, PYTHONDONTWRITEBYTECODE: '1' }];
  const [command, commandArgs, env] = args;
  let response;
  try {
    response = await run(command, commandArgs, { env, timeout: 240_000 });
  } catch (error) {
    if (!Number.isInteger(error.code)) throw error;
    response = error;
  }
  const label = `${pilot}-${jobId}-${agent}${expectedFailure ? '-failed' : ''}`;
  await Promise.all(['stdout', 'stderr'].map(stream => writeFile(join(logs, `${label}.${stream}.log`), response[stream])));
  assert.equal(Boolean(response.code), expectedFailure, `${label}\n${response.stdout}\n${response.stderr}`);
  const execution = await call('get_job_execution', { jobId });
  const last = execution.attempts.at(-1);
  assert.equal(last?.worker.id, worker, `${label} did not claim repetition ${jobId}`);
  assert.equal(last.status, expectedFailure ? 'failed' : 'finished');
  assert.equal(last.selected, !expectedFailure);
  assert.equal(execution.selectedAttemptId, expectedFailure ? null : last.id);
  return { execution, attempt: last };
}

async function inspect(pilot, jobId, execution, attempt) {
  const args = { jobId, attemptId: attempt.id };
  const archive = await call('export_attempt_evidence', { ...args, format: 'zip' });
  const log = await call('export_attempt_evidence', { ...args, format: 'log' });
  assert.equal(archive.sha256, attempt.archive.sha256);
  assert.equal(archive.sizeBytes, attempt.archive.sizeBytes);
  const { stdout } = await run('python3', ['-I', inspectZip, archive.path, pilot], { timeout: 30_000 });
  const evidence = JSON.parse(stdout);
  assert.equal(evidence.repetitionId, jobId);
  assert.equal(evidence.attemptId, attempt.id);
  assert.equal(evidence.specificationSha256, execution.specification.sha256);
  assert.deepEqual(evidence.result, attempt.result);
  assert.ok(evidence.stderr.length > 0 && (await readFile(log.path, 'utf8')).includes(evidence.stderr));
  for (const artifact of execution.specification.artifacts.artifacts) {
    assert.deepEqual(attempt.observedArtifacts[artifact.role], { sha256: artifact.sha256, sizeBytes: artifact.sizeBytes });
  }
  return { attemptId: attempt.id, worker: attempt.worker.id, status: attempt.status,
    archive, log, entryCount: evidence.entryCount, studyRecord: evidence.studyRecord, result: evidence.result };
}

const version = `smoke-${randomUUID().slice(0, 8)}`;
const benchmarks = new Map();
async function runStudy(pilot, agents, needsRetry) {
  const path = join(output, `${pilot}-requests`);
  const batch = pilot === 'gzip' || pilot === 'zstd';
  const stageArgs = [join(studyDir, 'stage.py'), pilot, '--cache', cache, '--output', path, '--system-id', '1',
    '--environment', 'system-local', '--benchmark-version', version];
  if (pilot === 'gzip') stageArgs.push('--executable', gzip);
  if (!batch && values['docker-socket']) stageArgs.push('--docker-socket', resolve(values['docker-socket']));
  if (values['study-revision']) stageArgs.push('--source-revision', values['study-revision']);
  await run('python3', stageArgs, { timeout: 60_000 });
  const request = async name => JSON.parse(await readFile(join(path, `${name}.json`), 'utf8'));
  const [benchmarkBody, bindingBody, experimentBody, evaluationBody] = await Promise.all(
    ['benchmark', 'binding', 'experiment', 'evaluation'].map(request));
  let benchmark = benchmarks.get(benchmarkBody.key);
  if (!benchmark) {
    benchmark = await call('publish_benchmark', benchmarkBody);
    benchmarks.set(benchmarkBody.key, benchmark);
  }
  const binding = await call('publish_binding', { ...bindingBody, benchmarkRevisionId: benchmark.id });
  const experiment = await call('create_experiment', { ...experimentBody, projectId: 1, bindingId: binding.id });
  const evaluation = await call('start_explicit_evaluation', { ...evaluationBody, name: experiment.name,
    experimentId: experiment.id, jobs: agents.map(() => evaluationBody.jobs[0]) });
  const { items: jobs } = await call('list_jobs', { evaluationId: evaluation.id });
  assert.equal(jobs.length, agents.length);
  assert.equal(new Set(jobs.map(job => job.id)).size, jobs.length);
  const frozen = await Promise.all(jobs.map(job => call('get_job_execution', { jobId: job.id })));
  const specificationSha256 = frozen[0].specification.sha256;
  assert.ok(frozen.every(item => item.attempts.length === 0 && item.specification.sha256 === specificationSha256));
  assert.deepEqual(frozen[0].specification.setup, {});

  const failed = new Map();
  if (needsRetry) {
    const jobId = jobs[0].id;
    const held = `${gzip}.held`;
    await rename(gzip, held);
    try {
      const { execution, attempt } = await runAgent(agents[0], jobId, pilot, true);
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
    const { execution, attempt } = await runAgent(agents[index], job.id, pilot);
    assert.equal(execution.specification.sha256, specificationSha256);
    const selected = await inspect(pilot, job.id, execution, attempt);
    const failedAttempt = failed.get(job.id);
    assert.deepEqual(execution.attempts.map(item => item.id), failedAttempt ? [failedAttempt.attemptId, attempt.id] : [attempt.id]);
    if (failedAttempt) assert.equal(execution.attempts[0].selected, false);
    repetitions.push({ jobId: job.id, selected, failed: failedAttempt ?? null });
  }
  const finished = await call('get_evaluation', { evaluationId: evaluation.id });
  assert.equal(finished.counts.finished, jobs.length);
  const results = await call('get_results', { scope: 'evaluation', id: evaluation.id });
  assert.deepEqual(results.jobs.map(job => job.result), repetitions.map(item => item.selected.result));
  return { pilot, benchmarkId: benchmark.id, bindingId: binding.id, experimentId: experiment.id,
    evaluationId: evaluation.id, specificationSha256, repetitions };
}

async function compare(studies, name) {
  const evaluationIds = studies.map(study => study.evaluationId);
  const comparison = await call('create_comparison', { projectId: 1, name, evaluationIds });
  assert.deepEqual(comparison.evaluations.map(evaluation => evaluation.id), evaluationIds);
  assert.deepEqual(comparison.evaluations.map(evaluation => evaluation.finished), studies.map(study => study.repetitions.length));
  assert.ok((await call('list_comparisons', { projectId: 1 })).items.some(item => item.id === comparison.id));
  return comparison.id;
}

try {
  await client.connect(new StdioClientTransport({ command: process.execPath, args: [cli], env: fixtureEnv }));
  assert.deepEqual(await call('get_capabilities'), { benchmarkWorkflows: true });
  assert.equal((await call('get_project', { projectId: 1 })).name, 'Fixture Project');
  const gzipStudy = await runStudy('gzip', ['go', 'python'], true);
  const zstdStudy = await runStudy('zstd', ['python', 'go'], false);
  const batch = [gzipStudy, zstdStudy];
  const corpusDigests = batch.flatMap(study => study.repetitions.map(item => item.selected.result.restoredSha256));
  assert.equal(new Set(corpusDigests).size, 1);
  const summary = { studyRevision: values['study-revision'] ?? null, batch,
    batchComparisonId: await compare(batch, `Batch ${version}`) };
  if (values.database) {
    const database = [await runStudy('postgresql', ['go'], false), await runStudy('mariadb', ['python'], false)];
    assert.equal(database[0].repetitions[0].selected.result.datasetSha256, database[1].repetitions[0].selected.result.datasetSha256);
    Object.assign(summary, { database, databaseComparisonId: await compare(database, `Database ${version}`) });
  }
  const destination = join(output, 'summary.json');
  await writeFile(destination, `${JSON.stringify(summary, null, 2)}\n`, { flag: 'wx' });
  console.log(destination);
} finally {
  await client.close();
}
