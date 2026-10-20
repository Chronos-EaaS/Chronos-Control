import { TestBed } from '@angular/core/testing';
import { signal } from '@angular/core';
import { provideRouter } from '@angular/router';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { Api } from './api';
import { ApplicationMetadata } from './application-metadata';
import { Metadata, SessionData, User } from './models';
import { LIVE_RUNTIME, LiveRuntime, Realtime } from './realtime';
import { Session } from './session';
import { Login } from '../features/login';
import { Shell } from '../shared/shell';
import { UI_BUILD_ID, VersionNotice } from '../shared/version-notice';

class MetadataSocket {
  readyState = 0;
  onopen: ((event: Event) => void) | null = null;
  onmessage: ((event: MessageEvent) => void) | null = null;
  onerror: ((event: Event) => void) | null = null;
  onclose: ((event: CloseEvent) => void) | null = null;
  sent: Record<string, unknown>[] = [];
  send(data: string): void { this.sent.push(JSON.parse(data)); }
  close(): void { this.readyState = 3; this.onclose?.(new CloseEvent('close')); }
  open(): void { this.readyState = 1; this.onopen?.(new Event('open')); }
  receive(data: object): void { this.onmessage?.(new MessageEvent('message', { data: JSON.stringify(data) })); }
  subscriptions(): Record<string, unknown>[] { return this.sent.filter(frame => frame['type'] === 'subscribe'); }
  push(path: string, data: unknown, revision = 1): void {
    const id = this.subscriptions().find(frame => frame['path'] === path)!['id'];
    this.receive({ type: 'data', id, data, revision });
  }
}

const anonymous: SessionData = { user: null, csrfToken: 'public-csrf', impersonation: { active: false, originalUser: null } };
const authenticated: SessionData = {
  ...anonymous, user: { id: 1, username: 'owner', role: 0 } as User,
  live: { ticket: 'initial-ticket' },
};
const metadata = (version: string): Metadata => ({
  api: 'ui', applicationVersion: version, uiBuildId: `build-${version}`, siteName: 'Chronos', timezone: 'Europe/Zurich',
  password: { minLength: 12, maxLength: 72, requireComplexity: true },
});

