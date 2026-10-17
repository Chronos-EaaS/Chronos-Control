import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { spawnSync } from 'node:child_process';
import { mkdtemp, mkdir, readFile, rm, writeFile } from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';

const publisher = fileURLToPath(new URL('../../scripts/publish-release.mjs', import.meta.url));
const commit = 'a'.repeat(40);
const names = ['chronos-control.zip', 'chronos-control-manifest.json', 'chronos-installer.php'];
const tag = `chronos-build-${commit}`;
const fixtureGh = `#!/usr/bin/env node
const fs=require('node:fs'),path=require('node:path');
if(!process.env.CHRONOS_PUBLISH_FIXTURE)process.exit(99);
const file=process.env.CHRONOS_PUBLISH_FIXTURE,state=JSON.parse(fs.readFileSync(file,'utf8')),args=process.argv.slice(2);
const flag=(name)=>args[args.indexOf(name)+1];
state.calls.push(args);
const save=()=>fs.writeFileSync(file,JSON.stringify(state));
const missing=(status=404)=>{save();process.stderr.write('HTTP '+status+': fixture response');process.exit(1);};
const output=value=>{save();process.stdout.write(JSON.stringify(value));};
if(args[0]==='api'){
 const url=new URL(args[1],'https://api.github.com/'),route=url.pathname,method=args.includes('--method')?flag('--method'):'GET';
 const body=()=>JSON.parse(fs.readFileSync(flag('--input')==='-'?0:flag('--input'),'utf8'));
 if(route.includes('/git/ref/tags/')){if(!state.reference)missing();output({object:state.reference});}
 else if(route.includes('/releases/tags/')){
  // Match the real published-only endpoint, including the CI failure after create.
  if(!state.release||state.release.draft)missing();output(state.release);
 }else if(route.endsWith('/releases')&&method==='GET'){
  if(state.listStatus)missing(state.listStatus);
  const releases=[...(state.before||[]),...(state.release?[state.release]:[]),...(state.extra||[])],page=Number(url.searchParams.get('page')||1);
  output(releases.slice((page-1)*100,page*100));
 }else if(route.endsWith('/releases')&&method==='POST'){
  const input=body();state.createdBody=input;
  state.release={id:41,tag_name:input.tag_name,target_commitish:input.target_commitish,draft:true,prerelease:true,assets:[]};
  if(state.createRace){state.createRace=false;missing(422);}output(state.release);
 }else if(/\\/releases\\/assets\\/\\d+$/.test(route)){
  const asset=state.release?.assets.find(item=>item.id===Number(route.split('/').at(-1)));if(!asset)missing();
  if(method==='DELETE'){
   if(!state.release.draft||asset.state!=='starter'||asset.size!==0)throw Error('Attempted deletion of real uploaded bytes');
   (state.deletedAssets||=[]).push({...asset});state.release.assets=state.release.assets.filter(item=>item.id!==asset.id);delete state.bytes[asset.name];save();
  }else if(args.includes('Accept: application/octet-stream')){save();process.stdout.write(Buffer.from(state.bytes[asset.name],'base64'));}
  else output(state.changedStarterMetadata&&asset.state==='starter'?{...asset,state:'uploaded',size:1}:asset);
 }else if(/\\/releases\\/\\d+\\/assets$/.test(route)&&method==='POST'){
  const name=url.searchParams.get('name');if(!state.release?.draft||state.release.assets.some(asset=>asset.name===name))missing(422);
  const bytes=fs.readFileSync(flag('--input')),asset={id:state.nextAsset++,name,state:'uploaded',size:bytes.length};
  if(state.failUploadName===name){state.failUploadName=null;asset.state='starter';asset.size=0;state.release.assets.push(asset);state.bytes[name]='';missing(502);}
  state.release.assets.push(asset);state.bytes[name]=bytes.toString('base64');output(asset);
 }else if(/\\/releases\\/\\d+$/.test(route)){
  if(!state.release||state.release.id!==Number(route.split('/').at(-1)))missing();
  if(method==='GET'&&state.publishDuringRead&&++state.releaseReads===2){state.release.draft=false;state.reference={type:'commit',sha:process.env.GITHUB_SHA};}
  if(method==='GET'&&state.staleStarterInventory&&state.deletedAssets?.length){output({...state.release,assets:[...state.release.assets,...state.deletedAssets]});process.exit(0);}
  if(method==='PATCH'){if(state.failPublish)missing(403);Object.assign(state.release,body());state.reference={type:'commit',sha:state.wrongPublishedCommit||process.env.GITHUB_SHA};}output(state.release);
 }else throw Error('Unexpected fixture API request: '+args.join(' '));
}else throw Error('Unexpected fixture gh command');
`;
const mutations = state => state.calls.filter(args => ['POST', 'PATCH', 'DELETE'].includes(args[args.indexOf('--method') + 1]));
const uploads = state => mutations(state).filter(args => /\/releases\/\d+\/assets\?/.test(args[1]));
async function fixture(callback) {
  const root = await mkdtemp(path.join(os.tmpdir(), 'chronos-publish-contract-'));
  try {
    const bin = path.join(root, 'bin'); await mkdir(bin);
    await writeFile(path.join(bin, 'gh'), fixtureGh, { mode: 0o755 });
    const archive = Buffer.from('synthetic immutable archive\0\xff', 'latin1');
    const manifest = { commit, uiBuildId: commit, archive: { bytes: archive.length, sha256: createHash('sha256').update(archive).digest('hex') } };
    await writeFile(path.join(root, names[0]), archive);
    await writeFile(path.join(root, names[1]), JSON.stringify(manifest));
    await writeFile(path.join(root, names[2]), '<?php /* synthetic installer */');
    const statePath = path.join(root, 'state.json');
    const state = { reference: null, release: null, bytes: {}, calls: [], nextAsset: 1 };
    const save = () => writeFile(statePath, JSON.stringify(state));
    const run = (overrides = {}) => spawnSync(process.execPath, [publisher, root], { encoding: 'utf8', env: { ...process.env, PATH: `${bin}:${process.env.PATH}`, GH_TOKEN: 'synthetic-fixture-token', CHRONOS_PUBLISH_FIXTURE: statePath, GITHUB_ACTIONS: 'true', GITHUB_REPOSITORY: 'Chronos-EaaS/Chronos-Control', GITHUB_SHA: commit, GITHUB_REF: 'refs/heads/fixture', GITHUB_EVENT_NAME: 'push', ...overrides } });
    const load = async () => JSON.parse(await readFile(statePath, 'utf8'));
    const existing = async (draft = false) => {
      state.reference = draft ? null : { type: 'commit', sha: commit };
      state.release = { id: 41, tag_name: tag, draft, prerelease: true, target_commitish: commit, assets: [] };
      for (const name of names) { const bytes = await readFile(path.join(root, name)); state.bytes[name] = bytes.toString('base64'); state.release.assets.push({ id: state.nextAsset++, name, state: 'uploaded', size: bytes.length }); }
    };
    await callback({ root, state, save, run, load, existing });
  } finally { await rm(root, { recursive: true, force: true }); }
}

