import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { mkdtemp, readFile, rm } from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';

// Only the trusted publishing job receives a write token. Fork PR jobs cannot
// enter this boundary, even if they invoke the script directly.
const repository = 'Chronos-EaaS/Chronos-Control';
const commit = process.env.GITHUB_SHA ?? '';
if (process.env.GITHUB_ACTIONS !== 'true' || process.env.GITHUB_REPOSITORY !== repository
    || !['push', 'workflow_dispatch'].includes(process.env.GITHUB_EVENT_NAME)
    || !process.env.GITHUB_REF?.startsWith('refs/heads/') || !/^[a-f0-9]{40}$/.test(commit)) {
  throw new Error('Immutable build publishing is restricted to trusted repository branch workflows.');
}
const directory = path.resolve(process.argv[2] || 'release-dist');
const manifest = JSON.parse(await readFile(path.join(directory, 'chronos-control-manifest.json'), 'utf8'));
if (manifest.commit !== commit || manifest.uiBuildId !== commit) throw new Error('The artifact belongs to a different source commit.');
const assets = ['chronos-control.zip', 'chronos-control-manifest.json', 'chronos-installer.php'];
const localHashes = new Map();
for (const name of assets) localHashes.set(name, createHash('sha256').update(await readFile(path.join(directory, name))).digest('hex'));
if (manifest.archive?.sha256 !== localHashes.get('chronos-control.zip')) throw new Error('The build archive failed its manifest integrity check.');
const tag = `chronos-build-${commit}`;
const gh = (...arguments_) => execFileSync('gh', arguments_, { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] }).trim();
let reference = null;
try { reference = JSON.parse(gh('api', `repos/${repository}/git/ref/tags/${tag}`)).object; }
catch (error) { if (!String(error.stderr).includes('404')) throw error; }
if (reference !== null) {
  for (let depth = 0; reference.type === 'tag' && depth < 5; depth++) reference = JSON.parse(gh('api', `repos/${repository}/git/tags/${reference.sha}`)).object;
  if (reference.type !== 'commit' || reference.sha !== commit) throw new Error('The existing immutable build tag points to another source commit.');
}
let release = null;
try { release = JSON.parse(gh('api', `repos/${repository}/releases/tags/${tag}`)); }
catch (error) { if (!String(error.stderr).includes('404')) throw error; }
if (release === null) {
  gh('release', 'create', tag, '--repo', repository, '--target', commit, '--title', `Chronos CI build ${commit.slice(0, 12)}`,
    '--notes', `Immutable CI package for source commit ${commit}. Includes the compiled Angular UI, production PHP dependencies and standalone installer.`, '--draft', '--prerelease', '--latest=false');
  release = JSON.parse(gh('api', `repos/${repository}/releases/tags/${tag}`));
} else if (!release.draft && reference === null) {
  throw new Error('The published immutable build has no verified source tag.');
}
if (release.draft && release.target_commitish !== commit) throw new Error('The draft build targets another source commit.');
const temporary = await mkdtemp(path.join(os.tmpdir(), 'chronos-release-verify-'));
try {
  for (const name of assets) {
    const existing = release.assets.find(asset => asset.name === name);
    if (existing) {
      gh('release', 'download', tag, '--repo', repository, '--pattern', name, '--dir', temporary);
      const remoteHash = createHash('sha256').update(await readFile(path.join(temporary, name))).digest('hex');
      if (remoteHash !== localHashes.get(name)) throw new Error(`Immutable build asset differs: ${name}. No existing asset was replaced.`);
    } else {
      if (!release.draft) throw new Error('A published immutable build is incomplete. It cannot be rewritten.');
      // No --clobber: a retry or concurrent publisher may never replace bytes.
      gh('release', 'upload', tag, path.join(directory, name), '--repo', repository);
    }
  }
  if (release.draft) gh('release', 'edit', tag, '--repo', repository, '--draft=false', '--prerelease', '--latest=false');
  console.log(`Verified immutable build ${tag}; no installation or stable release was changed.`);
} finally { await rm(temporary, { recursive: true, force: true }); }