describe('shared pushed application metadata', () => {
  let sockets: MetadataSocket[], protocols: string[][];
  let get = vi.fn(), post = vi.fn(), probe = vi.fn();
  beforeEach(() => {
    vi.useFakeTimers(); sockets = []; protocols = [];
    get = vi.fn(); post = vi.fn().mockResolvedValue(authenticated); probe = vi.fn().mockResolvedValue(true);
    const runtime: LiveRuntime = {
      now: () => Date.now(), later: (callback, delay) => setTimeout(callback, delay), cancel: timer => clearTimeout(timer), probe,
      socket: offered => { protocols.push(offered); const socket = new MetadataSocket(); sockets.push(socket); return socket; },
    };
    TestBed.configureTestingModule({ providers: [
      { provide: Api, useValue: { get, post, csrfToken: signal(''), sessionExpired: signal(0), expireSession: vi.fn() } },
      { provide: LIVE_RUNTIME, useValue: runtime },
    ] });
  });
  afterEach(() => { TestBed.resetTestingModule(); vi.useRealTimers(); });

  it('shares the guard identity lookup and opens one authenticated socket before loading public metadata', async () => {
    let finish!: (value: SessionData) => void;
    get.mockReturnValue(new Promise<SessionData>(resolve => { finish = resolve; }));
    const value = TestBed.inject(ApplicationMetadata);
    const initial = TestBed.inject(Session).load();
    expect(get).toHaveBeenCalledExactlyOnceWith('/session');
    expect(sockets).toHaveLength(0);
    expect(value.version()).toBeNull();
    finish(authenticated); await initial;
    expect(sockets).toHaveLength(1);
    expect(protocols).toEqual([['chronos-ui-v1', 'chronos-ticket.initial-ticket']]);
    sockets[0].open();
    expect(sockets[0].subscriptions().map(frame => frame['path'])).toEqual(['/session', '/meta']);
    sockets[0].push('/meta', metadata('3.0-dev'));
    expect(value.version()).toBe('3.0-dev');
    expect(TestBed.inject(ApplicationMetadata)).toBe(value);
    expect(get).not.toHaveBeenCalledWith('/meta');
    expect(post).not.toHaveBeenCalled();
  });

  it('uses public metadata on the login screen and rebinds the same stream after signing in', async () => {
    get.mockResolvedValue(anonymous);
    const value = TestBed.inject(ApplicationMetadata);
    await TestBed.inject(Session).load();
    sockets[0].open(); sockets[0].push('/meta', metadata('3.0-dev'));
    expect(protocols[0]).toEqual(['chronos-ui-v1']);
    expect(value.version()).toBe('3.0-dev');
    await TestBed.inject(Session).login('owner', 'password', true);
    expect(post).toHaveBeenCalledExactlyOnceWith('/session', { username: 'owner', password: 'password', remember: true });
    expect(sockets).toHaveLength(2); sockets[1].open();
    expect(sockets[1].subscriptions().filter(frame => frame['path'] === '/meta')).toHaveLength(1);
    expect(sockets[1].subscriptions().filter(frame => frame['path'] === '/session')).toHaveLength(1);
    sockets[0].push('/meta', metadata('obsolete'), 2);
    expect(value.version()).toBe('3.0-dev');
    sockets[1].push('/meta', metadata('3.0.1'));
    expect(value.version()).toBe('3.0.1');
    await TestBed.inject(Session).login('owner', 'password', true);
    expect(TestBed.inject(ApplicationMetadata)).toBe(value);
    expect(get).toHaveBeenCalledTimes(3); // One startup lookup plus one per explicit sign-in.
    expect(get.mock.calls.every(([path]) => path === '/session')).toBe(true);
  });

  it('keeps public metadata available after a failed session lookup without retrying HTTP while heartbeats arrive', async () => {
    get.mockRejectedValue(new Error('Identity lookup unavailable'));
    const value = TestBed.inject(ApplicationMetadata);
    await Promise.resolve(); await Promise.resolve(); await Promise.resolve();
    expect(sockets).toHaveLength(1); sockets[0].open();
    sockets[0].push('/meta', metadata('3.0-dev'));
    for (let index = 0; index < 8; ++index) {
      await vi.advanceTimersByTimeAsync(15_000); sockets[0].receive({ type: 'heartbeat' });
    }
    expect(value.version()).toBe('3.0-dev');
    expect(get).toHaveBeenCalledExactlyOnceWith('/session');
    expect(post).not.toHaveBeenCalled(); expect(probe).not.toHaveBeenCalled();
    get.mockResolvedValue(anonymous);
    await TestBed.inject(Session).login('owner', 'password', true);
    expect(TestBed.inject(Session).user()?.id).toBe(1);
    expect(get).toHaveBeenCalledTimes(2);
    sockets[1].open();
    expect(sockets[1].subscriptions().filter(frame => frame['path'] === '/meta')).toHaveLength(1);
  });

  it('does not let a delayed startup identity response replace a completed sign-in', async () => {
    let finish!: (value: SessionData) => void;
    get.mockReturnValueOnce(new Promise<SessionData>(resolve => { finish = resolve; })).mockResolvedValue(anonymous);
    TestBed.inject(ApplicationMetadata);
    const session = TestBed.inject(Session), initial = session.load();
    await session.login('owner', 'password', true);
    expect(session.user()?.id).toBe(1);
    let guardReady = false;
    void session.load().then(() => { guardReady = true; });
    await Promise.resolve();
    expect(guardReady).toBe(true);
    expect(get).toHaveBeenCalledTimes(2);
    finish(anonymous); await initial;
    expect(session.user()?.id).toBe(1);
    expect(protocols).toEqual([['chronos-ui-v1', 'chronos-ticket.initial-ticket']]);
    sockets[0].open();
    expect(sockets[0].subscriptions().filter(frame => frame['path'] === '/meta')).toHaveLength(1);
  });

  it('updates the shell, login and update notice from one shared subscription without HTTP metadata requests', async () => {
    const previousTitle = document.title;
    const close = vi.fn(); let push!: (data: Metadata) => void;
    const watch = vi.fn((_path, _query, apply) => { push = apply; return { close }; });
    const load = vi.fn().mockResolvedValue(undefined);
    vi.stubGlobal('matchMedia', vi.fn(() => ({ matches: false, addEventListener: vi.fn(), removeEventListener: vi.fn() })));
    TestBed.configureTestingModule({ imports: [Login, Shell, VersionNotice], providers: [provideRouter([]), { provide: UI_BUILD_ID, useValue: 'current' }] });
    TestBed.overrideProvider(Realtime, { useValue: { watch } });
    TestBed.overrideProvider(Session, { useValue: {
      load, displayName: () => 'Owner Fixture', user: signal(authenticated.user),
      isAdmin: () => false, impersonation: signal(anonymous.impersonation),
    } });
    try {
      const shell = TestBed.createComponent(Shell), login = TestBed.createComponent(Login), notice = TestBed.createComponent(VersionNotice);
      await Promise.resolve();
      expect(load).toHaveBeenCalledOnce(); expect(watch).toHaveBeenCalledExactlyOnceWith('/meta', {}, expect.any(Function));
      push(metadata('3.0.1')); TestBed.tick();
      for (const fixture of [shell, login, notice]) fixture.detectChanges();
      expect(shell.nativeElement.querySelector('.app-footer').textContent).toContain('Version 3.0.1');
      expect(login.nativeElement.querySelector('.login-footer').textContent).toContain('Version 3.0.1');
      expect(notice.nativeElement.textContent).toContain('A newer version of Chronos is available.');
      expect(get).not.toHaveBeenCalled(); expect(post).not.toHaveBeenCalled();
      for (const fixture of [shell, login, notice]) fixture.destroy();
      expect(close).not.toHaveBeenCalled(); // The application owns the shared stream.
      TestBed.resetTestingModule(); expect(close).toHaveBeenCalledOnce();
    } finally { document.title = previousTitle; vi.unstubAllGlobals(); }
  });

  it('closes its stream on application destruction and never starts one after delayed initialization is destroyed', async () => {
    const close = vi.fn(), watch = vi.fn(() => ({ close }));
    let finish!: () => void;
    TestBed.overrideProvider(Realtime, { useValue: { watch } });
    TestBed.overrideProvider(Session, { useValue: { load: () => new Promise<void>(resolve => { finish = resolve; }) } });
    TestBed.inject(ApplicationMetadata); TestBed.resetTestingModule();
    finish(); await Promise.resolve();
    expect(watch).not.toHaveBeenCalled();

    TestBed.configureTestingModule({ providers: [
      { provide: Realtime, useValue: { watch } }, { provide: Session, useValue: { load: () => Promise.resolve() } },
    ] });
    TestBed.inject(ApplicationMetadata); await Promise.resolve();
    expect(watch).toHaveBeenCalledOnce();
    TestBed.resetTestingModule(); expect(close).toHaveBeenCalledOnce();
  });
});