test('fork PR and non-branch contexts cannot invoke a publishing command', () => fixture(async ({ state, save, run, load }) => {
  for (const overrides of [{ GITHUB_EVENT_NAME: 'pull_request' }, { GITHUB_REPOSITORY: 'fork/Chronos-Control' }, { GITHUB_REF: 'refs/tags/stable' }]) {
    await save(); assert.notEqual(run(overrides).status, 0); assert.deepEqual((await load()).calls, state.calls);
  }
}));

test('new commit build uploads all fixed assets before publishing a draft', () => fixture(async ({ save, run, load }) => {
  await save(); const result = run(); assert.equal(result.status, 0, result.stderr);
  const state = await load(); assert.equal(state.release.draft, false);
  assert.deepEqual(state.release.assets.map(asset => asset.name), names);
  assert.equal(uploads(state).length, 3);
  assert.equal(mutations(state).filter(args => args[1].endsWith('/releases')).length, 1);
  assert.equal(mutations(state).filter(args => args.includes('PATCH')).length, 1);
  assert.deepEqual([state.createdBody.draft, state.createdBody.prerelease, state.createdBody.make_latest], [true, true, 'false']);
  const creation = state.calls.findIndex(args => args.includes('POST') && args[1].endsWith('/releases'));
  assert.ok(state.calls.slice(creation + 1).every(args => !args[1].includes('/releases/tags/')));
}));

