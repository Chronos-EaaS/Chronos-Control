import { TestBed } from '@angular/core/testing';
import { effect, signal } from '@angular/core';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { Api, ApiError } from './api';
import { SessionData, User } from './models';
import { LIVE_RUNTIME, LiveRuntime, Realtime } from './realtime';
import { Session } from './session';

class IdentitySocket {
  readyState = 0;
  onopen: ((event: Event) => void) | null = null;
  onmessage: ((event: MessageEvent) => void) | null = null;
  onerror: ((event: Event) => void) | null = null;
  onclose: ((event: CloseEvent) => void) | null = null;
  sent: any[] = [];
  send(data: string): void { this.sent.push(JSON.parse(data)); }
  close(): void { this.readyState = 3; this.onclose?.(new CloseEvent('close')); }
  open(): void { this.readyState = 1; this.onopen?.(new Event('open')); }
  receive(frame: object): void { this.onmessage?.(new MessageEvent('message', { data: JSON.stringify(frame) })); }
  subscription(path: string): string { return this.sent.find(frame => frame.type === 'subscribe' && frame.path === path).id; }
}
const identity = (id = 1, ticket = 'initial-ticket'): SessionData => ({
  user: { id, username: id === 1 ? 'owner' : 'member', role: 0 } as User,
  csrfToken: `csrf-${id}`, impersonation: { active: false, originalUser: null }, live: { ticket },
});

