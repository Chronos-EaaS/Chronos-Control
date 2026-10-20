import { DestroyRef, Injectable, InjectionToken, inject, signal } from '@angular/core';
import { Api, ApiError } from './api';
import { SessionData } from './models';

export type LiveQuery = Record<string, string | number | boolean>;
export interface LiveSubscription<T> {
  readonly ready: Promise<T>;
  refresh(): Promise<T>;
  update(query: LiveQuery): Promise<T>;
  checkpoint(query: LiveQuery): void;
  close(): void;
}
interface LiveSocket {
  readonly readyState: number;
  onopen: ((event: Event) => void) | null;
  onmessage: ((event: MessageEvent) => void) | null;
  onerror: ((event: Event) => void) | null;
  onclose: ((event: CloseEvent) => void) | null;
  send(data: string): void;
  close(code?: number): void;
}
export interface LiveRuntime {
  now(): number;
  later(callback: () => void, milliseconds: number): ReturnType<typeof setTimeout>;
  cancel(timer: ReturnType<typeof setTimeout>): void;
  socket(protocols: string[]): LiveSocket;
  probe(): Promise<boolean>;
}
export const LIVE_RUNTIME = new InjectionToken<LiveRuntime>('WebSocket runtime', {
  providedIn: 'root', factory: () => ({
    now: () => Date.now(), later: (callback, delay) => setTimeout(callback, delay), cancel: timer => clearTimeout(timer),
    socket: protocols => new WebSocket(`${location.protocol === 'https:' ? 'wss:' : 'ws:'}//${location.host}/api/ui/live`, protocols),
    probe: async () => {
      const controller = new AbortController();
      const timer = setTimeout(() => controller.abort(), 5_000);
      try {
        const response = await fetch('/api/ui/live/health', { credentials: 'same-origin', cache: 'no-store', signal: controller.signal });
        if (!response.ok) return false;
        const body = await response.json() as { ready?: boolean; data?: { ready?: boolean } };
        return body.ready === true || body.data?.ready === true;
      } catch { return false; }
      finally { clearTimeout(timer); }
    },
  }),
});

interface SubscriptionState<T = unknown> {
  id: string; path: string; query: LiveQuery; closed: boolean;
  apply: (value: T) => void; failed?: (error: ApiError) => void;
  resolve?: (value: T) => void; reject?: (error: ApiError) => void;
  revision: number;
}

/** One connection carries authorized snapshots. Only a missed heartbeat permits a health request. */
@Injectable({ providedIn: 'root' })
export class Realtime {
  private readonly api = inject(Api);
  private readonly runtime = inject(LIVE_RUNTIME);
  private readonly subscriptions = new Map<string, SubscriptionState>();
  private socket?: LiveSocket;
  private timer?: ReturnType<typeof setTimeout>;
  private generation = 0;
  private sequence = 0;
  private authenticated = false;
  private identity = '';
  private ticket?: string;
  private lastHeartbeat = 0;
  private deadline = 0;
  private retry = 0;
  private disposed = false;
  private recovering = false;
  private connecting = false;
  readonly disconnected = signal(false);
  static readonly heartbeatTimeout = 45_000;

  constructor() {
    const onVisible = () => {
      if (document.visibilityState === 'visible' && this.deadline && this.runtime.now() >= this.deadline) void this.recover();
    };
    document.addEventListener('visibilitychange', onVisible);
    inject(DestroyRef).onDestroy(() => {
      this.disposed = true; this.generation++;
      if (this.timer) this.runtime.cancel(this.timer);
      this.socket?.close(1000);
      for (const state of this.subscriptions.values()) this.cancel(state);
      this.subscriptions.clear();
      document.removeEventListener('visibilitychange', onVisible);
    });
  }

  configure(data: SessionData): void {
    const identity = data.user ? `${data.user.id}:${data.impersonation.originalUser?.id ?? ''}` : '';
    const ticket = data.live?.ticket;
    if (this.identity === identity && (!ticket || ticket === this.ticket)) return;
    this.identity = identity; this.authenticated = Boolean(data.user); this.ticket = ticket;
    this.generation++; this.socket?.close(1000); this.socket = undefined;
    this.recovering = false; this.connecting = false; this.retry = 0;
    if (this.timer) this.runtime.cancel(this.timer);
    // A new identity invalidates all queued frames before its first snapshot arrives.
    for (const state of [...this.subscriptions.values()]) this.replace(state);
    void this.connect();
  }