test('published matching commit bytes are verified and never uploaded again', () => fixture(async ({ existing, save, run, load }) => {
  await existing(); await save(); const result = run(); assert.equal(result.status, 0, result.stderr);
  assert.deepEqual(mutations(await load()), []);
}));

test('partial unpublished draft resumes only its missing assets', () => fixture(async ({ existing, state, save, run, load }) => {
  await existing(true); state.release.assets.splice(1); await save(); const result = run(); assert.equal(result.status, 0, result.stderr);
  const after = await load(); assert.equal(uploads(after).length, 2); assert.equal(after.release.id, 41);
  assert.equal(mutations(after).filter(args => args[1].endsWith('/releases')).length, 0);
  assert.ok(after.calls.some(args => args[1].includes('/releases?per_page=100&page=1')));
}));

test('existing wrong source tag or different bytes fail without replacement', () => fixture(async ({ existing, state, save, run, load }) => {
  await existing(); state.reference.sha = 'b'.repeat(40); await save(); assert.notEqual(run().status, 0);
  assert.deepEqual(mutations(await load()), []);
  state.reference.sha = commit; state.bytes[names[0]] = Buffer.from('different bytes').toString('base64'); state.calls = []; await save();
  assert.notEqual(run().status, 0); assert.deepEqual(mutations(await load()), []);
}));

test('published incomplete builds stay immutable and are not repaired by uploading', () => fixture(async ({ existing, state, save, run, load }) => {
  await existing(); state.release.assets.splice(1); await save(); assert.notEqual(run().status, 0);
  assert.deepEqual(mutations(await load()), []);
}));

test('ambiguous same-tag drafts fail without mutating either release', () => fixture(async ({ existing, state, save, run, load }) => {
  await existing(true); state.extra = [{ ...state.release, id: 42 }]; await save(); assert.notEqual(run().status, 0); assert.deepEqual(mutations(await load()), []);
}));

test('an unpublished draft targeting another commit is never adopted', () => fixture(async ({ existing, state, save, run, load }) => {
  await existing(true); state.release.target_commitish = 'b'.repeat(40); await save(); assert.notEqual(run().status, 0); assert.deepEqual(mutations(await load()), []);
}));

test('draft discovery follows pagination without creating a duplicate', () => fixture(async ({ existing, state, save, run, load }) => {
  await existing(true); state.before = Array.from({ length: 100 }, (_, index) => ({ id: 1000 + index, tag_name: `unrelated-${index}`, draft: false }));
  await save(); const result = run(); assert.equal(result.status, 0, result.stderr); const after = await load();
  assert.ok(after.calls.some(args => args[1].endsWith('page=2'))); assert.equal(mutations(after).filter(args => args[1].endsWith('/releases')).length, 0);
}));

test('a simultaneous create conflict adopts the exact draft without a second creation attempt', () => fixture(async ({ state, save, run, load }) => {
  state.createRace = true; await save(); const result = run(); assert.equal(result.status, 0, result.stderr);
  assert.equal(mutations(await load()).filter(args => args[1].endsWith('/releases')).length, 1);
}));

test('failed publication preserves uploaded assets and resumes the same draft on a later run', () => fixture(async ({ state, save, run, load }) => {
  state.failPublish = true; await save(); assert.notEqual(run().status, 0); const failed = await load();
  assert.equal(failed.release.draft, true); assert.equal(failed.release.assets.length, 3);
  Object.assign(state, failed, { failPublish: false, calls: [] }); await save(); const result = run(); assert.equal(result.status, 0, result.stderr);
  const after = await load(); assert.equal(after.release.id, failed.release.id); assert.equal(uploads(after).length, 0);
}));

test('duplicate or unexpected asset names fail closed before any write', () => fixture(async ({ existing, state, save, run, load }) => {
  await existing(true); state.release.assets.push({ ...state.release.assets[0], id: 100 }); await save(); assert.notEqual(run().status, 0); assert.deepEqual(mutations(await load()), []);
  state.release.assets.pop(); state.release.assets.push({ id: 100, name: 'unknown.zip', state: 'uploaded', size: 1 }); state.calls = []; await save();
  assert.notEqual(run().status, 0); assert.deepEqual(mutations(await load()), []);
}));

