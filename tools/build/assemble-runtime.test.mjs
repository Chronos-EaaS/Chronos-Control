import assert from 'node:assert/strict';
import { mkdtemp, mkdir, readFile, rm, symlink, writeFile } from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';
import { assembleRuntime, assemblyMarker, historicalRemovals, runtimeLayout, verifyRuntime } from './assemble-runtime.mjs';
import { migrationSources } from './migration-runtime.mjs';

async function fixture(run) {
  const root = await mkdtemp(path.join(os.tmpdir(), 'chronos-runtime-assembly-'));
  try {
    for (const mapping of runtimeLayout.mappings) {
      if (mapping.kind === 'directory') {
        await mkdir(path.join(root, mapping.source), { recursive: true });
        await writeFile(path.join(root, mapping.source, 'fixture.php'), `<?php /* ${mapping.source} */`);
      } else {
        await mkdir(path.dirname(path.join(root, mapping.source)), { recursive: true });
        await writeFile(path.join(root, mapping.source), `fixture ${mapping.destination}`);
      }
    }
    const migrations = 'backend/src/Infrastructure/Persistence/Migrations';
    await mkdir(path.join(root, migrations), { recursive: true });
    for (const name of migrationSources) await writeFile(path.join(root, migrations, name + '.php'), await readFile(new URL('../../' + migrations + '/' + name + '.php', import.meta.url)));
    const composer = { name: 'chronos-eaas/fixture', require: { 'psr/log': '^3.0.2' }, 'require-dev': { 'phpunit/phpunit': '^12.5' }, autoload: { 'psr-4': { 'Chronos\\': 'src/' } }, 'autoload-dev': { 'psr-4': { 'Chronos\\Tests\\': 'tests/unit/' } } };
    await writeFile(path.join(root, 'backend/composer.json'), JSON.stringify(composer));
    await writeFile(path.join(root, 'backend/composer.lock'), '{"packages":[],"packages-dev":[]}\n');
    await run(root);
  } finally { await rm(root, { recursive: true, force: true }); }
}

test('Git-free assembly maps source bytes and derives independent runtime Composer inputs', () => fixture(async root => {
  await mkdir(path.join(root, 'backend/vendor'), { recursive: true });
  await writeFile(path.join(root, 'backend/vendor/dev-sentinel'), 'retained development dependencies');
  const sourceComposer = await readFile(path.join(root, 'backend/composer.json'));
  const result = await assembleRuntime({ root });
  assert.equal(result.buildId, null);
  assert.equal(result.runtimeLayout, 'application-v1');
  for (const file of result.files) assert.deepEqual(await readFile(path.join(result.directory, file.path)), await readFile(path.join(root, file.source)));
  const composer = JSON.parse(await readFile(path.join(result.directory, 'composer.json'), 'utf8'));
  assert.deepEqual(composer.autoload, { 'psr-4': { 'Chronos\\': 'app/' } });
  assert.equal(composer['autoload-dev'], undefined);
  assert.equal(composer['require-dev']['phpunit/phpunit'], '^12.5');
  assert.deepEqual(await readFile(path.join(result.directory, 'composer.lock')), await readFile(path.join(root, 'backend/composer.lock')));
  assert.deepEqual(await readFile(path.join(root, 'backend/composer.json')), sourceComposer);
  assert.equal(await readFile(path.join(root, 'backend/vendor/dev-sentinel'), 'utf8'), 'retained development dependencies');
  await verifyRuntime({ root, buildId: 'a'.repeat(40) });
}));

test('persistent public data, compiled frontend and Markdown never enter runtime source staging', () => fixture(async root => {
  for (const name of ['systems/private.php', 'data/private.zip', 'media/private.png', 'ui/main.js']) {
    await mkdir(path.dirname(path.join(root, 'backend/public', name)), { recursive: true });
    await writeFile(path.join(root, 'backend/public', name), 'private');
  }
  await writeFile(path.join(root, 'backend/src/README.md'), 'source notes');
  const result = await assembleRuntime({ root });
  assert.ok(!result.files.some(file => /private|main\.js|README/.test(file.path)));
}));

test('stale source and a changed build identity cannot reuse an assembled runtime', () => fixture(async root => {
  await assembleRuntime({ root, buildId: 'a'.repeat(40) });
  await assert.rejects(verifyRuntime({ root, buildId: 'b'.repeat(40) }), /stale/);
  await writeFile(path.join(root, 'backend/src/fixture.php'), '<?php /* changed */');
  await assert.rejects(verifyRuntime({ root, buildId: 'a'.repeat(40) }), /stale/);
}));

