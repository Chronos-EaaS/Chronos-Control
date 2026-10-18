import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import { standaloneInstaller } from './standalone-installer.mjs';

const root = new URL('../../', import.meta.url);
const input = {
  entry: await readFile(new URL('backend/resources/install.php', root), 'utf8'),
  release: await readFile(new URL('backend/src/Infrastructure/Deployment/ReleaseManager.php', root), 'utf8'),
  installer: await readFile(new URL('backend/src/Infrastructure/Deployment/Installer.php', root), 'utf8'),
  applicationLock: await readFile(new URL('backend/src/Infrastructure/Deployment/ApplicationLock.php', root), 'utf8'),
  databaseDeployment: await readFile(new URL('backend/src/Infrastructure/Deployment/DatabaseDeployment.php', root), 'utf8'),
};

test('the standalone package keeps both deployment namespaces and an independent global installer entry', () => {
  const result = standaloneInstaller(input);
  assert.equal(result.match(/namespace Chronos\\Infrastructure\\Deployment \{/g)?.length, 4);
  assert.equal(result.match(/namespace \{/g)?.length, 1);
  assert.ok(result.includes('final class ReleaseManager\n'));
  assert.ok(result.includes('final class Installer\n'));
  assert.ok(result.includes('<form method="post">'));
  assert.ok(!result.includes("require_once __DIR__ . '/app/"));
  assert.ok(!/class_alias\(|vendor\/autoload\.php['"]\s*;/.test(result));
});

test('a renamed deployment namespace or implementation cannot silently produce a broken installer', () => {
  for (const key of ['release', 'installer', 'applicationLock', 'databaseDeployment']) {
    assert.throws(() => standaloneInstaller({ ...input, [key]: input[key].replace('namespace Chronos\\Infrastructure\\Deployment;', 'namespace Unexpected;') }), /namespaced/);
  }
  assert.throws(() => standaloneInstaller({ ...input, release: input.release.replace('final class ReleaseManager', 'final class OtherManager') }), /ReleaseManager/);
});

test('changed or repeated installer include statements fail before publishing', () => {
  const include = "require_once __DIR__ . '/app/Infrastructure/Deployment/Installer.php';";
  assert.throws(() => standaloneInstaller({ ...input, entry: input.entry.replace(include, '') }), /include changed/);
  assert.throws(() => standaloneInstaller({ ...input, entry: input.entry.replace(include, include + '\n' + include) }), /include changed/);
});

test('an incompatible PHP entry boundary cannot corrupt generated namespace blocks', () => {
  assert.throws(() => standaloneInstaller({ ...input, entry: input.entry.replace('declare(strict_types=1);', '') }), /strict types/);
  assert.throws(() => standaloneInstaller({ ...input, entry: input.entry + '\n<?php echo "changed entry context";' }), /end in its HTML/);
});
