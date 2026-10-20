import assert from 'node:assert/strict';
import { mkdtemp, mkdir, rm, symlink, writeFile } from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';
import { packageFiles } from './package-files.mjs';

test('Composer distribution archives retain runtime code and licenses while omitting nested VCS and CI metadata', async () => {
  const root = await mkdtemp(path.join(os.tmpdir(), 'chronos-package-files-'));
  try {
    for (const name of ['pkg/src/worker.php', 'pkg/LICENSE', 'pkg/.github/workflows/ci.yml', 'pkg/.git/config',
      'pkg/.gitattributes', 'pkg/.gitignore', 'pkg/.hg/hgrc', 'pkg/src/debug.js.map']) {
      await mkdir(path.dirname(path.join(root, name)), { recursive: true });
      await writeFile(path.join(root, name), name);
    }
    const files = [];
    await packageFiles(root, 'vendor', async (source, target) => files.push(target));
    assert.deepEqual(files.sort(), ['vendor/pkg/LICENSE', 'vendor/pkg/src/worker.php']);
  } finally { await rm(root, { recursive: true, force: true }); }
});

test('a dependency cannot smuggle a symlink outside the verified package tree', async () => {
  const root = await mkdtemp(path.join(os.tmpdir(), 'chronos-package-link-'));
  try {
    await symlink(os.tmpdir(), path.join(root, 'linked-runtime'));
    await assert.rejects(packageFiles(root, 'vendor', async () => {}), /symbolic link/);
  } finally { await rm(root, { recursive: true, force: true }); }
});