  watch<T>(path: string, query: LiveQuery, apply: (data: T) => void, failed?: (error: ApiError) => void): LiveSubscription<T> {
    if (!path.startsWith('/') || path.includes('?') || path.includes('://')) throw new Error('A live resource path is required.');
    const state: SubscriptionState<T> = { id: this.nextId(), path, query: { ...query }, apply, failed, closed: false, revision: -1 };
    const ready = this.pending(state);
    this.subscriptions.set(state.id, state as SubscriptionState);
    this.sendSubscribe(state);
    if (!this.socket && !this.deadline) void this.connect();
    return {
      ready,
      refresh: () => this.renew(state),
      update: next => { state.query = { ...next }; return this.renew(state); },
      checkpoint: next => { state.query = { ...next }; },
      close: () => {
        if (state.closed) return;
        state.closed = true; this.send({ type: 'unsubscribe', id: state.id });
        this.subscriptions.delete(state.id); this.cancel(state);
      },
    };
  }

  read<T>(path: string, query: LiveQuery = {}): Promise<T> {
    const subscription = this.watch<T>(path, query, () => {});
    return subscription.ready.finally(() => subscription.close());
  }

  private nextId(): string { return `s${++this.sequence}`; }
  private pending<T>(state: SubscriptionState<T>): Promise<T> {
    const promise = new Promise<T>((resolve, reject) => { state.resolve = resolve; state.reject = reject; });
    // Destruction/rebinding may precede a component awaiting its initial snapshot.
    void promise.catch(() => {});
    return promise;
  }
  private cancel<T>(state: SubscriptionState<T>): void {
    state.reject?.(new ApiError('The live request was superseded.', 0, 'live_cancelled'));
    state.resolve = undefined; state.reject = undefined;
  }
  private replace(state: SubscriptionState): void {
    this.send({ type: 'unsubscribe', id: state.id }); this.subscriptions.delete(state.id);
    state.id = this.nextId(); state.revision = -1; this.subscriptions.set(state.id, state);
  }
  private renew<T>(state: SubscriptionState<T>): Promise<T> {
    if (state.closed) return Promise.reject(new ApiError('The live view is closed.', 0, 'live_cancelled'));
    this.cancel(state as SubscriptionState); this.replace(state as SubscriptionState);
    const ready = this.pending(state); this.sendSubscribe(state); return ready;
  }
  private send(frame: unknown): void {
    if (this.socket?.readyState === 1) this.socket.send(JSON.stringify(frame));
  }
  private sendSubscribe<T>(state: SubscriptionState<T>): void {
    if (state.closed) return;
    this.send({ type: 'subscribe', id: state.id, path: state.path,
      query: Object.fromEntries(Object.entries(state.query).map(([key, value]) => [key, String(value)])) });
  }

  private async connect(): Promise<void> {
    if (this.disposed || this.socket || this.connecting) return;
    this.connecting = true;
    const generation = ++this.generation;
    for (const state of [...this.subscriptions.values()]) this.replace(state);
    this.deadline = this.runtime.now() + Realtime.heartbeatTimeout;
    this.arm(Realtime.heartbeatTimeout);
    try {
      let ticket = this.ticket; this.ticket = undefined;
      if (this.authenticated && !ticket) ticket = (await this.api.post<{ ticket: string }>('/live/ticket')).ticket;
      if (generation !== this.generation || this.disposed) return;
      const socket = this.runtime.socket(['chronos-ui-v1', ...(ticket ? [`chronos-ticket.${ticket}`] : [])]);
      this.socket = socket;
      socket.onopen = () => {
        if (generation !== this.generation) return;
        for (const state of this.subscriptions.values()) this.sendSubscribe(state);
      };
      socket.onmessage = event => {
        if (generation !== this.generation) return;
        this.receive(event.data);
      };
      socket.onclose = () => { if (generation === this.generation) this.socket = undefined; };
      socket.onerror = () => { /* The heartbeat deadline controls bounded recovery, including handshake failures. */ };
    } catch {
      // A failed ticket request is retried only after the existing heartbeat deadline.
    } finally { if (generation === this.generation) this.connecting = false; }
  }

