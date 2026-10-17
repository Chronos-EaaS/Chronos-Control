import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { mkdir, readFile, readdir, rm, lstat, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { generatedOutput, historicalRemovals, runtimeLayout, verifyRuntime } from '../build/assemble-runtime.mjs';

const root = path.resolve(import.meta.dirname, '../..');
const git = (...args) => execFileSync('git', args, { cwd: root, encoding: 'utf8' }).trim();
const commit = process.env.GITHUB_SHA || git('rev-parse', 'HEAD');
if (commit !== git('rev-parse', 'HEAD')) throw new Error('The requested build commit does not match the checked-out source.');
if (!/^[a-f0-9]{40}$/.test(commit)) throw new Error('A complete Git commit is required for a release package.');
if (git('status', '--porcelain', '--untracked-files=normal')) throw new Error('Commit the complete source before building a release package.');
const compiled = path.join(root, 'frontend/dist/chronos-ui/browser');
for (let directory = compiled; directory !== root; directory = path.dirname(directory)) {
  if ((await lstat(directory)).isSymbolicLink()) throw new Error('Compiled UI paths must not pass through symbolic links.');
}
const build = JSON.parse(await readFile(path.join(compiled, 'build.json'), 'utf8'));
if (build.id !== commit) throw new Error('The compiled UI does not match the source commit. Run the production frontend build first.');
const trackedSources = new Set(execFileSync('git', ['ls-files', '-z', '--', 'backend'], { cwd: root, encoding: 'utf8' }).split('\0').filter(Boolean));
const assembled = await verifyRuntime({ root, output: process.env.CHRONOS_RUNTIME_OUTPUT || 'dist/runtime', buildId: commit, trackedSources });
const output = await generatedOutput(root, process.env.CHRONOS_RELEASE_OUTPUT || 'dist/release');
if (output === assembled.directory || output.startsWith(assembled.directory + path.sep) || assembled.directory.startsWith(output + path.sep)) throw new Error('Release output and runtime staging must not overlap.');
for (let directory = output; directory !== root; directory = path.dirname(directory)) {
  try { if ((await lstat(directory)).isSymbolicLink()) throw new Error('Release output must not pass through a symbolic link.'); } catch (error) { if (error.code !== 'ENOENT') throw error; }
}
await mkdir(output, { recursive: true });
const stage = path.join(output, 'stage');
try { if ((await lstat(stage)).isSymbolicLink()) throw new Error('Release staging must not be a symbolic link.'); } catch (error) { if (error.code !== 'ENOENT') throw error; }
await rm(stage, { recursive: true, force: true }); await mkdir(stage);
const files = {};
async function add(source, destination) {
  if (destination.startsWith('/') || destination.split('/').some(part => !part || part === '.' || part === '..' || part.startsWith('.git') || part === '.hg') || /[\x00-\x1f\x7f\\:]/.test(destination)) throw new Error(`Unsafe package path: ${destination}`);
  const bytes = await readFile(source); const target = path.join(stage, destination);
  await mkdir(path.dirname(target), { recursive: true }); await writeFile(target, bytes, { mode: 0o644 });
  files[destination] = { bytes: bytes.length, sha256: createHash('sha256').update(bytes).digest('hex') };
}
const runtimePaths = runtimeLayout.historicalRuntimeRoots;
const baseline = '1d349f20a716eac975dcf0c52969af6a9c79ed63';
for (const file of assembled.files) {
  const source = path.join(assembled.directory, file.path);
  if (createHash('sha256').update(await readFile(source)).digest('hex') !== file.sha256) throw new Error('An assembled source changed while packaging.');
  await add(source, file.path);
}
async function walk(directory, prefix) {
  for (const entry of await readdir(directory, { withFileTypes: true })) {
    if (entry.isSymbolicLink()) throw new Error('A build contains an unexpected symbolic link.');
    if (entry.isDirectory()) await walk(path.join(directory, entry.name), `${prefix}/${entry.name}`);
    else if (entry.isFile() && !entry.name.endsWith('.map')) await add(path.join(directory, entry.name), `${prefix}/${entry.name}`);
  }
}
await walk(compiled, 'webroot/ui');
const vendor = path.join(assembled.directory, 'vendor');
if ((await lstat(vendor)).isSymbolicLink()) throw new Error('Runtime dependencies must not be a symbolic link.');
const installed = JSON.parse(await readFile(path.join(vendor, 'composer/installed.json'), 'utf8'));
const runtime = installed.packages ?? installed;
const locked = JSON.parse(await readFile(path.join(assembled.directory, 'composer.lock'), 'utf8'));
if (!Array.isArray(runtime) || runtime.length !== 1 || runtime[0].name !== 'psr/log' || installed.dev !== false
    || JSON.stringify(runtime.map(item => [item.name, item.version])) !== JSON.stringify(locked.packages.map(item => [item.name, item.version]))) {
  throw new Error('Install the locked production dependencies in dist/runtime with composer install --no-dev --classmap-authoritative before packaging.');
}
await walk(vendor, 'vendor');
for (const [dependency, destination] of [['admin-lte/LICENSE', 'adminlte.txt'], ['bootstrap/LICENSE', 'bootstrap.txt'], ['chart.js/LICENSE.md', 'chartjs.txt'], ['@sgratzl/chartjs-chart-boxplot/LICENSE', 'chartjs-boxplot.txt']]) {
  const license = path.join(root, 'frontend/node_modules', dependency);
  await add(license, `webroot/ui/licenses/${destination}`);
}
await add(path.join(root, 'LICENSE'), 'webroot/ui/licenses/chronos.txt');
const baselinePaths = execFileSync('git', ['ls-tree', '-r', '--name-only', '-z', baseline, '--', ...runtimePaths], { cwd: root, encoding: 'utf8' }).split('\0').filter(Boolean);
// Source moves never become runtime removals. The original runtime inventory
// identifies obsolete v2 files, and each installed manifest handles later updates.
const removedFiles = historicalRemovals(baselinePaths, new Set(Object.keys(files)));
const manifest = { format: 1, runtimeLayout: runtimeLayout.runtimeLayout, commit, uiBuildId: commit, phpMinimum: '8.3', files: Object.fromEntries(Object.entries(files).sort(([a], [b]) => a < b ? -1 : a > b ? 1 : 0)), removedFiles };
await writeFile(path.join(stage, 'manifest.json'), JSON.stringify(manifest));
const archive = path.join(output, 'chronos-control.zip');
execFileSync('python3', ['-c', `import pathlib,sys,zipfile\nroot=pathlib.Path(sys.argv[1])\nwith zipfile.ZipFile(sys.argv[2], 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:\n for file in sorted(root.rglob('*')):\n  if file.is_file():\n   info=zipfile.ZipInfo(file.relative_to(root).as_posix(), date_time=(2026,1,1,0,0,0))\n   info.external_attr=0o100644 << 16\n   info.compress_type=zipfile.ZIP_DEFLATED\n   archive.writestr(info,file.read_bytes())\n`, stage, archive], { stdio: 'inherit' });
const bytes = await readFile(archive);
manifest.archive = { bytes: bytes.length, sha256: createHash('sha256').update(bytes).digest('hex') };
await writeFile(path.join(output, 'chronos-control-manifest.json'), JSON.stringify(manifest, null, 2) + '\n');
const installer = await readFile(path.join(assembled.directory, 'install.php'), 'utf8');
const releaseLibrary = (await readFile(path.join(assembled.directory, 'libraries/release.php'), 'utf8')).replace(/^<\?php\s*declare\(strict_types=1\);\s*/, '');
const installLibrary = (await readFile(path.join(assembled.directory, 'libraries/install.php'), 'utf8')).replace(/^<\?php\s*declare\(strict_types=1\);\s*/, '');
await writeFile(path.join(output, 'chronos-installer.php'), installer.replace("require_once __DIR__ . '/libraries/release.php';", releaseLibrary).replace("require_once __DIR__ . '/libraries/install.php';", installLibrary));
await rm(stage, { recursive: true, force: true });
if (git('rev-parse', 'HEAD') !== commit || git('status', '--porcelain', '--untracked-files=normal')) throw new Error('The source changed during packaging. Discard this local package and rebuild.');
console.log(`Built ${Object.keys(files).length} verified files for ${commit}; archive ${bytes.length} bytes.`);