test('the final published tag must resolve to the exact approved source commit', () => fixture(async ({ state, save, run }) => {
  state.wrongPublishedCommit = 'b'.repeat(40); await save(); assert.notEqual(run().status, 0);
}));

test('another publisher completing the same draft does not cause a second publication mutation', () => fixture(async ({ existing, state, save, run, load }) => {
  await existing(true); state.publishDuringRead = true; state.releaseReads = 0; await save(); const result = run(); assert.equal(result.status, 0, result.stderr);
  const after = await load(); assert.equal(after.release.draft, false); assert.deepEqual(mutations(after), []);
}));

test('a forbidden draft inventory is never mistaken for an absent release', () => fixture(async ({ state, save, run, load }) => {
  state.listStatus = 403; await save(); assert.notEqual(run().status, 0); assert.deepEqual(mutations(await load()), []);
}));

test('a failed upload leaving a zero-byte starter resumes later without replacing uploaded bytes', () => fixture(async ({ state, save, run, load }) => {
  state.failUploadName = names[1]; await save(); assert.notEqual(run().status, 0); const failed = await load();
  assert.deepEqual(failed.release.assets.map(asset => [asset.name, asset.state, asset.size]), [[names[0], 'uploaded', Buffer.from(failed.bytes[names[0]], 'base64').length], [names[1], 'starter', 0]]);
  const uploadedBytes = failed.bytes[names[0]]; const starter = failed.release.assets[1];
  Object.assign(state, failed, { calls: [] }); await save(); const result = run(); assert.equal(result.status, 0, result.stderr);
  const after = await load(); assert.equal(after.release.draft, false); assert.equal(after.bytes[names[0]], uploadedBytes); assert.equal(uploads(after).length, 2);
  assert.deepEqual(mutations(after).filter(args => args.includes('DELETE')).map(args => args[1]), [`repos/Chronos-EaaS/Chronos-Control/releases/assets/${starter.id}`]);
  assert.deepEqual(after.deletedAssets, [{ ...starter }]);
}));

test('starter cleanup rejects published, nonzero, unknown and duplicate asset metadata', () => fixture(async ({ existing, state, save, run, load }) => {
  for (const variant of ['published', 'nonzero', 'unknown', 'duplicate']) {
    await existing(variant !== 'published'); state.calls = [];
    state.release.assets[1] = { ...state.release.assets[1], state: 'starter', size: variant === 'nonzero' ? 1 : 0 };
    if (variant === 'unknown') state.release.assets[1].name = 'unverified.zip';
    if (variant === 'duplicate') state.release.assets.push({ ...state.release.assets[1], id: 999 });
    await save(); assert.notEqual(run().status, 0); assert.deepEqual(mutations(await load()), [], variant);
  }
}));

test('uploaded-byte mismatch blocks starter deletion and a changed starter is never removed', () => fixture(async ({ existing, state, save, run, load }) => {
  await existing(true); state.release.assets[1] = { ...state.release.assets[1], state: 'starter', size: 0 };
  const correct = state.bytes[names[0]]; state.bytes[names[0]] = Buffer.from('different uploaded data').toString('base64');
  await save(); assert.notEqual(run().status, 0); assert.deepEqual(mutations(await load()), []);
  state.bytes[names[0]] = correct; state.changedStarterMetadata = true; state.calls = [];
  await save(); assert.notEqual(run().status, 0); assert.deepEqual(mutations(await load()), []);
}));

test('a stale starter inventory never permits publishing an incomplete draft', () => fixture(async ({ existing, state, save, run, load }) => {
  await existing(true); state.release.assets[1] = { ...state.release.assets[1], state: 'starter', size: 0 }; state.staleStarterInventory = true;
  await save(); assert.notEqual(run().status, 0); const after = await load(); assert.equal(after.release.draft, true);
  assert.equal(mutations(after).filter(args => args.includes('DELETE')).length, 1); assert.equal(mutations(after).filter(args => args.includes('PATCH')).length, 0);
}));
