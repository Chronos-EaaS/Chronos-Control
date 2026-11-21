import assert from 'node:assert/strict';
import { execFile } from 'node:child_process';
import { createHash, randomUUID } from 'node:crypto';
import { mkdtemp, readFile, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { promisify } from 'node:util';
import { Client } from '@modelcontextprotocol/client';
import { StdioClientTransport } from '@modelcontextprotocol/client/stdio';

const run = promisify(execFile);
const cli = fileURLToPath(new URL('../src/index.js', import.meta.url));
const worker = fileURLToPath(new URL('../../../tools/workers/chronos-command.py', import.meta.url));

const origin = new URL(process.env.CHRONOS_URL ?? 'http://127.0.0.1:8089');
assert.equal(origin.protocol, 'http:', 'Use the disposable loopback HTTP fixture.');
assert.ok(['127.0.0.1', '[::1]'].includes(origin.hostname) && origin.port && origin.href === `${origin.origin}/`,
  'Use the origin and port of the disposable loopback fixture.');
const health = await fetch(new URL('/healthz.txt', origin), { redirect: 'error', signal: AbortSignal.timeout(5000) });
assert.ok(health.ok && (await health.text()).trim() === 'chronos-test-ready', 'The Chronos test fixture is not ready.');

const directory = await mkdtemp(join(tmpdir(), 'chronos-mcp-smoke-'));
const client = new Client({ name: 'chronos-mcp-live-smoke', version: '1.0.0' }, { versionNegotiation: { mode: 'auto' } });

async function call(name, args = {}) {
  const result = await client.callTool({ name: `chronos_${name}`, arguments: args }, { timeout: 30_000 });
  assert.ok(!result.isError, JSON.stringify(result.structuredContent ?? result.content));
  return result.structuredContent;
}

async function catalog() {
  const combined = { benchmarks: [], bindings: [], systems: [] };
  let page = 1;
  while (page !== null) {
    const response = await call('list_benchmarks', { page });
    for (const key of Object.keys(combined)) combined[key].push(...response[key]);
    page = response.nextPage;
  }
  return combined;
}

try {
  await client.connect(new StdioClientTransport({
    command: process.execPath,
    args: [cli],
    env: {
      CHRONOS_URL: origin.origin,
      CHRONOS_USERNAME: 'owner',
      CHRONOS_PASSWORD: 'Chronos-test-2026!',
      CHRONOS_EXPORT_DIR: directory,
    },
  }));
  assert.deepEqual(await call('get_capabilities'), { benchmarkWorkflows: true });
  assert.ok((await catalog()).systems.some(system => system.id === 1 && system.name === 'Fixture System'));
  assert.equal((await call('get_project', { projectId: 1 })).name, 'Fixture Project');
  assert.equal((await call('get_job_execution', { jobId: 5 })).resultSelection, 'legacy-job-result');

  const name = `MCP smoke ${randomUUID().slice(0, 8)}`;
  const label = name.replaceAll(' ', '-').toLowerCase();
  const driver = Buffer.from('#!/bin/sh\ncat >/dev/null\nprintf "MCP smoke diagnostic\\n" >&2\nprintf \'{"durationMs":12.5}\\n\'\n');
  const sha256 = createHash('sha256').update(driver).digest('hex');
  await writeFile(join(directory, sha256), driver, { mode: 0o600 });
  const benchmark = await call('publish_benchmark', { key: label, version: '1.0', name, contract: {
    parameters: { workers: { type: 'integer', default: 3 } },
    measurement: 'Return the synthetic duration in milliseconds.',
    lifecycle: { warmUp: 'disabled' },
    results: { durationMs: { type: 'number', unit: 'ms' } },
  } });
  const binding = await call('publish_binding', { benchmarkRevisionId: benchmark.id, systemId: 1, version: '1.0', definition: {
    systemVersion: 'fixture-1', adapter: 'chronos-command@1', parameterMap: { workers: 'threads' },
    artifacts: { schemaVersion: 1, artifacts: [{ role: 'driver', uri: `fixture:${label}`, sha256, sizeBytes: driver.length }] },
  } });
  const published = await catalog();
  assert.ok(published.benchmarks.some(item => item.id === benchmark.id));
  assert.ok(published.bindings.some(item => item.id === binding.id));

  const experimentArgs = { projectId: 1, bindingId: binding.id, planning: 'explicit' };
  const options = await call('get_experiment_options', experimentArgs);
  assert.equal(options.binding.id, binding.id);
  const experiment = await call('create_experiment', { ...experimentArgs, name });
  assert.equal(experiment.bindingId, binding.id);
  const runOptions = await call('get_run_options', { experimentId: experiment.id });
  assert.equal(runOptions.planning, 'explicit');
  assert.equal(runOptions.binding.id, binding.id);
  assert.ok(runOptions.environments.some(environment => environment.key === 'system-local'));
  const evaluation = await call('start_explicit_evaluation', {
    experimentId: experiment.id, environment: 'system-local', name, jobs: [{ parameters: { workers: 3 } }, { parameters: {} }],
  });
  assert.equal(evaluation.counts.total, 2);
  const { items: jobs } = await call('list_jobs', { evaluationId: evaluation.id });
  assert.equal(jobs.length, 2);
  assert.notEqual(jobs[0].id, jobs[1].id);
  const planned = await Promise.all(jobs.map(job => call('get_job_execution', { jobId: job.id })));
  assert.ok(planned.every(item => item.attempts.length === 0 && item.selectedAttemptId === null));
  assert.equal(planned[0].specification.sha256, planned[1].specification.sha256);
  assert.equal(planned[0].specification.effectiveParameters.threads, 3);
  assert.equal(planned[0].specification.artifacts.artifacts[0].sha256, sha256);

  for (const job of jobs) {
    const { stdout } = await run('python3', [worker, origin.origin, '--worker', label, '--environment', 'local',
      '--supports', 'fixture-system', '--cache', directory, '--timeout', '10'], { timeout: 60_000 });
    assert.match(stdout, new RegExp(`Completed repetition ${job.id}, attempt \\d+\\.`));
  }
  assert.equal((await call('get_evaluation', { evaluationId: evaluation.id })).counts.finished, 2);
  const jobId = jobs[0].id;
  const finished = await call('get_job_execution', { jobId });
  assert.equal(finished.specification.sha256, planned[0].specification.sha256);
  assert.equal(finished.resultSelection, 'successful-attempt');
  const [selected] = finished.attempts;
  assert.equal(selected.id, finished.selectedAttemptId);
  assert.equal(selected.selected, true);
  assert.deepEqual(selected.result, { durationMs: 12.5 });
  assert.equal(selected.observedArtifacts.driver.sha256, sha256);

  const save = format => call('export_attempt_evidence', { jobId, attemptId: selected.id, format });
  const log = await save('log');
  assert.equal(await readFile(log.path, 'utf8'), 'MCP smoke diagnostic\n');
  const archive = await save('zip');
  assert.equal(archive.sha256, selected.archive.sha256);
  assert.equal(archive.sizeBytes, selected.archive.sizeBytes);
  assert.equal((await readFile(archive.path)).subarray(0, 4).toString('hex'), '504b0304');
  const results = await call('get_results', { scope: 'evaluation', id: evaluation.id });
  assert.deepEqual(results.jobs.map(row => row.result.durationMs), [12.5, 12.5]);

  const comparison = await call('create_comparison', { projectId: 1, name, evaluationIds: [evaluation.id, 2] });
  assert.deepEqual(comparison.evaluations.map(item => item.id), [evaluation.id, 2]);
  assert.equal(comparison.evaluations[0].finished, 2);
  assert.ok((await call('list_comparisons', { projectId: 1 })).items.some(item => item.id === comparison.id));

  console.log(JSON.stringify({ benchmarkId: benchmark.id, bindingId: binding.id, experimentId: experiment.id,
    evaluationId: evaluation.id, repetitionIds: jobs.map(job => job.id), selectedAttemptId: selected.id, comparisonId: comparison.id }));
} finally {
  try {
    await client.close();
  } finally {
    await rm(directory, { recursive: true, force: true });
  }
}
