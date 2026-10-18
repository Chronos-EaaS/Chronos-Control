import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { lstat, mkdir, mkdtemp, open, readFile, readdir, realpath, rename, rm, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

export const legacyCommit = '23f2e3b52b302945cc58c73dd46105a3852d472e';
export const legacyMarker = '.chronos-legacy-baseline.json';
export const projectRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const legacyTree = 'fca8778fcbed69b1826dc2cb077f9147ac67dfb2';
const legacyStamp = { format: 1, commit: legacyCommit, tree: legacyTree, files: 747 };
const composeBlob = '4be81b61863626e7b99617e4759ce3aa58e0c810';
const commandOptions = { timeout: 30_000, maxBuffer: 4 * 1024 * 1024 };
const blobHash = bytes => createHash('sha1').update(`blob ${bytes.length}\0`).update(bytes).digest('hex');

function git(root, args, options = {}) {
  const env = { ...process.env };
  for (const key of ['GIT_DIR', 'GIT_WORK_TREE', 'GIT_INDEX_FILE', 'GIT_OBJECT_DIRECTORY', 'GIT_ALTERNATE_OBJECT_DIRECTORIES']) delete env[key];
  return execFileSync('git', ['--no-optional-locks', '-C', root, ...args], { ...commandOptions, env, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'], ...options });
}

async function entry(filename) {
  try { return await lstat(filename); } catch (error) { if (error.code === 'ENOENT') return null; throw error; }
}

function safeName(name) {
  if (!name || name.startsWith('/') || /[\x00-\x1f\x7f\\:]/.test(name)
      || name.split('/').some(part => !part || part === '.' || part === '..' || part === '.git')) {
    throw new Error('The fixed baseline contains an unsafe archive path.');
  }
  return name;
}

function inventory(root) {
  let tree;
  try {
    if (git(root, ['cat-file', '-t', legacyCommit]).trim() !== 'commit') throw new Error('Not a commit.');
    tree = git(root, ['rev-parse', `${legacyCommit}^{tree}`]).trim();
  } catch {
    throw new Error(`The fixed legacy commit ${legacyCommit} is unavailable locally. Fetch that exact commit before preparing the legacy fixture.`);
  }
  const files = new Map(); const directories = new Set();
  for (const line of git(root, ['ls-tree', '-r', '-l', '-z', legacyCommit]).split('\0').filter(Boolean)) {
    const match = /^(100644|100755) blob ([a-f0-9]{40})\s+(\d+)\t([\s\S]+)$/.exec(line);
    if (!match) throw new Error('The fixed baseline must contain only regular files.');
    const [, mode, hash, size, name] = match; safeName(name);
    if (name === legacyMarker || files.has(name)) throw new Error('The fixed baseline contains an ambiguous archive path.');
    files.set(name, { hash, bytes: Number(size), executable: mode === '100755' });
    for (let parent = path.posix.dirname(name); parent !== '.'; parent = path.posix.dirname(parent)) directories.add(parent);
  }
  if (!files.has('compose.test.yml') || !files.has('tests/docker/Dockerfile') || !files.has('controllers/user.php')) {
    throw new Error('The fixed legacy runtime inventory is incomplete.');
  }
  if (tree !== legacyTree || files.size !== legacyStamp.files) throw new Error('The fixed baseline inventory does not match its pinned tree.');
  return { files, directories, stamp: legacyStamp };
}

async function verifyStamp(directory) {
  const status = await entry(directory);
  if (!status?.isDirectory() || status.isSymbolicLink()) throw new Error('The legacy source target must be a real directory.');
  const marker = await entry(path.join(directory, legacyMarker));
  if (!marker?.isFile() || marker.isSymbolicLink() || marker.size > 4096) throw new Error('Refusing an unmanaged legacy source directory.');
  let stamp;
  try { stamp = JSON.parse(await readFile(path.join(directory, legacyMarker), 'utf8')); }
  catch { throw new Error('The legacy source marker is invalid.'); }
  if (JSON.stringify(stamp) !== JSON.stringify(legacyStamp)) throw new Error('The legacy source marker does not match the fixed baseline.');
}

async function verify(directory, expected, stamped = true) {
  const status = await entry(directory);
  if (!status?.isDirectory() || status.isSymbolicLink()) throw new Error('The legacy source target must be a real directory.');
  if (stamped) {
    await verifyStamp(directory);
  }
  const seen = new Set();
  async function visit(relative = '') {
    const results = await Promise.allSettled((await readdir(path.join(directory, relative))).map(async name => {
      const location = relative ? `${relative}/${name}` : name;
      const current = await lstat(path.join(directory, location));
      if (current.isSymbolicLink()) throw new Error(`Refusing a symbolic link in legacy sources: ${location}`);
      if (location === legacyMarker && stamped && current.isFile()) return;
      if (current.isDirectory() && expected.directories.has(location)) { await visit(location); return; }
      const file = expected.files.get(location);
      if (!current.isFile() || !file) throw new Error(`Refusing unexpected legacy source content: ${location}`);
      if (current.size !== file.bytes || Boolean(current.mode & 0o100) !== file.executable) throw new Error(`The legacy source was modified: ${location}`);
      const bytes = await readFile(path.join(directory, location));
      if (blobHash(bytes) !== file.hash) throw new Error(`The legacy source was modified: ${location}`);
      seen.add(location);
    }));
    const failed = results.find(result => result.status === 'rejected');
    if (failed) throw failed.reason;
  }
  await visit();
  if (seen.size !== expected.files.size) throw new Error('A fixed legacy source file is missing.');
}

/** Container control reads only pinned Compose configuration, never source UI. */
export async function existingLegacy({ root = projectRoot } = {}) {
  root = path.resolve(root); const dist = path.join(root, 'dist'); const target = path.join(dist, 'legacy-source');
  const distStatus = await entry(dist);
  if (!distStatus?.isDirectory() || distStatus.isSymbolicLink()) throw new Error('Prepare the fixed legacy fixture with tools/test/test-stack up first.');
  await verifyStamp(target);
  const filename = path.join(target, 'compose.test.yml'); const status = await entry(filename);
  if (!status?.isFile() || status.isSymbolicLink() || status.size !== 1700 || blobHash(await readFile(filename)) !== composeBlob) {
    throw new Error('The legacy Compose configuration does not match the fixed project and loopback target.');
  }
  return target;
}

/** Prepare a verified archive of one immutable v2 commit, without a checkout. */
export async function prepareLegacy({ root = projectRoot } = {}) {
  root = path.resolve(root);
  const dist = path.join(root, 'dist'); const target = path.join(dist, 'legacy-source');
  const distStatus = await entry(dist);
  if (distStatus && (!distStatus.isDirectory() || distStatus.isSymbolicLink())) throw new Error('The generated dist directory must be a real directory.');
  const expected = inventory(root);
  if (await entry(target)) { await verify(target, expected); return target; }
  await mkdir(dist, { recursive: true });
  const stage = await mkdtemp(path.join(dist, '.legacy-source-stage-'));
  const source = path.join(stage, 'source'); await mkdir(source);
  try {
    const archive = path.join(stage, 'source.tar'); const descriptor = await open(archive, 'wx', 0o600);
    try { git(root, ['archive', '--format=tar', legacyCommit], { stdio: ['ignore', descriptor.fd, 'pipe'] }); }
    finally { await descriptor.close(); }
    execFileSync('tar', ['-xf', archive, '-C', source, '--no-same-owner', '--no-same-permissions'], commandOptions);
    await verify(source, expected, false);
    await writeFile(path.join(source, legacyMarker), JSON.stringify(expected.stamp, null, 2) + '\n', { flag: 'wx', mode: 0o644 });
    if (await entry(target)) { await verify(target, expected); return target; }
    try { await rename(source, target); }
    catch (error) {
      // Another preparation may have published this same verified snapshot.
      if (!['EEXIST', 'ENOTEMPTY'].includes(error.code)) throw error;
      await verify(target, expected);
    }
    return target;
  } finally { await rm(stage, { recursive: true, force: true }); }
}

const invokedPath = process.argv[1] ? await realpath(process.argv[1]).catch(() => null) : null;
if (invokedPath === fileURLToPath(import.meta.url)) {
  if (process.argv.length !== 2 && !(process.argv.length === 3 && process.argv[2] === '--existing')) throw new Error('Usage: node tools/test/prepare-legacy.mjs [--existing]');
  console.log(await (process.argv[2] === '--existing' ? existingLegacy() : prepareLegacy()));
}
