import { execFileSync } from 'node:child_process';
import { after, before, beforeEach, describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { chmod, copyFile, lstat, mkdir, mkdtemp, readFile, readdir, realpath, rm, symlink, utimes, writeFile } from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { legacyCommit, legacyMarker, existingLegacy, prepareLegacy, projectRoot } from './prepare-legacy.mjs';

const execute = (command, args, cwd) => execFileSync(command, args, { cwd, encoding: 'utf8', timeout: 30_000, stdio: ['ignore', 'pipe', 'pipe'] });

describe('fixed legacy source preparation', { concurrency: false, timeout: 120_000 }, () => {
  let temporary; let root; let target;
  before(async () => {
    temporary = await realpath(await mkdtemp(path.join(os.tmpdir(), 'chronos-legacy-source-contract-')));
    root = path.join(temporary, 'repository'); await mkdir(root);
    execute('git', ['init', '--quiet'], root);
    // Copy only immutable local Git objects into a disposable test repository.
    // This does not check out files, access the network or mutate source Git.
    execute('git', ['fetch', '--quiet', '--no-tags', '--depth=1', projectRoot, legacyCommit], root);
    target = path.join(root, 'dist/legacy-source');
  });
  beforeEach(async () => { await rm(path.join(root, 'dist'), { recursive: true, force: true }); });
  after(async () => { if (temporary) await rm(temporary, { recursive: true, force: true }); });

  it('archives the exact pinned v2 UI and original container configuration', async () => {
    assert.equal(await prepareLegacy({ root }), target);
    const stamp = JSON.parse(await readFile(path.join(target, legacyMarker), 'utf8'));
    assert.equal(stamp.commit, legacyCommit); assert.equal(stamp.format, 1);
    const compose = await readFile(path.join(target, 'compose.test.yml'), 'utf8');
    assert.match(compose, /^name: chronos-v3-regression\n/);
    assert.match(compose, /127\.0\.0\.1:8088:8080/);
    assert.match(compose, /dockerfile: tests\/docker\/Dockerfile/);
    assert.match(await readFile(path.join(target, 'controllers/user.php'), 'utf8'), /class User_Controller/);
    assert.match(await readFile(path.join(target, 'tests/docker/Dockerfile'), 'utf8'), /COPY views \/opt\/chronos\/views/);
    assert.equal(await lstat(path.join(target, '.git')).catch(() => null), null);
    assert.equal((await lstat(path.join(target, 'scripts/test-stack'))).mode & 0o100, 0o100);
    assert.deepEqual((await readdir(path.join(root, 'dist'))).sort(), ['legacy-source']);
  });

  it('reuses an identical prepared snapshot without rewriting its files', async () => {
    await prepareLegacy({ root });
    const compose = path.join(target, 'compose.test.yml'); const time = new Date('2026-01-15T12:00:00Z');
    await utimes(compose, time, time); const before = (await lstat(compose)).mtimeMs;
    assert.equal(await prepareLegacy({ root }), target);
    assert.equal((await lstat(compose)).mtimeMs, before);
  });

  it('refuses modified tracked sources without overwriting them', async () => {
    await prepareLegacy({ root }); const compose = path.join(target, 'compose.test.yml');
    await writeFile(compose, 'unexpected fixture target');
    await assert.rejects(prepareLegacy({ root }), /legacy source was modified/);
    assert.equal(await readFile(compose, 'utf8'), 'unexpected fixture target');
  });

  it('refuses generated configuration and persistent data in an existing snapshot', async () => {
    await prepareLegacy({ root }); const config = path.join(target, 'config.php');
    await writeFile(config, 'private configuration remains untouched');
    await assert.rejects(prepareLegacy({ root }), /unexpected legacy source content: config.php/);
    assert.equal(await readFile(config, 'utf8'), 'private configuration remains untouched');
    await rm(config); const data = path.join(target, 'webroot/data/do-not-remove.txt');
    await writeFile(data, 'synthetic persistent data');
    await assert.rejects(prepareLegacy({ root }), /unexpected legacy source content: webroot\/data\/do-not-remove.txt/);
    assert.equal(await readFile(data, 'utf8'), 'synthetic persistent data');
  });

  it('refuses an unmanaged existing directory without deleting its contents', async () => {
    await mkdir(target, { recursive: true }); const sentinel = path.join(target, 'sentinel');
    await writeFile(sentinel, 'preserve');
    await assert.rejects(prepareLegacy({ root }), /unmanaged legacy source/);
    assert.equal(await readFile(sentinel, 'utf8'), 'preserve');
  });

  it('rejects the wrong source stamp instead of silently changing the baseline', async () => {
    await prepareLegacy({ root }); const marker = path.join(target, legacyMarker);
    const stamp = JSON.parse(await readFile(marker, 'utf8')); stamp.commit = '0'.repeat(40);
    await writeFile(marker, JSON.stringify(stamp));
    await assert.rejects(prepareLegacy({ root }), /marker does not match the fixed baseline/);
    assert.equal(JSON.parse(await readFile(marker, 'utf8')).commit, '0'.repeat(40));
  });

  it('refuses symlinked output directories and linked files', async () => {
    const outside = path.join(temporary, 'outside'); await mkdir(outside, { recursive: true });
    const sentinel = path.join(outside, 'sentinel'); await writeFile(sentinel, 'outside');
    await symlink(outside, path.join(root, 'dist'));
    await assert.rejects(prepareLegacy({ root }), /dist directory must be a real directory/);
    assert.equal(await readFile(sentinel, 'utf8'), 'outside');
    await rm(path.join(root, 'dist')); await prepareLegacy({ root });
    const compose = path.join(target, 'compose.test.yml'); await rm(compose); await symlink(sentinel, compose);
    await assert.rejects(prepareLegacy({ root }), /symbolic link in legacy sources/);
    assert.equal(await readFile(sentinel, 'utf8'), 'outside');
  });

  it('rejects a repository missing the fixed baseline without preparing another revision', async () => {
    const empty = path.join(temporary, 'empty'); await mkdir(empty); execute('git', ['init', '--quiet'], empty);
    await assert.rejects(prepareLegacy({ root: empty }), new RegExp(`fixed legacy commit ${legacyCommit} is unavailable locally`));
    assert.equal(await lstat(path.join(empty, 'dist')).catch(() => null), null);
  });

  it('resolves the fixed output independently of the caller working directory', async () => {
    const tool = path.join(root, 'tools/test/prepare-legacy.mjs'); await mkdir(path.dirname(tool), { recursive: true });
    await copyFile(fileURLToPath(new URL('./prepare-legacy.mjs', import.meta.url)), tool);
    assert.equal(execute(process.execPath, [tool], os.tmpdir()).trim(), target);
    assert.equal(JSON.parse(await readFile(path.join(target, legacyMarker), 'utf8')).commit, legacyCommit);
    const alias = path.join(temporary, 'alias-helper.mjs'); await symlink(tool, alias);
    assert.equal(execute(process.execPath, [alias, '--existing'], os.tmpdir()).trim(), target);
  });

  it('can be imported from stdin without interpreting the stdin sentinel as a CLI filename', () => {
    const output = execFileSync(process.execPath, ['--input-type=module', '-'], {
      cwd: os.tmpdir(), encoding: 'utf8', timeout: 10_000, stdio: ['pipe', 'pipe', 'pipe'],
      input: `await import(${JSON.stringify(new URL('./prepare-legacy.mjs', import.meta.url).href)}); process.stdout.write('imported');`,
    });
    assert.equal(output, 'imported');
  });

  it('requires an existing fixed snapshot for reset, logs and down', async () => {
    await assert.rejects(existingLegacy({ root }), /Prepare the fixed legacy fixture/);
    assert.equal(await lstat(path.join(root, 'dist')).catch(() => null), null);
  });

  it('validates only pinned container control inputs during an ordinary reset', async () => {
    await prepareLegacy({ root });
    // Runtime images already contain the validated UI. Control commands do not
    // need to rehash all UI assets, but a future up must reject source changes.
    await writeFile(path.join(target, 'controllers/user.php'), 'modified source');
    assert.equal(await existingLegacy({ root }), target);
    await assert.rejects(prepareLegacy({ root }), /legacy source was modified/);
    await writeFile(path.join(target, 'compose.test.yml'), 'different project or port');
    await assert.rejects(existingLegacy({ root }), /Compose configuration does not match the fixed project and loopback target/);
  });

  it('rejects an altered pin before issuing any container control command', async () => {
    await prepareLegacy({ root }); const marker = path.join(target, legacyMarker);
    const stamp = JSON.parse(await readFile(marker, 'utf8')); stamp.commit = '0'.repeat(40);
    await writeFile(marker, JSON.stringify(stamp));
    await assert.rejects(existingLegacy({ root }), /marker does not match the fixed baseline/);
  });

  it('keeps up, reset, logs and down on the original fixed Compose file from any cwd', async () => {
    const tools = path.join(root, 'tools/test'); await mkdir(tools, { recursive: true });
    for (const filename of ['prepare-legacy.mjs', 'test-stack']) {
      await copyFile(fileURLToPath(new URL(filename, import.meta.url)), path.join(tools, filename));
    }
    const bin = path.join(temporary, 'bin'); await mkdir(bin);
    const docker = path.join(bin, 'docker'); const log = path.join(temporary, 'docker-arguments.jsonl');
    await writeFile(docker, '#!/usr/bin/env node\nrequire("node:fs").appendFileSync(process.env.CHRONOS_LEGACY_ARGUMENTS, JSON.stringify(process.argv.slice(2))+"\\n");\n');
    await chmod(docker, 0o755);
    const run = action => execFileSync('sh', [path.join(tools, 'test-stack'), action], {
      cwd: os.tmpdir(), encoding: 'utf8', timeout: 30_000,
      stdio: ['ignore', 'pipe', 'pipe'],
      env: { ...process.env, PATH: `${bin}${path.delimiter}${process.env.PATH}`, CHRONOS_LEGACY_ARGUMENTS: log },
    });
    for (const action of ['up', 'reset', 'logs', 'down']) run(action);
    const calls = (await readFile(log, 'utf8')).trim().split('\n').map(line => JSON.parse(line));
    assert.equal(calls.length, 4);
    const prefix = ['compose', '--project-name', 'chronos-v3-regression', '--file', path.join(target, 'compose.test.yml')];
    for (const call of calls) assert.deepEqual(call.slice(0, prefix.length), prefix);
    assert.deepEqual(calls[0].slice(prefix.length), ['up', '--build', '--detach', '--wait', '--wait-timeout', '180']);
    assert.deepEqual(calls[1].slice(prefix.length), ['exec', '-T', 'web', 'php', '/opt/chronos/tests/fixtures/seed.php', '--reset']);
    assert.deepEqual(calls[2].slice(prefix.length), ['logs', '--no-color']);
    assert.deepEqual(calls[3].slice(prefix.length), ['down', '--volumes', '--remove-orphans']);
    await writeFile(path.join(target, 'compose.test.yml'), 'different project');
    assert.throws(() => run('reset'), /Compose configuration does not match/);
    assert.equal((await readFile(log, 'utf8')).trim().split('\n').length, 4);
  });
});