  private receive(raw: unknown): void {
    if (typeof raw !== 'string') return;
    let frame: Record<string, unknown>;
    try { frame = JSON.parse(raw) as Record<string, unknown>; } catch { return; }
    if (!frame || typeof frame !== 'object') return;
    if (frame['type'] === 'heartbeat') {
      this.lastHeartbeat = this.runtime.now(); this.deadline = this.lastHeartbeat + Realtime.heartbeatTimeout;
      this.disconnected.set(false); this.retry = 0; this.arm(Realtime.heartbeatTimeout); return;
    }
    if (frame['type'] === 'session-expired') { this.expire(); return; }
    if (frame['type'] === 'restart' && frame['reason'] === 'build-changed') {
      // A server-directed deployment restart is distinct from a missing heartbeat.
      const generation = ++this.generation;
      this.socket?.close(1000); this.socket = undefined; this.connecting = false; this.recovering = false;
      if (this.timer) this.runtime.cancel(this.timer);
      this.timer = this.runtime.later(() => {
        if (generation === this.generation) void this.connect();
      }, 2_500);
      return;
    }
    const state = typeof frame['id'] === 'string' ? this.subscriptions.get(frame['id']) : undefined;
    if (!state || state.closed) return;
    if (frame['type'] === 'data') {
      const revision = frame['revision'];
      if (typeof revision !== 'number' || !Number.isSafeInteger(revision) || revision <= state.revision) return;
      state.revision = revision;
      state.apply(frame['data']); state.resolve?.(frame['data']); state.resolve = undefined; state.reject = undefined;
    } else if (frame['type'] === 'error') {
      const detail = frame['error'] as { message?: string; code?: string } | undefined;
      const error = new ApiError(detail?.message ?? 'The live view could not be loaded.', Number(frame['status']) || 500, detail?.code ?? 'live_failed');
      if (error.status === 401) { this.expire(error); return; }
      state.reject?.(error); state.resolve = undefined; state.reject = undefined; state.failed?.(error);
    }
  }

  private expire(error = new ApiError('Your session has expired. Please sign in again.', 401, 'session_expired')): void {
    this.generation++; this.socket?.close(1000); this.socket = undefined;
    this.connecting = false; this.recovering = false;
    if (this.timer) this.runtime.cancel(this.timer);
    this.timer = undefined; this.deadline = 0;
    this.authenticated = false; this.ticket = undefined;
    // Session will bind an anonymous connection for public metadata. Until then,
    // neither a delayed timer nor a retained credential may revive this identity.
    this.identity = '__expired__';
    for (const state of [...this.subscriptions.values()]) {
      if (state.path === '/meta') continue;
      state.closed = true; this.subscriptions.delete(state.id);
      state.reject?.(error); state.resolve = undefined; state.reject = undefined;
      state.failed?.(error);
    }
    this.api.expireSession();
  }

  private arm(delay: number): void {
    if (this.timer) this.runtime.cancel(this.timer);
    this.timer = this.runtime.later(() => { void this.recover(); }, delay);
  }
  private async recover(): Promise<void> {
    if (this.disposed || this.recovering) return;
    const remaining = this.deadline - this.runtime.now();
    if (remaining > 0) { this.arm(remaining); return; }
    this.recovering = true;
    const generation = this.generation;
    const healthy = await this.runtime.probe().catch(() => false);
    if (generation !== this.generation || this.disposed) { this.recovering = false; return; }
    // A heartbeat received while the health probe was in flight wins over its stale result.
    if (this.runtime.now() < this.deadline) { this.recovering = false; return; }
    if (!healthy) this.disconnected.set(true);
    this.generation++; this.socket?.close(1000); this.socket = undefined; this.connecting = false;
    this.recovering = false;
    if (healthy) { void this.connect(); return; }
    const delay = Math.min(30_000, 5_000 * 2 ** Math.min(this.retry++, 3));
    this.arm(delay);
  }
}
