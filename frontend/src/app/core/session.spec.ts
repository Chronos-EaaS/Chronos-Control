import { TestBed } from '@angular/core/testing';
import { signal } from '@angular/core';
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
  const api = { get: vi.fn(), post: vi.fn(), delete: vi.fn(), csrfToken: signal(''), sessionExpired: signal(0), expireSession: vi.fn() };
  beforeEach(() => {
    vi.useFakeTimers(); vi.resetAllMocks(); sockets = []; protocols = [];
    api.csrfToken.set(''); api.sessionExpired.set(0);
    api.get.mockResolvedValue(identity()); api.post.mockResolvedValue({ ticket: 'renewed-ticket' });
    const runtime: LiveRuntime = {
      now: () => Date.now(), later: (callback, delay) => setTimeout(callback, delay), cancel: timer => clearTimeout(timer), probe: vi.fn(async () => true),
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
});
