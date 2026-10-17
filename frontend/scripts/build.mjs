import { execFileSync, spawnSync } from 'node:child_process';
import { copyFileSync, mkdirSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const root = fileURLToPath(new URL('../', import.meta.url));
const id = process.env.CHRONOS_BUILD_ID || process.env.GITHUB_SHA || execFileSync('git', ['rev-parse', 'HEAD'], { cwd: root, encoding: 'utf8' }).trim();
if (!/^[a-zA-Z0-9._-]{7,100}$/.test(id)) throw new Error('The UI build identifier is invalid.');
mkdirSync(new URL('../public/', import.meta.url), { recursive: true });
writeFileSync(new URL('../public/build.json', import.meta.url), JSON.stringify({ id, apiVersion: 3 }) + '\n');
const cli = fileURLToPath(new URL('../node_modules/@angular/cli/bin/ng.js', import.meta.url));
const result = spawnSync(process.execPath, [cli, 'build', '--define', `__CHRONOS_BUILD_ID__=${JSON.stringify(id)}`, ...process.argv.slice(2), '--extract-licenses=true'], { cwd: root, stdio: 'inherit' });
if (result.status === 0) {
  copyFileSync(new URL('../dist/chronos-ui/3rdpartylicenses.txt', import.meta.url), new URL('../dist/chronos-ui/browser/3rdpartylicenses.txt', import.meta.url));
}
process.exit(result.status ?? 1);