describe('session identity transitions and live lease rotation', () => {
  let session: Session, realtime: Realtime;
  let sockets: IdentitySocket[], protocols: string[][];
  let probe = vi.fn(async () => true);
  const api = { get: vi.fn(), post: vi.fn(), delete: vi.fn(), csrfToken: signal(''), sessionExpired: signal(0), expireSession: vi.fn() };
  beforeEach(() => {
    vi.useFakeTimers(); vi.resetAllMocks(); sockets = []; protocols = [];
    api.csrfToken.set(''); api.sessionExpired.set(0);
    api.get.mockResolvedValue(identity()); api.post.mockResolvedValue({ ticket: 'renewed-ticket' });
    probe = vi.fn(async () => true);
    const runtime: LiveRuntime = {
      now: () => Date.now(), later: (callback, delay) => setTimeout(callback, delay), cancel: timer => clearTimeout(timer), probe,
      socket: offered => { protocols.push(offered); const socket = new IdentitySocket(); sockets.push(socket); return socket; },
    };
    TestBed.configureTestingModule({ providers: [{ provide: Api, useValue: api }, { provide: LIVE_RUNTIME, useValue: runtime }] });
    session = TestBed.inject(Session); realtime = TestBed.inject(Realtime);
  });
  afterEach(() => { TestBed.resetTestingModule(); vi.useRealTimers(); });

  it.each(['password', 'impersonation'])('closes the old %s lease before HTTP mutation and preserves live views under the new identity', async kind => {
    await session.refresh(); sockets[0].open();
    const apply = vi.fn(); realtime.watch('/jobs/3', {}, apply); const old = sockets[0].subscription('/jobs/3');
    const next = identity(kind === 'impersonation' ? 2 : 1, 'new-ticket'); api.get.mockResolvedValue(next);
    const action = vi.fn(async () => {
      expect(sockets[0].readyState).toBe(3);
      sockets[0].receive({ type: 'session-expired' }); sockets[0].receive({ type: 'data', id: old, revision: 99, data: 'obsolete' });
      return 'saved';
    });
    expect(await session.withIdentityChange(action)).toBe('saved');
    expect(action).toHaveBeenCalledOnce(); expect(api.expireSession).not.toHaveBeenCalled(); expect(apply).not.toHaveBeenCalled();
    expect(session.user()?.id).toBe(next.user!.id); sockets[1].open();
    sockets[1].receive({ type: 'data', id: sockets[1].subscription('/jobs/3'), revision: 1, data: 'new-view' });
    sockets[1].receive({ type: 'data', id: sockets[1].subscription('/session'), revision: 1, data: { ...next, live: undefined } });
    await vi.advanceTimersByTimeAsync(15_001); sockets[1].receive({ type: 'heartbeat' });
    expect(apply).toHaveBeenCalledExactlyOnceWith('new-view'); expect(session.user()?.id).toBe(next.user!.id);
    expect(api.expireSession).not.toHaveBeenCalled(); expect(protocols[1]).toContain('chronos-ticket.new-ticket');
  });
  it('does not invalidate identity-dependent work for semantically identical pushed user snapshots', async () => {
    await session.refresh(); sockets[0].open();
    const initial = session.user(); const dependent = vi.fn();
    TestBed.runInInjectionContext(() => effect(() => dependent(session.user()))); TestBed.tick();
    expect(dependent).toHaveBeenCalledTimes(1);
    const reordered = Object.fromEntries(Object.entries(initial!).reverse()) as unknown as User;
    sockets[0].receive({ type: 'data', id: sockets[0].subscription('/session'), revision: 1, data: { ...identity(), user: reordered, live: undefined } });
    TestBed.tick(); expect(session.user()).toBe(initial); expect(dependent).toHaveBeenCalledTimes(1);
    sockets[0].receive({ type: 'data', id: sockets[0].subscription('/session'), revision: 2, data: { ...identity(), user: { ...reordered, firstName: 'Updated display name' }, live: undefined } });
    TestBed.tick(); expect(session.user()?.firstName).toBe('Updated display name'); expect(dependent).toHaveBeenCalledTimes(2);
  });
  it('preserves a rejected password error and resumes the authoritative unchanged identity', async () => {
    await session.refresh(); sockets[0].open(); api.get.mockResolvedValue(identity(1, 'revalidated-ticket'));
    const original = new ApiError('The current password is incorrect.', 422, 'validation_failed');
    await expect(session.withIdentityChange(async () => { throw original; })).rejects.toBe(original);
    expect(session.user()?.id).toBe(1); expect(protocols[1]).toContain('chronos-ticket.revalidated-ticket');
    expect(api.expireSession).not.toHaveBeenCalled();
  });
  it('retains the action error after failed recovery and never reuses a consumed cached ticket', async () => {
    await session.refresh(); sockets[0].open(); api.get.mockRejectedValue(new ApiError('Offline', 0, 'network'));
    const original = new ApiError('Password update failed.', 0, 'network');
    await expect(session.withIdentityChange(async () => { throw original; })).rejects.toBe(original);
    await Promise.resolve(); await Promise.resolve();
    expect(session.user()?.id).toBe(1); expect(api.post).toHaveBeenCalledWith('/live/ticket', {});
    expect(protocols.at(-1)).toContain('chronos-ticket.renewed-ticket');
    expect(protocols.at(-1)).not.toContain('chronos-ticket.initial-ticket');
  });
  it('suspends before login/logout mutations and resumes the previous valid identity after a rejected logout', async () => {
    await session.refresh(); sockets[0].open();
    api.post.mockImplementation(async (path: string) => {
      if (path === '/session') { expect(sockets.at(-1)!.readyState).toBe(3); return identity(2, 'login-ticket'); }
      return { ticket: 'renewed-ticket' };
    });
    await session.login('member', 'password', false); expect(session.user()?.id).toBe(2);
    const failed = new Error('Sign-out failed'); api.get.mockResolvedValue(identity(2, 'after-failure'));
    api.delete.mockImplementation(async () => { expect(sockets.at(-1)!.readyState).toBe(3); throw failed; });
    await expect(session.logout()).rejects.toBe(failed); expect(session.user()?.id).toBe(2);
    expect(protocols.at(-1)).toContain('chronos-ticket.after-failure'); expect(api.expireSession).not.toHaveBeenCalled();
  });

  it('does not restore an old identity when an earlier lookup completes after sign-out', async () => {
    await session.refresh(); sockets[0].open();
    let finish!: (value: SessionData) => void;
    api.get.mockReturnValueOnce(new Promise<SessionData>(resolve => { finish = resolve; }));
    const delayed = session.refresh();
    api.delete.mockResolvedValue({ user: null, csrfToken: 'signed-out', impersonation: { active: false, originalUser: null } });
    await session.logout();
    finish(identity(1, 'obsolete-ticket')); await delayed;
    expect(session.user()).toBeNull();
    expect(api.csrfToken()).toBe('signed-out');
    expect(protocols.at(-1)).toEqual(['chronos-ui-v1']);
  });

  it('does not restore an expired identity from an in-flight lookup', async () => {
    await session.refresh(); sockets[0].open(); TestBed.tick();
    let finish!: (value: SessionData) => void;
    api.get.mockReturnValueOnce(new Promise<SessionData>(resolve => { finish = resolve; }));
    const delayed = session.refresh();
    api.sessionExpired.set(1); TestBed.tick();
    finish(identity(1, 'obsolete-ticket')); await delayed;
    expect(session.user()).toBeNull();
    expect(protocols.at(-1)).toEqual(['chronos-ui-v1']);
  });

  it('invalidates lookups started while sign-out was in progress when the mutation completes', async () => {
    await session.refresh(); sockets[0].open();
    let signedOut!: (value: SessionData) => void, finish!: (value: SessionData) => void;
    api.delete.mockReturnValueOnce(new Promise<SessionData>(resolve => { signedOut = resolve; }));
    const logout = session.logout();
    api.get.mockReturnValueOnce(new Promise<SessionData>(resolve => { finish = resolve; }));
    const delayed = session.refresh();
    signedOut({ user: null, csrfToken: 'signed-out', impersonation: { active: false, originalUser: null } });
    await logout;
    finish(identity(1, 'obsolete-ticket')); await delayed;
    expect(session.user()).toBeNull(); expect(api.csrfToken()).toBe('signed-out');
    expect(protocols.at(-1)).toEqual(['chronos-ui-v1']);
  });

  it('keeps the newest CSRF token, impersonation and live identity when refreshes finish out of order', async () => {
    let finish!: (value: SessionData) => void;
    api.get.mockReturnValueOnce(new Promise<SessionData>(resolve => { finish = resolve; }));
    const delayed = session.refresh();
    const next = { ...identity(2, 'current-ticket'), impersonation: { active: true, originalUser: { id: 3, role: 1 } as User } };
    api.get.mockResolvedValue(next); await session.refresh();
    finish(identity(1, 'obsolete-ticket')); await delayed;
    expect(session.user()?.id).toBe(2); expect(api.csrfToken()).toBe('csrf-2');
    expect(session.impersonation()).toEqual(next.impersonation);
    expect(protocols).toEqual([['chronos-ui-v1', 'chronos-ticket.current-ticket']]);
  });

  it('keeps recovery after a failed identity change authoritative over an older lookup', async () => {
    await session.refresh(); sockets[0].open();
    let finish!: (value: SessionData) => void;
    api.get.mockReturnValueOnce(new Promise<SessionData>(resolve => { finish = resolve; })).mockResolvedValue(identity(2, 'recovery-ticket'));
    const delayed = session.refresh();
    const failure = new Error('Identity change failed');
    await expect(session.withIdentityChange(async () => { throw failure; })).rejects.toBe(failure);
    finish(identity(1, 'obsolete-ticket')); await delayed;
    expect(session.user()?.id).toBe(2); expect(api.csrfToken()).toBe('csrf-2');
    expect(protocols.at(-1)).toContain('chronos-ticket.recovery-ticket');
  });

  it('retries a failed initial lookup and shares each attempt between startup consumers', async () => {
    api.get.mockRejectedValueOnce(new Error('Identity unavailable')).mockResolvedValue(identity());
    const failed = session.load(); expect(session.load()).toBe(failed);
    await expect(failed).rejects.toThrow('Identity unavailable');
    const retry = session.load(); expect(session.load()).toBe(retry);
    await retry;
    expect(api.get).toHaveBeenCalledTimes(2); expect(session.user()?.id).toBe(1);
  });

  it('does not clear a newer ready identity cache when an obsolete initial lookup fails', async () => {
    let fail!: (error: Error) => void;
    api.get.mockReturnValueOnce(new Promise<SessionData>((_resolve, reject) => { fail = reject; })).mockResolvedValue(identity(2, 'newer-ticket'));
    const initial = session.load();
    await session.refresh();
    let guardReady = false;
    void session.load().then(() => { guardReady = true; }); await Promise.resolve();
    expect(guardReady).toBe(true);
    fail(new Error('Obsolete startup lookup failed'));
    await expect(initial).rejects.toThrow('Obsolete startup lookup failed');
    await session.load();
    expect(api.get).toHaveBeenCalledTimes(2);
    expect(session.user()?.id).toBe(2); expect(api.csrfToken()).toBe('csrf-2');
  });

  it('does not clear a newer pending startup request when an expired initial lookup fails', async () => {
    let fail!: (error: Error) => void, finish!: (value: SessionData) => void;
    api.get.mockReturnValueOnce(new Promise<SessionData>((_resolve, reject) => { fail = reject; }))
      .mockReturnValueOnce(new Promise<SessionData>(resolve => { finish = resolve; }));
    const initial = session.load();
    api.sessionExpired.set(1); TestBed.tick();
    const current = session.load();
    fail(new Error('Obsolete startup lookup failed'));
    await expect(initial).rejects.toThrow('Obsolete startup lookup failed');
    expect(session.load()).toBe(current);
    expect(api.get).toHaveBeenCalledTimes(2);
    finish(identity(2, 'current-ticket')); await current;
    expect(session.user()?.id).toBe(2);
  });

  it('restores an expired lease through one HTTP identity lookup while preserving private views', async () => {
    await session.refresh(); sockets[0].open();
    const original = session.user(); const apply = vi.fn();
    const view = realtime.watch('/jobs/3', {}, apply); const old = sockets[0].subscription('/jobs/3');
    api.get.mockResolvedValue({ ...identity(1, 'remembered-ticket'), csrfToken: 'restored-csrf' });
    sockets[0].receive({ type: 'session-expired' });
    sockets[0].receive({ type: 'data', id: old, revision: 99, data: 'obsolete' });
    await vi.advanceTimersByTimeAsync(0);
    expect(api.get).toHaveBeenCalledTimes(2); expect(api.post).not.toHaveBeenCalled();
    expect(api.expireSession).not.toHaveBeenCalled(); expect(session.user()).toBe(original);
    expect(api.csrfToken()).toBe('restored-csrf'); expect(protocols[1]).toContain('chronos-ticket.remembered-ticket');
    sockets[1].open();
    sockets[1].receive({ type: 'data', id: sockets[1].subscription('/jobs/3'), revision: 1, data: 'restored view' });
    expect(await view.ready).toBe('restored view'); expect(apply).toHaveBeenCalledExactlyOnceWith('restored view');
  });

  it('coalesces repeated lease expiry frames and still signs out an anonymous restored session', async () => {
    await session.refresh(); sockets[0].open();
    let finish!: (value: SessionData) => void;
    api.get.mockReturnValueOnce(new Promise<SessionData>(resolve => { finish = resolve; }));
    const view = realtime.watch('/jobs/3', {}, () => {}); const id = sockets[0].subscription('/jobs/3');
    sockets[0].receive({ type: 'session-expired' }); sockets[0].receive({ type: 'session-expired' });
    sockets[0].receive({ type: 'error', id, status: 401 });
    expect(api.get).toHaveBeenCalledTimes(2); expect(api.expireSession).not.toHaveBeenCalled();
    finish({ user: null, csrfToken: 'anonymous', impersonation: { active: false, originalUser: null } });
    await vi.advanceTimersByTimeAsync(0);
    expect(api.expireSession).toHaveBeenCalledOnce();
    await expect(view.ready).rejects.toMatchObject({ status: 401 });
    expect(sockets).toHaveLength(1);
  });

  it.each([
    identity(2, 'another-account'),
    { ...identity(1, 'lost-impersonation'), impersonation: { active: false, originalUser: null } },
    { ...identity(1, 'changed-admin'), impersonation: { active: true, originalUser: { id: 3 } as User } },
    { ...identity(1, 'malformed-admin'), impersonation: { active: true, originalUser: null } },
  ])('never restores a changed or incomplete impersonation identity over private views: %j', async next => {
    api.get.mockResolvedValue({ ...identity(), impersonation: { active: true, originalUser: { id: 2 } as User } });
    await session.refresh(); sockets[0].open();
    api.get.mockResolvedValue(next); const apply = vi.fn();
    const view = realtime.watch('/jobs/3', {}, apply);
    sockets[0].receive({ type: 'session-expired' }); await vi.advanceTimersByTimeAsync(0);
    expect(api.expireSession).toHaveBeenCalledOnce(); expect(sockets).toHaveLength(1);
    expect(apply).not.toHaveBeenCalled(); await expect(view.ready).rejects.toMatchObject({ status: 401 });
  });

  it('retains drafts through a transient restore failure and retries only after a missed heartbeat and probe', async () => {
    await session.refresh(); sockets[0].open();
    const view = realtime.watch('/jobs/3', {}, () => {});
    api.get.mockRejectedValueOnce(new ApiError('Temporarily unavailable', 503, 'maintenance'))
      .mockResolvedValueOnce(identity(1, 'after-outage'));
    sockets[0].receive({ type: 'session-expired' }); await vi.advanceTimersByTimeAsync(0);
    expect(session.user()?.id).toBe(1); expect(api.expireSession).not.toHaveBeenCalled();
    await vi.advanceTimersByTimeAsync(44_999);
    expect(api.get).toHaveBeenCalledTimes(2); expect(probe).not.toHaveBeenCalled();
    await vi.advanceTimersByTimeAsync(1);
    expect(probe).toHaveBeenCalledOnce(); expect(api.get).toHaveBeenCalledTimes(3);
    expect(protocols[1]).toContain('chronos-ticket.after-outage'); expect(api.expireSession).not.toHaveBeenCalled();
    sockets[1].open(); sockets[1].receive({ type: 'data', id: sockets[1].subscription('/jobs/3'), revision: 1, data: 'restored' });
    expect(await view.ready).toBe('restored');
  });

  it.each([401, 403])('restores remembered credentials when ticket renewal returns HTTP %s', async status => {
    await session.refresh(); sockets[0].open();
    api.post.mockRejectedValueOnce(new ApiError('Renew credentials', status, status === 403 ? 'csrf_invalid' : 'authentication_required'));
    api.get.mockResolvedValueOnce({ ...identity(1, 'http-restored'), csrfToken: 'new-csrf' });
    await vi.advanceTimersByTimeAsync(45_000);
    expect(api.post).toHaveBeenCalledExactlyOnceWith('/live/ticket', {});
    expect(api.get).toHaveBeenCalledTimes(2); expect(api.csrfToken()).toBe('new-csrf');
    expect(protocols[1]).toContain('chronos-ticket.http-restored'); expect(api.expireSession).not.toHaveBeenCalled();
  });

  it('keeps recovered credentials authoritative over an older in-flight identity lookup', async () => {
    await session.refresh(); sockets[0].open();
    let finish!: (value: SessionData) => void;
    api.get.mockReturnValueOnce(new Promise<SessionData>(resolve => { finish = resolve; }))
      .mockResolvedValueOnce(identity(1, 'restored'));
    const oldLookup = session.refresh(); sockets[0].receive({ type: 'session-expired' });
    await vi.advanceTimersByTimeAsync(0);
    finish(identity(2, 'obsolete')); await oldLookup;
    expect(session.user()?.id).toBe(1); expect(protocols.at(-1)).toContain('chronos-ticket.restored');
    expect(api.csrfToken()).toBe('csrf-1');
  });

  it('waits for restoring HTTP cookies before logout and never revives the signed-out identity', async () => {
    await session.refresh(); sockets[0].open();
    let finish!: (value: SessionData) => void;
    api.get.mockReturnValueOnce(new Promise<SessionData>(resolve => { finish = resolve; }));
    sockets[0].receive({ type: 'session-expired' });
    api.delete.mockResolvedValue({ user: null, csrfToken: 'signed-out', impersonation: { active: false, originalUser: null } });
    const logout = session.logout(); await Promise.resolve();
    expect(api.delete).not.toHaveBeenCalled();
    finish(identity(1, 'restored-before-logout')); await logout;
    expect(api.delete).toHaveBeenCalledExactlyOnceWith('/session'); expect(session.user()).toBeNull();
    expect(api.csrfToken()).toBe('signed-out'); expect(protocols.at(-1)).toEqual(['chronos-ui-v1']);
    expect(api.expireSession).not.toHaveBeenCalled();
  });

  it('does not apply a restoration response after destruction', async () => {
    await session.refresh(); sockets[0].open();
    let finish!: (value: SessionData) => void;
    api.get.mockReturnValueOnce(new Promise<SessionData>(resolve => { finish = resolve; }));
    sockets[0].receive({ type: 'session-expired' });
    TestBed.resetTestingModule(); finish({ ...identity(1, 'obsolete'), csrfToken: 'obsolete-csrf' });
    await vi.advanceTimersByTimeAsync(0);
    expect(api.csrfToken()).toBe('csrf-1'); expect(sockets).toHaveLength(1); expect(api.expireSession).not.toHaveBeenCalled();
  });

  it('cannot revive an expired identity from fallback when an in-flight identity write and recovery fail', async () => {
    api.get.mockResolvedValue({ ...identity(), impersonation: { active: true, originalUser: { id: 2 } as User } });
    await session.refresh(); sockets[0].open();
    let fail!: (error: Error) => void;
    const write = session.withIdentityChange(() => new Promise<void>((_resolve, reject) => { fail = reject; }));
    const rejected = expect(write).rejects.toThrow('Write failed');
    api.sessionExpired.set(1); TestBed.tick();
    api.get.mockRejectedValue(new ApiError('Offline', 0, 'network'));
    fail(new Error('Write failed')); await rejected;
    expect(session.user()).toBeNull(); expect(session.impersonation()).toEqual({ active: false, originalUser: null });
    expect(api.csrfToken()).toBe(''); expect(protocols.at(-1)).toEqual(['chronos-ui-v1']);
  });
});
