import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { mkdtemp, readFile, rm, writeFile } from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

const wrapper = fileURLToPath(new URL('./test-migrations', import.meta.url));
const compose = fileURLToPath(new URL('./compose.migrations.test.yml', import.meta.url));

async function fixture(run) {
  const directory = await mkdtemp(path.join(os.tmpdir(), 'chronos-migration-wrapper-'));
  try {
    const log = path.join(directory, 'calls.jsonl');
    await writeFile(path.join(directory, 'docker'), '#!/usr/bin/env node\nconst fs=require("node:fs");const a=process.argv.slice(2);fs.appendFileSync(process.env.MIGRATION_CALLS,JSON.stringify(a)+"\\n");if(process.env.MIGRATION_FAIL_85==="1"&&a.at(-2)==="build"&&a.at(-1)==="php85")process.exit(17);\n', { mode: 0o755 });
    const invoke = (args, fail = false) => spawnSync('/bin/sh', [wrapper, ...args], { cwd: directory, env: { ...process.env, PATH: directory + path.delimiter + process.env.PATH, MIGRATION_CALLS: log, MIGRATION_FAIL_85: fail ? '1' : '0' }, encoding: 'utf8' });
    const calls = async () => { try { return (await readFile(log, 'utf8')).trim().split('\n').filter(Boolean).map(line => JSON.parse(line)); } catch (error) { if (error.code === 'ENOENT') return []; throw error; } };
    await run(invoke, calls);
  } finally { await rm(directory, { recursive: true, force: true }); }
}

test('migration fixtures refuse caller-supplied database targets before invoking Docker', () => fixture(async (invoke, calls) => {
  const result = invoke(['production']);
  assert.equal(result.status, 2);
  assert.deepEqual(await calls(), []);
}));

test('fixture cleanup stays bound to its own project and Compose file from any working directory', () => fixture(async (invoke, calls) => {
  assert.equal(invoke(['down']).status, 0);
  assert.deepEqual(await calls(), [['compose', '--project-name', 'chronos-v3-migration-regression', '--file', compose, 'down', '--volumes', '--remove-orphans']]);
}));

test('a failed second runtime cleans its disposable database without running further tests', () => fixture(async (invoke, calls) => {
  const result = invoke(['all'], true);
  assert.equal(result.status, 17);
  const commands = await calls();
  for (const command of commands) assert.deepEqual(command.slice(0, 5), ['compose', '--project-name', 'chronos-v3-migration-regression', '--file', compose]);
  assert.ok(commands.some(command => command.includes('/opt/chronos/backend/tests/integration/database-migrations.php')));
  assert.ok(commands.some(command => command.includes('/opt/chronos/backend/tests/integration/database-deployment.php')));
  assert.ok(commands.some(command => command.includes('/opt/chronos/backend/tests/integration/event-timeline-index.php')));
  assert.ok(commands.some(command => command.includes('/opt/chronos/backend/tests/integration/scoped-event-timeline-indexes.php')));
  assert.ok(commands.some(command => command.includes('/opt/chronos/backend/tests/integration/resource-event-timeline-index.php')));
  assert.ok(commands.some(command => command.includes('/opt/chronos/backend/tests/integration/scoped-workflow-timeline.php')));
  assert.ok(!commands.some(command => command.includes('run') && command.includes('php85')));
  assert.deepEqual(commands.at(-1).slice(5), ['down', '--volumes', '--remove-orphans']);
}));
