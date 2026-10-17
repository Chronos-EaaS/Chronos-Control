import { execFileSync } from 'node:child_process';
import { resolve } from 'node:path';
import { test, expect, type APIResponse } from '@playwright/test';

type Envelope<T = Record<string, unknown>> = {
  status: { code: number; message: string };
  response?: T;
};

async function envelope<T = Record<string, unknown>>(response: APIResponse, code = 200): Promise<Envelope<T>> {
  // v1 has application status codes inside an HTTP 200 envelope. This contract
  // deliberately differs from the new UI API's normal HTTP error semantics.
  expect(response.status()).toBe(200);
  expect(response.headers()['content-type']).toMatch(/^application\/json/);
  const result = await response.json() as Envelope<T>;
  expect(result.status.code, result.status.message).toBe(code);
  expect(typeof result.status.message).toBe('string');
  return result;
}

test.beforeEach(async ({ baseURL }) => {
  if (baseURL !== 'http://127.0.0.1:8088') throw new Error('Agent contract tests require the fixed disposable stack.');
  execFileSync(resolve('scripts/test-stack'), ['reset'], { timeout: 30_000, stdio: 'pipe' });
});

test('V1-JOB-01 specific job preserves numeric fields, environment and XML CDL', async ({ request }) => {
  const result = await envelope(await request.get('/api/v1/job/id=90'));
  expect(result.response).toMatchObject({ id: 90, user: 3, username: 'owner', status: 0, progress: 0, environment: 'system-agent', system: 'fixture-system' });
  expect(typeof result.response?.phases).toBe('number');
  expect(typeof result.response?.currentPhase).toBe('number');
  expect(result.response?.cdl).toMatch(/<\?xml[^>]*>\s*<chronos/);
  expect(result.response?.created).toMatch(/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/);
});

test('V1-JOB-02 missing job uses application code 602', async ({ request }) => {
  const result = await envelope(await request.get('/api/v1/job/id=999999'), 602);
  expect(result.status.message).toContain('Job does not exist');
  expect(result).not.toHaveProperty('response');
});

test('V1-JOB-03 next job is filtered by system environment; empty queue uses 601', async ({ request }) => {
  const next = await envelope(await request.get('/api/v1/job/id=next/supports=demoall/environment=local'));
  expect(next.response).toMatchObject({ status: 0, environment: 'system-local' });
  const missing = await envelope(await request.get('/api/v1/job/id=next/supports=demoall/environment=empty-fixture'), 601);
  expect(missing.status.message).toContain('No job in queue');
});

test('V1-JOB-04 URL-encoded lifecycle updates persist and phase changes reset progress', async ({ request }) => {
  const update = await envelope(await request.patch('/api/v1/job/id=90', { form: { status: 2, progress: 47 } }));
  expect(update).not.toHaveProperty('response');
  expect((await envelope(await request.get('/api/v1/job/id=90'))).response).toMatchObject({ status: 2, progress: 47 });

  await envelope(await request.patch('/api/v1/job/id=90', { form: { currentPhase: 3, progress: 80 } }));
  expect((await envelope(await request.get('/api/v1/job/id=90'))).response).toMatchObject({ currentPhase: 3, progress: 0 });

  await envelope(await request.patch('/api/v1/job/id=90', { form: { currentPhase: 99, progress: 25 } }));
  expect((await envelope(await request.get('/api/v1/job/id=90'))).response).toMatchObject({ currentPhase: 3, progress: 25 });
});

test('V1-JOB-05 result JSON remains a URL-encoded field and log appends preserve content', async ({ request }) => {
  const result = JSON.stringify({ throughput: 123.5, note: 'A&B + Zürich' });
  await envelope(await request.patch('/api/v1/job/id=90', { form: { result, status: 3 } }));
  await envelope(await request.post('/api/v1/job/id=90/action=appendLog', { form: { log: 'Agent: A&B + Zürich\n' } }));
  const log = await envelope(await request.get('/api/ui/job/id=90/withLog=true'));
  expect(log.response?.log).toContain('Agent: A&B + Zürich\n');
  expect((await envelope(await request.get('/api/v1/job/id=90'))).response?.status).toBe(3);
});

test('V1-JOB-06 HTTP upload target retains its legacy response shape', async ({ request }) => {
  const result = await envelope(await request.post('/api/v1/job/id=90/action=getUploadTarget'));
  expect(result.response).toEqual({ method: 'http', path: '/api/v1/job', hostname: 'http://127.0.0.1:8088' });
});

test('V1-JOB-07 multipart result archive upload succeeds without JSON-only parsing', async ({ request }) => {
  // Empty ZIP end-of-central-directory record; no external fixture/download.
  const archive = Buffer.from('504b0506000000000000000000000000000000000000', 'hex');
  await envelope(await request.post('/api/v1/job/id=90/action=upload', {
    multipart: { result: { name: 'result.zip', mimeType: 'application/zip', buffer: archive } },
  }));
  const stored = await request.get('/data/evaluation/90.zip');
  expect(stored.status()).toBe(200);
  expect(await stored.body()).toEqual(archive);
});

const cem = '/api/v1/cem/uniqueId=fixture-contract/version=2/environment=fixture';

test('V1-CEM-01 old client and unknown environment keep codes 631 and 632', async ({ request }) => {
  await envelope(await request.get('/api/v1/cem/uniqueId=fixture-contract/version=1/environment=fixture'), 631);
  await envelope(await request.get('/api/v1/cem/uniqueId=fixture-contract/version=2/environment=unknown-fixture'), 632);
});

test('V1-CEM-02 specific job provides setup data and CDL', async ({ request }) => {
  const result = await envelope(await request.get(`${cem}/jobId=90`));
  expect(result.response?.job).toMatchObject({ id: 90, status: 0 });
  expect(result.response?.system).toMatchObject({ id: 1 });
  expect(result.response?.setup).toMatchObject({ fixtureSetup: 'fixture-setup-value' });
  expect(result.response?.cdl).toMatch(/<\?xml/);
});

test('V1-CEM-03 start, telemetry, log and termination persist job lifecycle', async ({ request }) => {
  await envelope(await request.post(`${cem}/action=jobStarted`, { form: { jobId: 90 } }));
  expect((await envelope(await request.get('/api/v1/job/id=90'))).response?.status).toBe(1);

  await envelope(await request.post(`${cem}/action=nodeStatus`, { form: {
    jobId: 90, cpu: 12.5, memoryUsed: 1024, memoryTotal: 4096,
    hostname: 'fixture-node', os: 'Fixture Linux', healthStatus: '',
  } }));
  await envelope(await request.post(`${cem}/action=jobLog`, { form: { jobId: 90, message: 'setup complete' } }));
  const log = await envelope(await request.get('/api/ui/job/id=90/withLog=true'));
  expect(log.response?.log).toContain('CEM > setup complete');

  await envelope(await request.post(`${cem}/action=jobTerminated`, { form: { jobId: 90, reason: 'simulated fixture failure' } }));
  expect((await envelope(await request.get('/api/v1/job/id=90'))).response?.status).toBe(-2);
});
