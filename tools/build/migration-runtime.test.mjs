import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { mkdtemp, readFile, rm, writeFile } from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';
import { migrationRuntime, migrationSources } from './migration-runtime.mjs';

const sources = Object.fromEntries(await Promise.all(migrationSources.map(async name => [name, await readFile(new URL('../../backend/src/Infrastructure/Persistence/Migrations/' + name + '.php', import.meta.url), 'utf8')])));

test('target migration code loads independently of another release and can be loaded repeatedly without database access', async () => {
  const directory = await mkdtemp(path.join(os.tmpdir(), 'chronos-migration-bundle-'));
  try {
    const first = path.join(directory, 'first.php'), second = path.join(directory, 'second.php');
    const changed = { ...sources, UserEnabledMigration: sources.UserEnabledMigration.replaceAll('202610180001_user_enabled', '202610190001_user_enabled') };
    assert.notEqual(changed.UserEnabledMigration, sources.UserEnabledMigration);
    await writeFile(first, migrationRuntime(sources)); await writeFile(second, migrationRuntime(changed));
    const script = '$one = require $argv[1]; $two = require $argv[2]; $again = require $argv[1]; echo json_encode([$one["version"], $two["version"], $again["version"], $one["run"] instanceof Closure, $two["run"] instanceof Closure]);';
    const result = JSON.parse(execFileSync('php', ['-n', '-r', script, first, second], { encoding: 'utf8' }));
    assert.deepEqual(result, ['202610180001_user_enabled', '202610190001_user_enabled', '202610180001_user_enabled', true, true]);
  } finally { await rm(directory, { recursive: true, force: true }); }
});

test('the isolated runtime pins original migration source checksums consistently with ordinary application loading', () => {
  const output = migrationRuntime(sources);
  assert.ok(output.includes(createHash('sha256').update(sources.UserEnabledMigration).digest('hex')));
  assert.equal(output, migrationRuntime(sources));
  assert.ok(!output.includes('eval('));
  assert.ok(!output.includes('vendor/autoload.php'));
});

test('an incomplete or incompatible source set cannot silently create a deployment runtime', () => {
  assert.throws(() => migrationRuntime({ ...sources, Unexpected: '<?php' }), /complete migration/);
  assert.throws(() => migrationRuntime({ ...sources, MigrationRunner: sources.MigrationRunner.replace('namespace Chronos\\Infrastructure\\Persistence\\Migrations;', 'namespace Wrong;') }), /namespace/);
  assert.throws(() => migrationRuntime({ ...sources, MigrationRunner: sources.MigrationRunner.replace('final class MigrationRunner', 'final class RenamedRunner') }), /declaration/);
});