test('tampered runtime bytes and rewritten Composer inputs are rejected independently', () => fixture(async root => {
  const result = await assembleRuntime({ root });
  const file = path.join(result.directory, 'app/fixture.php');
  const original = await readFile(file);
  await writeFile(file, 'tampered runtime');
  await assert.rejects(verifyRuntime({ root }), /runtime file changed/);
  await writeFile(file, original);
  const database = await readFile(path.join(result.directory, 'database.php'));
  await writeFile(path.join(result.directory, 'database.php'), 'tampered migration code');
  await assert.rejects(verifyRuntime({ root }), /database migration runtime changed/);
  await writeFile(path.join(result.directory, 'database.php'), database);
  await writeFile(path.join(result.directory, 'composer.json'), '{}');
  await assert.rejects(verifyRuntime({ root }), /Composer inputs changed/);
}));

test('a release cannot use ignored or untracked runtime source files', () => fixture(async root => {
  const result = await assembleRuntime({ root });
  const trackedSources = new Set(result.files.map(file => file.source));
  trackedSources.delete(result.files[0].source);
  await assert.rejects(verifyRuntime({ root, trackedSources }), /untracked runtime source/);
}));

test('source and output symlinks never escape their fixture boundary', () => fixture(async root => {
  const outside = path.join(root, 'outside'); await mkdir(outside); await writeFile(path.join(outside, 'sentinel'), 'unchanged');
  await symlink(path.join(outside, 'sentinel'), path.join(root, 'backend/src/link.php'));
  await assert.rejects(assembleRuntime({ root }), /Symbolic links/);
  await rm(path.join(root, 'backend/src/link.php'));
  await mkdir(path.join(root, 'dist')); await symlink(outside, path.join(root, 'dist/link'));
  await assert.rejects(assembleRuntime({ root, output: 'dist/link/runtime' }), /Symbolic links/);
  assert.equal(await readFile(path.join(outside, 'sentinel'), 'utf8'), 'unchanged');
}));

test('output replacement refuses source paths, unmanaged directories and persistent application configuration', () => fixture(async root => {
  await assert.rejects(assembleRuntime({ root, output: 'backend/src' }), /repository dist/);
  await mkdir(path.join(root, 'dist/runtime'), { recursive: true });
  await writeFile(path.join(root, 'dist/runtime/sentinel'), 'unchanged');
  await assert.rejects(assembleRuntime({ root }), /unmanaged/);
  assert.equal(await readFile(path.join(root, 'dist/runtime/sentinel'), 'utf8'), 'unchanged');
  await rm(path.join(root, 'dist/runtime'), { recursive: true });
  const result = await assembleRuntime({ root });
  assert.equal(JSON.parse(await readFile(path.join(result.directory, assemblyMarker), 'utf8')).format, 1);
  await writeFile(path.join(result.directory, 'config.php'), 'private config');
  await assert.rejects(assembleRuntime({ root }), /containing config\.php/);
  assert.equal(await readFile(path.join(result.directory, 'config.php'), 'utf8'), 'private config');
}));

test('historical cleanup uses runtime names and excludes both shipped files and persistent data', () => {
  const removed = historicalRemovals(['core/kept.php', 'webroot/assets/old.js', 'webroot/data/private.zip', 'webroot/assets/old.js'], new Set(['core/kept.php']));
  assert.deepEqual(removed, ['webroot/assets/old.js']);
  assert.throws(() => historicalRemovals(['backend/src/Example.php'], new Set()), /not a historical runtime/);
});

test('canonical UI and supported agent runtime entrypoints have no preview compatibility', () => {
  assert.equal(runtimeLayout.runtimeLayout, 'application-v1');
  assert.ok(!runtimeLayout.mappings.some(mapping => mapping.source.startsWith('backend/legacy/')));
  assert.ok(!runtimeLayout.mappings.some(mapping => mapping.source.startsWith('backend/compatibility/')));
  assert.ok(runtimeLayout.mappings.some(mapping => mapping.source === 'backend/http/v1' && mapping.destination === 'api/v1'));
  assert.ok(runtimeLayout.mappings.some(mapping => mapping.source === 'backend/http/ui' && mapping.destination === 'api/ui'));
  assert.deepEqual(historicalRemovals(['core/autoloader.php', 'libraries/builder.php', 'libraries/release.php'], new Set(['libraries/release.php'])), ['core/autoloader.php', 'libraries/builder.php']);
});
