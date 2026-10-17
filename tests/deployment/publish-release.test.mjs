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
const fixtureGh = `#!/usr/bin/env node
const fs=require('node:fs'),path=require('node:path');
if(!process.env.CHRONOS_PUBLISH_FIXTURE)process.exit(99);
const file=process.env.CHRONOS_PUBLISH_FIXTURE,state=JSON.parse(fs.readFileSync(file,'utf8')),args=process.argv.slice(2);
const flag=(name)=>args[args.indexOf(name)+1];
state.calls.push(args);
const save=()=>fs.writeFileSync(file,JSON.stringify(state));
const missing=()=>{save();process.stderr.write('HTTP 404: Not Found');process.exit(1);};
if(args[0]==='api'){
 if(args[1].includes('/git/ref/tags/')){if(!state.reference)missing();save();console.log(JSON.stringify({object:state.reference}));}
 else{if(!state.release)missing();save();console.log(JSON.stringify(state.release));}
}else if(args[1]==='create'){
 state.release={draft:true,target_commitish:flag('--target'),assets:[]};save();
}else if(args[1]==='upload'){
 if(args.includes('--clobber'))throw Error('Immutable assets must never be replaced');
 const source=args[3],name=path.basename(source);state.release.assets.push({name});state.bytes[name]=fs.readFileSync(source).toString('base64');save();
}else if(args[1]==='download'){
 const name=flag('--pattern');fs.writeFileSync(path.join(flag('--dir'),name),Buffer.from(state.bytes[name],'base64'));save();
}else if(args[1]==='edit'){
 state.release.draft=false;state.reference={type:'commit',sha:process.env.GITHUB_SHA};save();
}else throw Error('Unexpected fixture gh command');
`;
async function fixture(callback) {
  const root = await mkdtemp(path.join(os.tmpdir(), 'chronos-publish-contract-'));
  try {
    const bin = path.join(root, 'bin'); await mkdir(bin);
    await writeFile(path.join(bin, 'gh'), fixtureGh, { mode: 0o755 });
    const archive = Buffer.from('synthetic immutable archive');
    const manifest = { commit, uiBuildId: commit, archive: { bytes: archive.length, sha256: createHash('sha256').update(archive).digest('hex') } };
    await writeFile(path.join(root, names[0]), archive);
    await writeFile(path.join(root, names[1]), JSON.stringify(manifest));
    await writeFile(path.join(root, names[2]), '<?php /* synthetic installer */');
    const statePath = path.join(root, 'state.json');
    const state = { reference: null, release: null, bytes: {}, calls: [] };
    const save = () => writeFile(statePath, JSON.stringify(state));
    const run = (overrides = {}) => spawnSync(process.execPath, [publisher, root], { encoding: 'utf8', env: { ...process.env, PATH: `${bin}:${process.env.PATH}`, GH_TOKEN: 'synthetic-fixture-token', CHRONOS_PUBLISH_FIXTURE: statePath, GITHUB_ACTIONS: 'true', GITHUB_REPOSITORY: 'Chronos-EaaS/Chronos-Control', GITHUB_SHA: commit, GITHUB_REF: 'refs/heads/fixture', GITHUB_EVENT_NAME: 'push', ...overrides } });
    const load = async () => JSON.parse(await readFile(statePath, 'utf8'));
    const existing = async (draft = false) => {
      state.reference = draft ? null : { type: 'commit', sha: commit };
      state.release = { draft, target_commitish: commit, assets: names.map(name => ({ name })) };
      for (const name of names) state.bytes[name] = (await readFile(path.join(root, name))).toString('base64');
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
  assert.equal(state.calls.filter(args => args[1] === 'upload').length, 3);
  assert.equal(state.calls.at(-1)[1], 'edit');
}));

test('published matching commit bytes are verified and never uploaded again', () => fixture(async ({ existing, save, run, load }) => {
  await existing(); await save(); const result = run(); assert.equal(result.status, 0, result.stderr);
  assert.equal((await load()).calls.filter(args => ['upload', 'edit', 'create'].includes(args[1])).length, 0);
}));

test('partial unpublished draft resumes only its missing assets', () => fixture(async ({ existing, state, save, run, load }) => {
  await existing(true); state.release.assets.splice(1); await save(); const result = run(); assert.equal(result.status, 0, result.stderr);
  assert.equal((await load()).calls.filter(args => args[1] === 'upload').length, 2);
}));

test('existing wrong source tag or different bytes fail without replacement', () => fixture(async ({ existing, state, save, run, load }) => {
  await existing(); state.reference.sha = 'b'.repeat(40); await save(); assert.notEqual(run().status, 0);
  assert.equal((await load()).calls.filter(args => ['upload', 'edit', 'create'].includes(args[1])).length, 0);
  state.reference.sha = commit; state.bytes[names[0]] = Buffer.from('different bytes').toString('base64'); state.calls = []; await save();
  assert.notEqual(run().status, 0); assert.equal((await load()).calls.filter(args => ['upload', 'edit', 'create'].includes(args[1])).length, 0);
}));

test('published incomplete builds stay immutable and are not repaired by uploading', () => fixture(async ({ existing, state, save, run, load }) => {
  await existing(); state.release.assets.splice(1); await save(); assert.notEqual(run().status, 0);
  assert.equal((await load()).calls.filter(args => args[1] === 'upload').length, 0);
}));
