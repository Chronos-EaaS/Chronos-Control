import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { mkdtemp, open, readFile, rm } from 'node:fs/promises';
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
const directory = path.resolve(process.argv[2] || 'dist/release');
const manifest = JSON.parse(await readFile(path.join(directory, 'chronos-control-manifest.json'), 'utf8'));
if (manifest.commit !== commit || manifest.uiBuildId !== commit) throw new Error('The artifact belongs to a different source commit.');
const assets = ['chronos-control.zip', 'chronos-control-manifest.json', 'chronos-installer.php'];
const localHashes = new Map();
for (const name of assets) localHashes.set(name, createHash('sha256').update(await readFile(path.join(directory, name))).digest('hex'));
if (manifest.archive?.sha256 !== localHashes.get('chronos-control.zip')) throw new Error('The build archive failed its manifest integrity check.');
const tag = `chronos-build-${commit}`;
const endpoint = `repos/${repository}/releases`;
function api(resource, method = 'GET', body, file) {
  const arguments_ = ['api', resource, '--method', method];
  if (body !== undefined) arguments_.push('--input', '-');
  if (file !== undefined) arguments_.push('--input', file, '--header', 'Content-Type: application/octet-stream');
  const response = execFileSync('gh', arguments_, { input: body === undefined ? undefined : JSON.stringify(body), encoding: 'utf8', stdio: ['pipe', 'pipe', 'pipe'] });
  return method === 'DELETE' && response.trim() === '' ? null : JSON.parse(response);
}
function status(error, expected) {
  try { if (Number(JSON.parse(String(error.stdout)).status) === expected) return true; } catch { /* Non-JSON CLI errors still carry an HTTP status. */ }
  return new RegExp(`\\bHTTP ${expected}\\b`).test(String(error.stderr));
}
function optional(resource) { try { return api(resource); } catch (error) { if (!status(error, 404)) throw error; return null; } }
function reference() {
  let object = optional(`repos/${repository}/git/ref/tags/${tag}`)?.object;
  if (!object) return null;
  for (let depth = 0; object.type === 'tag' && depth < 5; depth++) object = api(`repos/${repository}/git/tags/${object.sha}`).object;
  if (object.type !== 'commit' || object.sha !== commit) throw new Error('The immutable build tag points to another source commit.');
  return object;
}
function discover() {
  const published = optional(`${endpoint}/tags/${tag}`);
  if (published !== null) return published;
  // The tag endpoint returns published releases only. Push-authorized listing
  // also discovers drafts, including a draft left by an interrupted earlier run.
  const matches = [];
  for (let page = 1; ; page++) {
    const releases = api(`${endpoint}?per_page=100&page=${page}`);
    if (!Array.isArray(releases) || releases.length > 100) throw new Error('Invalid release inventory.');
    matches.push(...releases.filter(release => release.tag_name === tag));
    if (matches.length > 1) throw new Error('Multiple releases use the immutable build tag. Resolve the ambiguity without replacing assets.');
    if (releases.length < 100) return matches[0] ?? null;
  }
}
const starter = asset => asset.state === 'starter' && asset.size === 0;
function validate(release) {
  if (!Number.isSafeInteger(release?.id) || release.id < 1 || release.tag_name !== tag || typeof release.draft !== 'boolean' || !Array.isArray(release.assets)) throw new Error('The release identity is invalid.');
  if (release.draft && release.target_commitish !== commit) throw new Error('The draft build targets another source commit.');
  const seen = new Set(), ids = new Set();
  for (const asset of release.assets) {
    if (!Number.isSafeInteger(asset.id) || asset.id < 1 || ids.has(asset.id) || !assets.includes(asset.name) || seen.has(asset.name)
        || !(asset.state === 'uploaded' || (release.draft && starter(asset)))) throw new Error('The immutable release has duplicate, unexpected or incomplete assets.');
    seen.add(asset.name); ids.add(asset.id);
  }
  return release;
}
const initialReference = reference();
let release = discover();
if (release === null) {
  try {
    // Creation returns the draft ID; a draft deliberately has no published tag.
    release = api(endpoint, 'POST', { tag_name: tag, target_commitish: commit, name: `Chronos CI build ${commit.slice(0, 12)}`,
      body: `Immutable CI package for source commit ${commit}. Includes the compiled Angular UI, production PHP dependencies and standalone installer.`, draft: true, prerelease: true, make_latest: 'false' });
  } catch (error) {
    if (!status(error, 422) || (release = discover()) === null) throw error;
  }
}
validate(release);
release = validate(api(`${endpoint}/${release.id}`));
if (!release.draft && initialReference === null && !reference()) throw new Error('The published immutable build has no verified source tag.');
const temporary = await mkdtemp(path.join(os.tmpdir(), 'chronos-release-verify-'));
async function verifyBytes(release) {
  for (const asset of release.assets.filter(asset => asset.state === 'uploaded')) {
    const destination = path.join(temporary, asset.name);
    const output = await open(destination, 'w', 0o600);
    try { execFileSync('gh', ['api', `${endpoint}/assets/${asset.id}`, '--header', 'Accept: application/octet-stream'], { stdio: ['ignore', output.fd, 'pipe'] }); }
    finally { await output.close(); }
    if (createHash('sha256').update(await readFile(destination)).digest('hex') !== localHashes.get(asset.name)) throw new Error(`Immutable build asset differs: ${asset.name}. No existing asset was replaced.`);
  }
}
try {
  // Check every existing byte before making the first write to a resumed draft.
  await verifyBytes(release);
  for (const placeholder of release.assets.filter(starter)) {
    // GitHub documents zero-byte starter entries left by an HTTP502 upload.
    // Recheck both the exact draft and asset metadata immediately before cleanup.
    const current = validate(api(`${endpoint}/${release.id}`));
    if (!current.draft) throw new Error('Starter cleanup requires the exact unpublished draft.');
    const candidate = current.assets.find(asset => asset.id === placeholder.id);
    if (!candidate) continue;
    const metadata = api(`${endpoint}/assets/${candidate.id}`);
    if (!starter(candidate) || candidate.name !== placeholder.name || metadata.id !== candidate.id || metadata.name !== candidate.name || !starter(metadata)) throw new Error('The starter asset changed. No existing bytes were removed.');
    api(`${endpoint}/assets/${candidate.id}`, 'DELETE');
  }
  if (release.assets.some(starter)) {
    release = validate(api(`${endpoint}/${release.id}`));
    await verifyBytes(release);
  }
  for (const name of assets) {
    if (!release.assets.some(asset => asset.name === name)) {
      if (!release.draft) throw new Error('A published immutable build is incomplete. It cannot be rewritten.');
      // A duplicate name is a conflict, never permission to replace existing bytes.
      api(`https://uploads.github.com/repos/${repository}/releases/${release.id}/assets?name=${encodeURIComponent(name)}`, 'POST', undefined, path.join(directory, name));
    }
  }
  if (release.draft) {
    release = validate(api(`${endpoint}/${release.id}`));
    if (release.assets.length !== assets.length || release.assets.some(asset => asset.state !== 'uploaded')) throw new Error('The draft upload is incomplete.');
    await verifyBytes(release);
    if (release.draft) release = validate(api(`${endpoint}/${release.id}`, 'PATCH', { draft: false, prerelease: true, make_latest: 'false' }));
    if (release.draft || !reference()) throw new Error('The immutable build was not published with its verified source tag.');
  }
  console.log(`Verified immutable build ${tag}; no installation or stable release was changed.`);
} finally { await rm(temporary, { recursive: true, force: true }); }
