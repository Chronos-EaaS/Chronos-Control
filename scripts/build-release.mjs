import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { mkdir, readFile, readdir, rm, lstat, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const git = (...args) => execFileSync('git', args, { cwd: root, encoding: 'utf8' }).trim();
const commit = process.env.GITHUB_SHA || git('rev-parse', 'HEAD');
if (commit !== git('rev-parse', 'HEAD')) throw new Error('The requested build commit does not match the checked-out source.');
if (!/^[a-f0-9]{40}$/.test(commit)) throw new Error('A complete Git commit is required for a release package.');
if (git('status', '--porcelain', '--untracked-files=normal')) throw new Error('Commit the complete source before building a release package.');
const compiled = path.join(root, 'frontend/dist/chronos-ui/browser');
const build = JSON.parse(await readFile(path.join(compiled, 'build.json'), 'utf8'));
if (build.id !== commit) throw new Error('The compiled UI does not match the source commit. Run the production frontend build first.');
const output = path.resolve(root, process.env.CHRONOS_RELEASE_OUTPUT || 'release-dist');
if (output === root || !output.startsWith(root + path.sep)) throw new Error('Release output must be a child of the source directory.');
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
const runtimePaths = ['api', 'controllers', 'core', 'libraries', 'templates', 'views', 'webroot', 'constants.php', 'chronos.sql', 'config.template.php', '.htaccess', 'install.php'];
const baseline = '1d349f20a716eac975dcf0c52969af6a9c79ed63';
const obsoletePublicUi = name => /^webroot\/(?:assets|design|template)(?:\/|$)/.test(name);
const persistentPath = name => /^webroot\/(?:systems|data|media)(?:\/|$)/.test(name);
const tracked = execFileSync('git', ['ls-files', '-z', '--', ...runtimePaths], { cwd: root, encoding: 'utf8' }).split('\0').filter(Boolean);
for (const name of tracked) {
  if (obsoletePublicUi(name) || persistentPath(name) || /^webroot\/ui\//.test(name) || /\.md$/i.test(name)) continue;
  try { const entry = await lstat(path.join(root, name)); if (entry.isSymbolicLink()) throw new Error('Runtime sources must not contain symbolic links.'); if (entry.isFile()) await add(path.join(root, name), name); } catch (error) { if (error.code !== 'ENOENT') throw error; }
}
async function walk(directory, prefix) {
  for (const entry of await readdir(directory, { withFileTypes: true })) {
    if (entry.isSymbolicLink()) throw new Error('A build contains an unexpected symbolic link.');
    if (entry.isDirectory()) await walk(path.join(directory, entry.name), `${prefix}/${entry.name}`);
    else if (entry.isFile() && !entry.name.endsWith('.map')) await add(path.join(directory, entry.name), `${prefix}/${entry.name}`);
  }
}
await walk(compiled, 'webroot/ui');
const vendor = path.join(root, 'vendor');
const installed = JSON.parse(await readFile(path.join(vendor, 'composer/installed.json'), 'utf8'));
const runtime = installed.packages ?? installed;
if (!Array.isArray(runtime) || runtime.length !== 1 || runtime[0].name !== 'psr/log' || installed.dev !== false) throw new Error('Install the locked PHP dependencies with composer install --no-dev before packaging.');
await walk(vendor, 'vendor');
for (const [dependency, destination] of [['admin-lte/LICENSE', 'adminlte.txt'], ['chart.js/LICENSE.md', 'chartjs.txt'], ['@sgratzl/chartjs-chart-boxplot/LICENSE', 'chartjs-boxplot.txt']]) {
  const license = path.join(root, 'frontend/node_modules', dependency);
  await add(license, `webroot/ui/licenses/${destination}`);
}
await add(path.join(root, 'LICENSE'), 'webroot/ui/licenses/chronos.txt');
const baselinePaths = execFileSync('git', ['ls-tree', '-r', '--name-only', '-z', baseline, '--', ...runtimePaths], { cwd: root, encoding: 'utf8' }).split('\0').filter(Boolean);
const deletedPaths = execFileSync('git', ['diff', '--name-only', '-z', '--diff-filter=D', baseline, 'HEAD', '--', ...runtimePaths], { cwd: root, encoding: 'utf8' }).split('\0').filter(Boolean);
const removedFiles = [...new Set([...deletedPaths, ...baselinePaths.filter(obsoletePublicUi), ...tracked.filter(obsoletePublicUi)])].filter(name => !persistentPath(name)).sort();
const manifest = { format: 1, commit, uiBuildId: commit, phpMinimum: '8.3', files: Object.fromEntries(Object.entries(files).sort(([a], [b]) => a < b ? -1 : a > b ? 1 : 0)), removedFiles };
await writeFile(path.join(stage, 'manifest.json'), JSON.stringify(manifest));
const archive = path.join(output, 'chronos-control.zip');
execFileSync('python3', ['-c', `import pathlib,sys,zipfile\nroot=pathlib.Path(sys.argv[1])\nwith zipfile.ZipFile(sys.argv[2], 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:\n for file in sorted(root.rglob('*')):\n  if file.is_file():\n   info=zipfile.ZipInfo(file.relative_to(root).as_posix(), date_time=(2026,1,1,0,0,0))\n   info.external_attr=0o100644 << 16\n   info.compress_type=zipfile.ZIP_DEFLATED\n   archive.writestr(info,file.read_bytes())\n`, stage, archive], { stdio: 'inherit' });
const bytes = await readFile(archive);
manifest.archive = { bytes: bytes.length, sha256: createHash('sha256').update(bytes).digest('hex') };
await writeFile(path.join(output, 'chronos-control-manifest.json'), JSON.stringify(manifest, null, 2) + '\n');
const installer = await readFile(path.join(root, 'install.php'), 'utf8');
const releaseLibrary = (await readFile(path.join(root, 'libraries/release.php'), 'utf8')).replace(/^<\?php\s*declare\(strict_types=1\);\s*/, '');
const installLibrary = (await readFile(path.join(root, 'libraries/install.php'), 'utf8')).replace(/^<\?php\s*declare\(strict_types=1\);\s*/, '');
await writeFile(path.join(output, 'chronos-installer.php'), installer.replace("require_once __DIR__ . '/libraries/release.php';", releaseLibrary).replace("require_once __DIR__ . '/libraries/install.php';", installLibrary));
await rm(stage, { recursive: true, force: true });
console.log(`Built ${Object.keys(files).length} verified files for ${commit}; archive ${bytes.length} bytes.`);
