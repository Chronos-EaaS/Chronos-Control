import { createHash, randomUUID } from 'node:crypto';
import { lstat, mkdir, readFile, readdir, rename, rm, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { migrationRuntime, migrationSources } from './migration-runtime.mjs';

export const projectRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const layoutBytes = await readFile(new URL('./runtime-layout.json', import.meta.url));
export const runtimeLayout = JSON.parse(layoutBytes);
export const assemblyMarker = '.chronos-runtime-source.json';
const digest = bytes => createHash('sha256').update(bytes).digest('hex');
const json = value => JSON.stringify(value, null, 2) + '\n';

export function safeRelative(value) {
  if (typeof value !== 'string' || !value || value.startsWith('/') || /[\x00-\x1f\x7f\\:]/.test(value)
      || value.split('/').some(part => !part || part === '.' || part === '..' || part.startsWith('.git') || part === '.hg')) {
    throw new Error(`Unsafe runtime path: ${value}`);
  }
  return value;
}

export const excludedRuntimePath = value => runtimeLayout.excludedRuntimePrefixes.some(prefix => value === prefix || value.startsWith(prefix + '/'));
const ignored = value => path.posix.basename(value) === '.DS_Store' || /\.md$/i.test(value);

export function historicalRemovals(paths, shipped) {
  return [...new Set(paths)].filter(value => {
    safeRelative(value);
    if (!runtimeLayout.historicalRuntimeRoots.some(root => value === root || value.startsWith(root + '/'))) throw new Error(`A removal is not a historical runtime path: ${value}`);
    return !shipped.has(value) && !/^webroot\/(?:systems|data|media)(?:\/|$)/.test(value);
  }).sort();
}

async function noLinks(root, relative) {
  let current = root;
  for (const part of safeRelative(relative).split('/')) {
    current = path.join(current, part);
    try { if ((await lstat(current)).isSymbolicLink()) throw new Error(`Symbolic links are not allowed: ${relative}`); }
    catch (error) { if (error.code !== 'ENOENT') throw error; }
  }
}

export async function generatedOutput(root, output) {
  const target = path.resolve(root, output);
  if (!target.startsWith(path.join(root, 'dist') + path.sep)) throw new Error('Generated output must be a child of the repository dist directory.');
  await noLinks(root, path.relative(root, target).split(path.sep).join('/'));
  return target;
}

/** Read mapped runtime sources without Git, dependencies, mutable data or output directories. */
export async function inspectSources(root = projectRoot) {
  const entries = new Map();
  async function add(source, destination, kind) {
    safeRelative(source); safeRelative(destination);
    if (excludedRuntimePath(destination) || ignored(destination)) return;
    await noLinks(root, source);
    const entry = await lstat(path.join(root, source));
    if (entry.isSymbolicLink()) throw new Error(`Symbolic links are not allowed: ${source}`);
    if (kind === 'directory' && !entry.isDirectory() || kind === 'file' && !entry.isFile()) throw new Error(`Runtime source has an unexpected type: ${source}`);
    if (entry.isDirectory()) {
      for (const child of (await readdir(path.join(root, source))).sort()) await add(`${source}/${child}`, `${destination}/${child}`);
    } else if (entry.isFile()) {
      if (entries.has(destination)) throw new Error(`Duplicate runtime destination: ${destination}`);
      const bytes = await readFile(path.join(root, source));
      entries.set(destination, { source, path: destination, bytes: bytes.length, sha256: digest(bytes) });
    } else throw new Error(`Runtime source is not a regular file: ${source}`);
  }
  for (const mapping of runtimeLayout.mappings) await add(mapping.source, mapping.destination, mapping.kind);
  return [...entries.values()].sort((a, b) => a.path < b.path ? -1 : a.path > b.path ? 1 : 0);
}

async function composerFiles(root) {
  await noLinks(root, 'backend/composer.json'); await noLinks(root, 'backend/composer.lock');
  const manifestBytes = await readFile(path.join(root, 'backend/composer.json'));
  const lock = await readFile(path.join(root, 'backend/composer.lock'));
  const manifest = JSON.parse(manifestBytes);
  if (JSON.stringify(manifest.autoload) !== JSON.stringify({ 'psr-4': { 'Chronos\\': 'src/' } })) throw new Error('The source Composer application mapping is unsupported.');
  const runtime = { ...manifest, autoload: { 'psr-4': { 'Chronos\\': 'app/' } } };
  delete runtime['autoload-dev'];
  const generated = Buffer.from(json(runtime));
  return { manifest: generated, lock, proof: { sourceManifestSha256: digest(manifestBytes), sourceLockSha256: digest(lock), manifestSha256: digest(generated), lockSha256: digest(lock) } };
}

async function databaseBundle(root) {
  const sources = {};
  for (const name of migrationSources) sources[name] = await readFile(path.join(root, 'backend/src/Infrastructure/Persistence/Migrations', name + '.php'), 'utf8');
  return Buffer.from(migrationRuntime(sources));
}

async function disposable(root, target) {
  try {
    if (!(await lstat(target)).isDirectory()) throw new Error('Runtime output is not a directory.');
  } catch (error) { if (error.code === 'ENOENT') return; throw error; }
  if ((await readdir(target)).length === 0) return;
  for (const name of ['config.php', ...runtimeLayout.excludedRuntimePrefixes]) {
    try { await lstat(path.join(target, name)); throw new Error(`Refusing to replace runtime output containing ${name}.`); }
    catch (error) { if (error.code !== 'ENOENT') throw error; }
  }
  try {
    const marker = JSON.parse(await readFile(path.join(target, assemblyMarker), 'utf8'));
    if (marker.format !== 1 || marker.runtimeLayout !== runtimeLayout.runtimeLayout) throw new Error('Unsupported runtime marker.');
  } catch { throw new Error('Refusing to replace an unmanaged runtime output directory.'); }
  await noLinks(root, path.relative(root, target).split(path.sep).join('/'));
}

export async function assembleRuntime({ root = projectRoot, output = 'dist/runtime', buildId = null } = {}) {
  root = path.resolve(root);
  if (buildId !== null && (typeof buildId !== 'string' || !/^[A-Za-z0-9._-]{7,100}$/.test(buildId))) throw new Error('Invalid runtime build identifier.');
  const target = await generatedOutput(root, output);
  await disposable(root, target);
  const files = await inspectSources(root);
  const composer = await composerFiles(root);
  const database = await databaseBundle(root);
  const generated = [{ path: 'database.php', bytes: database.length, sha256: digest(database) }];
  const marker = { format: 1, runtimeLayout: runtimeLayout.runtimeLayout, buildId, layoutSha256: digest(layoutBytes), files, generated, composer: composer.proof };
  const stage = path.join(root, 'dist', `.runtime-stage-${randomUUID()}`);
  const backup = path.join(root, 'dist', `.runtime-previous-${randomUUID()}`);
  let backedUp = false;
  await mkdir(stage, { recursive: true });
  try {
    for (const file of files) {
      const bytes = await readFile(path.join(root, file.source));
      if (digest(bytes) !== file.sha256) throw new Error('A runtime source changed during assembly.');
      const destination = path.join(stage, file.path);
      await mkdir(path.dirname(destination), { recursive: true }); await writeFile(destination, bytes, { mode: 0o644 });
    }
    await writeFile(path.join(stage, 'composer.json'), composer.manifest);
    await writeFile(path.join(stage, 'composer.lock'), composer.lock);
    await writeFile(path.join(stage, 'database.php'), database);
    await writeFile(path.join(stage, assemblyMarker), json(marker));
    await mkdir(path.dirname(target), { recursive: true });
    try { await rename(target, backup); backedUp = true; } catch (error) { if (error.code !== 'ENOENT') throw error; }
    try { await rename(stage, target); } catch (error) { if (backedUp) await rename(backup, target); throw error; }
    if (backedUp) await rm(backup, { recursive: true });
  } finally { await rm(stage, { recursive: true, force: true }); }
  return { directory: target, ...marker };
}

/** Independently compare staging against current source before making a release. */
export async function verifyRuntime({ root = projectRoot, output = 'dist/runtime', buildId, trackedSources } = {}) {
  root = path.resolve(root);
  const target = await generatedOutput(root, output);
  await noLinks(root, path.relative(root, path.join(target, assemblyMarker)).split(path.sep).join('/'));
  const marker = JSON.parse(await readFile(path.join(target, assemblyMarker), 'utf8'));
  const files = await inspectSources(root);
  const composer = await composerFiles(root);
  const database = await databaseBundle(root);
  const generated = [{ path: 'database.php', bytes: database.length, sha256: digest(database) }];
  if (marker.format !== 1 || marker.runtimeLayout !== runtimeLayout.runtimeLayout || marker.layoutSha256 !== digest(layoutBytes)
      || marker.buildId !== null && buildId !== undefined && marker.buildId !== buildId
      || JSON.stringify(marker.files) !== JSON.stringify(files) || JSON.stringify(marker.generated) !== JSON.stringify(generated) || JSON.stringify(marker.composer) !== JSON.stringify(composer.proof)) {
    throw new Error('The assembled runtime is stale. Reassemble it from the current source.');
  }
  for (const file of files) {
    if (trackedSources && !trackedSources.has(file.source)) throw new Error(`An untracked runtime source cannot enter a release: ${file.source}`);
    await noLinks(root, path.relative(root, path.join(target, file.path)).split(path.sep).join('/'));
    if (digest(await readFile(path.join(target, file.path))) !== file.sha256) throw new Error(`An assembled runtime file changed: ${file.path}`);
  }
  for (const [name, expected] of [['composer.json', composer.manifest], ['composer.lock', composer.lock]]) {
    await noLinks(root, path.relative(root, path.join(target, name)).split(path.sep).join('/'));
    if (digest(await readFile(path.join(target, name))) !== digest(expected)) throw new Error('The assembled Composer inputs changed.');
  }
  await noLinks(root, path.relative(root, path.join(target, 'database.php')).split(path.sep).join('/'));
  if (digest(await readFile(path.join(target, 'database.php'))) !== digest(database)) throw new Error('The assembled database migration runtime changed.');
  return { directory: target, files, generated, runtimeLayout: marker.runtimeLayout };
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  const options = { buildId: process.env.CHRONOS_BUILD_ID || process.env.GITHUB_SHA || null };
  for (let index = 2; index < process.argv.length; index += 2) {
    const flag = process.argv[index], value = process.argv[index + 1];
    if (!value || !['--output', '--build-id'].includes(flag)) throw new Error('Usage: assemble-runtime.mjs [--output dist/runtime] [--build-id source-id]');
    options[flag === '--output' ? 'output' : 'buildId'] = value;
  }
  const result = await assembleRuntime(options);
  console.log(`Assembled ${result.files.length} runtime source files in ${result.directory}; layout ${result.runtimeLayout}; build ${result.buildId ?? 'unidentified'}.`);
}
