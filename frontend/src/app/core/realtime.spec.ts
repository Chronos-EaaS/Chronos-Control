import { TestBed } from '@angular/core/testing';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { Api } from './api';
import { LIVE_RUNTIME, LiveRuntime, Realtime } from './realtime';
import { SessionData } from './models';

class Socket {
  readyState = 0;
  onopen: ((event: Event) => void) | null = null;
  onmessage: ((event: MessageEvent) => void) | null = null;
  onerror: ((event: Event) => void) | null = null;
  onclose: ((event: CloseEvent) => void) | null = null;
  sent: Record<string, unknown>[] = [];
  send(value: string): void { this.sent.push(JSON.parse(value)); }
  close(): void { this.readyState = 3; this.onclose?.(new CloseEvent('close')); }
  open(): void { this.readyState = 1; this.onopen?.(new Event('open')); }
  receive(value: object): void { this.onmessage?.(new MessageEvent('message', { data: JSON.stringify(value) })); }
  subscriptions(): Record<string, unknown>[] { return this.sent.filter(frame => frame['type'] === 'subscribe'); }
}

describe('server-pushed UI transport', () => {
  let live: Realtime;
  let sockets: Socket[];
  let protocols: string[][];
  let probe = vi.fn(async () => true);
  let post = vi.fn(async () => ({ ticket: 'renewed-ticket' }));
  let expired = vi.fn();
  beforeEach(() => {
    vi.useFakeTimers(); vi.setSystemTime(0); sockets = []; protocols = [];
    probe = vi.fn().mockResolvedValue(true); post = vi.fn().mockResolvedValue({ ticket: 'renewed-ticket' }); expired = vi.fn();
    const runtime: LiveRuntime = {
      now: () => Date.now(), later: (callback, delay) => setTimeout(callback, delay), cancel: timer => clearTimeout(timer), probe: () => probe(),
      socket: offered => { protocols.push(offered); const socket = new Socket(); sockets.push(socket); return socket; },
    };
    TestBed.configureTestingModule({ providers: [
      { provide: Api, useValue: { post, expireSession: expired } }, { provide: LIVE_RUNTIME, useValue: runtime },
    ] });
    live = TestBed.inject(Realtime);
  });
  afterEach(() => { TestBed.resetTestingModule(); vi.useRealTimers(); });

  it('delivers snapshots without HTTP requests while server heartbeats arrive', async () => {
    const apply = vi.fn(); const sub = live.watch('/meta', {}, apply); sockets[0].open();
    const id = sockets[0].subscriptions()[0]['id'];
    sockets[0].receive({ type: 'data', id, revision: 1, data: { uiBuildId: 'one' } });
    expect(await sub.ready).toEqual({ uiBuildId: 'one' });
    for (let index = 0; index < 20; index++) {
      await vi.advanceTimersByTimeAsync(15_000); sockets[0].receive({ type: 'heartbeat', at: new Date().toISOString() });
    }
    expect(probe).not.toHaveBeenCalled(); expect(post).not.toHaveBeenCalled(); expect(sockets).toHaveLength(1);
    expect(apply).toHaveBeenCalledTimes(1);
  });
  it('probes only after a missed heartbeat, reports failed reachability and clears only on heartbeat', async () => {
    probe.mockResolvedValueOnce(false).mockResolvedValue(true);
    live.watch('/meta', {}, () => {}); sockets[0].open(); sockets[0].receive({ type: 'heartbeat' });
    await vi.advanceTimersByTimeAsync(44_999); expect(probe).not.toHaveBeenCalled();
    await vi.advanceTimersByTimeAsync(1); expect(probe).toHaveBeenCalledTimes(1); expect(live.disconnected()).toBe(true);
    await vi.advanceTimersByTimeAsync(5_000); expect(sockets).toHaveLength(2); sockets[1].open();
    expect(live.disconnected()).toBe(true);
    sockets[1].receive({ type: 'heartbeat' }); expect(live.disconnected()).toBe(false);
  });
  it('does not let ordinary data mask missing server heartbeats', async () => {
    live.watch('/meta', {}, () => {}); sockets[0].open();
    await vi.advanceTimersByTimeAsync(30_000);
    sockets[0].receive({ type: 'data', id: sockets[0].subscriptions()[0]['id'], revision: 1, data: {} });
    await vi.advanceTimersByTimeAsync(15_000); expect(probe).toHaveBeenCalledOnce();
  });
  it('waits for the heartbeat deadline after an early socket close', async () => {
    live.watch('/meta', {}, () => {}); sockets[0].open(); sockets[0].close();
    await vi.advanceTimersByTimeAsync(10_000); live.watch('/meta', {}, () => {});
    expect(probe).not.toHaveBeenCalled(); expect(sockets).toHaveLength(1);
    await vi.advanceTimersByTimeAsync(35_000); expect(probe).toHaveBeenCalledOnce(); expect(sockets).toHaveLength(2);
  });
  it('replays subscriptions with new identities and accepts fresh revision numbers after reconnect', async () => {
    const apply = vi.fn(); live.watch('/jobs/3', { includeArchived: false }, apply); sockets[0].open();
    const old = sockets[0].subscriptions()[0]; expect(old['query']).toEqual({ includeArchived: 'false' });
    sockets[0].receive({ type: 'data', id: old['id'], revision: 8, data: 'before' });
    await vi.advanceTimersByTimeAsync(45_000); sockets[1].open();
    const fresh = sockets[1].subscriptions()[0]; expect(fresh['id']).not.toBe(old['id']);
    sockets[0].receive({ type: 'data', id: old['id'], revision: 9, data: 'stale' });
    sockets[1].receive({ type: 'data', id: fresh['id'], revision: 1, data: 'after' });
    expect(apply.mock.calls.map(call => call[0])).toEqual(['before', 'after']);
  });
  it('replaces subscription identities for changed queries and refreshes after writes', async () => {
    const apply = vi.fn(); const sub = live.watch('/jobs', { page: 1 }, apply); sockets[0].open();
    const oldId = sockets[0].subscriptions()[0]['id'];
    const changed = sub.update({ page: 2 }); const newId = sockets[0].subscriptions().at(-1)!['id'];
    sockets[0].receive({ type: 'data', id: oldId, revision: 99, data: 'stale' });
    sockets[0].receive({ type: 'data', id: newId, revision: 1, data: 'current' });
    expect(await changed).toBe('current'); expect(apply).toHaveBeenCalledExactlyOnceWith('current');
  });
  it('unsubscribes closed views and one-shot reads', async () => {
    const apply = vi.fn(); const sub = live.watch('/meta', {}, apply); sockets[0].open();
    const id = sockets[0].subscriptions()[0]['id']; sub.close();
    sockets[0].receive({ type: 'data', id, revision: 1, data: {} }); expect(apply).not.toHaveBeenCalled();
    const read = live.read('/meta'); const readId = sockets[0].subscriptions().at(-1)!['id'];
    sockets[0].receive({ type: 'data', id: readId, revision: 1, data: 'one' }); expect(await read).toBe('one');
    expect(sockets[0].sent.at(-1)).toEqual({ type: 'unsubscribe', id: readId });
  });
  it('offers opaque credentials as subprotocols and renews them only on reconnect', async () => {
    const session = { user: { id: 1 }, impersonation: { active: false, originalUser: null }, live: { ticket: 'initial-ticket' } } as SessionData;
    live.configure(session); live.watch('/session', {}, () => {}); sockets[0].open();
    expect(protocols[0]).toEqual(['chronos-ui-v1', 'chronos-ticket.initial-ticket']); expect(post).not.toHaveBeenCalled();
    await vi.advanceTimersByTimeAsync(45_000);
    expect(post).toHaveBeenCalledExactlyOnceWith('/live/ticket', {}); expect(protocols[1]).toContain('chronos-ticket.renewed-ticket');
  });
  it('ignores frames from the previous identity immediately after logout', () => {
    live.configure({ user: { id: 1 }, impersonation: { active: false, originalUser: null }, live: { ticket: 'ticket' } } as SessionData);
    const apply = vi.fn(); live.watch('/jobs/3', {}, apply); sockets[0].open(); const id = sockets[0].subscriptions()[0]['id'];
    live.configure({ user: null, csrfToken: '', impersonation: { active: false, originalUser: null } });
    sockets[0].receive({ type: 'data', id, revision: 1, data: 'private' }); expect(apply).not.toHaveBeenCalled();
  });
  it('expires authentication and stops accepting old socket frames', () => {
    const apply = vi.fn(); live.watch('/jobs/3', {}, apply); sockets[0].open(); const id = sockets[0].subscriptions()[0]['id'];
    sockets[0].receive({ type: 'session-expired' }); sockets[0].receive({ type: 'data', id, revision: 1, data: 'private' });
    expect(expired).toHaveBeenCalledOnce(); expect(apply).not.toHaveBeenCalled();
  });
  it('does not display a stale failed probe after a heartbeat recovers mid-request', async () => {
    let finish!: (healthy: boolean) => void; probe.mockReturnValue(new Promise<boolean>(resolve => { finish = resolve; }));
    live.watch('/meta', {}, () => {}); sockets[0].open(); await vi.advanceTimersByTimeAsync(45_000);
    sockets[0].receive({ type: 'heartbeat' }); finish(false); await Promise.resolve(); await Promise.resolve();
    expect(live.disconnected()).toBe(false); expect(sockets).toHaveLength(1);
  });
  it('does not probe merely because a healthy tab becomes visible', () => {
    live.watch('/meta', {}, () => {}); sockets[0].open(); sockets[0].receive({ type: 'heartbeat' });
    document.dispatchEvent(new Event('visibilitychange')); expect(probe).not.toHaveBeenCalled();
  });
  it('ignores a pending credential from an identity replaced before its request completes', async () => {
    let finish!: (value: { ticket: string }) => void;
    post.mockImplementationOnce(() => new Promise(resolve => { finish = resolve; }));
    live.configure({ user: { id: 1 }, impersonation: { active: false, originalUser: null } } as SessionData);
    live.watch('/meta', {}, () => {}); expect(sockets).toHaveLength(0);
    live.configure({ user: { id: 2 }, impersonation: { active: false, originalUser: null }, live: { ticket: 'new-identity-ticket' } } as SessionData);
    expect(sockets).toHaveLength(1);
    finish({ ticket: 'old-identity-ticket' }); await Promise.resolve(); await Promise.resolve();
    expect(sockets).toHaveLength(1); expect(protocols[0]).toContain('chronos-ticket.new-identity-ticket');
  });
  it('coalesces visibility checks with an already pending heartbeat health probe', async () => {
    let finish!: (healthy: boolean) => void;
    probe.mockImplementationOnce(() => new Promise(resolve => { finish = resolve; }));
    live.watch('/meta', {}, () => {}); sockets[0].open();
    await vi.advanceTimersByTimeAsync(45_000);
    document.dispatchEvent(new Event('visibilitychange')); document.dispatchEvent(new Event('visibilitychange'));
    expect(probe).toHaveBeenCalledOnce(); finish(false);
    await Promise.resolve(); await Promise.resolve(); expect(live.disconnected()).toBe(true);
  });
  it('does not let an old socket heartbeat or authentication error affect its replacement', async () => {
    probe.mockResolvedValueOnce(false).mockResolvedValue(true);
    live.watch('/meta', {}, () => {}); sockets[0].open();
    await vi.advanceTimersByTimeAsync(50_000); sockets[1].open();
    expect(live.disconnected()).toBe(true);
    sockets[0].receive({ type: 'heartbeat' }); sockets[0].receive({ type: 'session-expired' });
    expect(live.disconnected()).toBe(true); expect(expired).not.toHaveBeenCalled();
    sockets[1].receive({ type: 'heartbeat' }); expect(live.disconnected()).toBe(false);
  });
  it('stops expired identity recovery and cancels private views before rebinding public metadata', async () => {
    live.configure({ user: { id: 1 }, impersonation: { active: false, originalUser: null }, live: { ticket: 'first' } } as SessionData);
    const metadata = vi.fn(), privateData = vi.fn();
    live.watch('/meta', {}, metadata); const privateView = live.watch('/jobs/3', {}, privateData);
    sockets[0].open(); sockets[0].receive({ type: 'session-expired' });
    await expect(privateView.ready).rejects.toMatchObject({ status: 401, code: 'session_expired' });
    await vi.advanceTimersByTimeAsync(120_000);
    expect(probe).not.toHaveBeenCalled(); expect(post).not.toHaveBeenCalled(); expect(sockets).toHaveLength(1);
    live.configure({ user: null, csrfToken: '', impersonation: { active: false, originalUser: null } }); sockets[1].open();
    expect(protocols[1]).toEqual(['chronos-ui-v1']);
    expect(sockets[1].subscriptions().map(frame => frame['path'])).toEqual(['/meta']);
    const id = sockets[1].subscriptions()[0]['id']; sockets[1].receive({ type: 'data', id, revision: 1, data: 'public' });
    expect(metadata).toHaveBeenCalledExactlyOnceWith('public'); expect(privateData).not.toHaveBeenCalled();
  });
  it('retains a silent log cursor checkpoint for reconnect without sending another request', async () => {
    const sub = live.watch('/jobs/3/log', { offset: 0 }, () => {}); sockets[0].open();
    const frames = sockets[0].sent.length;
    sub.checkpoint({ offset: 2048, epoch: 'replacement-file' }); expect(sockets[0].sent).toHaveLength(frames);
    await vi.advanceTimersByTimeAsync(45_000); sockets[1].open();
    expect(sockets[1].subscriptions()[0]['query']).toEqual({ offset: '2048', epoch: 'replacement-file' });
  });
  it('honors a server deployment restart without a speculative health request', async () => {
    const apply = vi.fn(); live.watch('/meta', {}, apply); sockets[0].open();
    const old = sockets[0].subscriptions()[0]['id'];
    sockets[0].receive({ type: 'restart', reason: 'build-changed' });
    await vi.advanceTimersByTimeAsync(2_499); expect(sockets).toHaveLength(1); expect(probe).not.toHaveBeenCalled();
    await vi.advanceTimersByTimeAsync(1); expect(sockets).toHaveLength(2); sockets[1].open();
    const id = sockets[1].subscriptions()[0]['id']; expect(id).not.toBe(old);
    sockets[0].receive({ type: 'data', id: old, revision: 99, data: 'old-build' });
    sockets[1].receive({ type: 'data', id, revision: 1, data: 'new-build' });
    expect(apply).toHaveBeenCalledExactlyOnceWith('new-build'); expect(probe).not.toHaveBeenCalled();
  });

  it('reports an authorization failure exactly once while expiring the private subscription', async () => {
    const failed = vi.fn(); const sub = live.watch('/jobs/3', {}, () => {}, failed); sockets[0].open();
    sockets[0].receive({ type: 'error', id: sockets[0].subscriptions()[0]['id'], status: 401, error: { code: 'authentication_required', message: 'Sign in again.' } });
    await expect(sub.ready).rejects.toMatchObject({ status: 401, code: 'authentication_required' });
    expect(failed).toHaveBeenCalledTimes(1); expect(expired).toHaveBeenCalledOnce();
  });

  it('quiesces queued expiry/data frames and all recovery while an identity change is suspended', async () => {
    const apply = vi.fn(); live.watch('/meta', {}, apply); sockets[0].open();
    const old = sockets[0].subscriptions()[0]['id'];
    live.suspend(); live.watch('/jobs/3', {}, () => {});
    sockets[0].receive({ type: 'session-expired' }); sockets[0].receive({ type: 'heartbeat' });
    sockets[0].receive({ type: 'data', id: old, revision: 99, data: 'old-identity' });
    document.dispatchEvent(new Event('visibilitychange'));
    await vi.advanceTimersByTimeAsync(120_000);
    expect(expired).not.toHaveBeenCalled(); expect(apply).not.toHaveBeenCalled();
    expect(probe).not.toHaveBeenCalled(); expect(post).not.toHaveBeenCalled(); expect(sockets).toHaveLength(1);
    live.configure({ user: null, csrfToken: '', impersonation: { active: false, originalUser: null } }); sockets[1].open();
    expect(sockets[1].subscriptions().map(frame => frame['path'])).toEqual(['/meta', '/jobs/3']);
    const fresh = sockets[1].subscriptions()[0]['id']; expect(fresh).not.toBe(old);
    sockets[1].receive({ type: 'data', id: fresh, revision: 1, data: 'current' });
    expect(apply).toHaveBeenCalledExactlyOnceWith('current');
  });
  it('does not open a delayed ticket connection after suspension and resumes the same identity explicitly', async () => {
    let finish!: (value: { ticket: string }) => void;
    post.mockImplementationOnce(() => new Promise(resolve => { finish = resolve; }));
    const identity = { user: { id: 1 }, impersonation: { active: false, originalUser: null } } as SessionData;
    live.configure(identity); live.suspend();
    finish({ ticket: 'pre-suspension-ticket' }); await Promise.resolve(); await Promise.resolve();
    expect(sockets).toHaveLength(0);
    live.configure({ ...identity, live: { ticket: 'resumed-ticket' } });
    expect(sockets).toHaveLength(1); expect(protocols[0]).toContain('chronos-ticket.resumed-ticket');
  });

});
