import { DestroyRef, Injectable, computed, effect, inject, signal } from '@angular/core';
import { CanActivateFn, Router } from '@angular/router';
import { Api, ApiError } from './api';
import { SessionData, User } from './models';
import { LiveSubscription, Realtime } from './realtime';

// User DTO fields are scalar. Compare their values rather than the allocation
// of each HTTP/WebSocket snapshot, including independently ordered JSON keys.
function sameUser(previous: User | null, next: User | null): boolean {
  if (previous === next) return true;
  if (!previous || !next) return false;
  const keys = Object.keys(previous) as (keyof User)[];
  return keys.length === Object.keys(next).length && keys.every(key => previous[key] === next[key]);
}

@Injectable({ providedIn: 'root' })
export class Session {
  private readonly api = inject(Api);
  private readonly realtime = inject(Realtime);
  private liveSession?: LiveSubscription<SessionData>;
  private initialRequest?: Promise<void>;
  private refreshSequence = 0;
  private identityOperation?: Promise<unknown>;
  private recoveryRequest?: Promise<SessionData | null>;
  private snapshot: SessionData = { user: null, csrfToken: '', impersonation: { active: false, originalUser: null } };
  readonly user = signal<User | null>(null, { equal: sameUser });
  readonly impersonation = signal<SessionData['impersonation']>({ active: false, originalUser: null }, {
    equal: (previous, next) => previous.active === next.active && sameUser(previous.originalUser, next.originalUser),
  });
  readonly isAdmin = computed(() => (this.user()?.role ?? 0) >= 1);
  readonly isSuperAdmin = computed(() => this.user()?.role === 2);
  readonly displayName = computed(() => {
    const user = this.user();
    return user ? [user.firstName, user.lastName].filter(Boolean).join(' ') || user.username : '';
  });
  constructor() {
    const stopRecovery = this.realtime.setSessionRecovery(() => this.restoreLiveSession());
    effect(() => {
      if (this.api.sessionExpired()) {
        ++this.refreshSequence;
        this.user.set(null); this.initialRequest = undefined;
        this.liveSession?.close(); this.liveSession = undefined;
        this.realtime.configure({ user: null, csrfToken: '', impersonation: { active: false, originalUser: null } });
      }
    });
    inject(DestroyRef).onDestroy(() => { ++this.refreshSequence; stopRecovery(); this.liveSession?.close(); });
  }
  private apply(data: SessionData, reconnect = true): void {
    // An authoritative identity also completes startup for later route guards,
    // even if an obsolete initial lookup is still waiting for its response.
    this.initialRequest = Promise.resolve();
    // Cached identities never retain a one-use ticket consumed by a prior socket.
    this.snapshot = { user: data.user, csrfToken: data.csrfToken, impersonation: data.impersonation };
    this.api.csrfToken.set(data.csrfToken);
    this.user.set(data.user);
    this.impersonation.set(data.impersonation);
    if (reconnect) {
      this.liveSession?.close(); this.liveSession = undefined;
      this.realtime.configure(data);
      if (data.user) this.liveSession = this.realtime.watch<SessionData>('/session', {}, next => this.apply(next, false));
    }
  }
  load(): Promise<void> {
    if (!this.initialRequest) {
      const request = this.refresh().catch(error => {
        if (this.initialRequest === request) this.initialRequest = undefined;
        throw error;
      });
      this.initialRequest = request;
    }
    return this.initialRequest;
  }
  async refresh(): Promise<void> {
    const sequence = ++this.refreshSequence;
    const data = await this.api.get<SessionData>('/session');
    // A delayed identity lookup cannot replace a more recent lookup or a sign-in,
    // sign-out or expiry that has already invalidated its credentials.
    if (sequence === this.refreshSequence) this.apply(data);
  }
  private restoreLiveSession(): Promise<SessionData | null> {
    if (this.recoveryRequest) return this.recoveryRequest;
    const previous = this.snapshot;
    const sequence = ++this.refreshSequence;
    const request = (async () => {
      let data: SessionData;
      try { data = await this.api.get<SessionData>('/session'); }
      catch (error) {
        if (error instanceof ApiError && [401, 403].includes(error.status)) return null;
        throw error;
      }
      if (sequence !== this.refreshSequence) throw new ApiError('The identity lookup was superseded.', 0, 'live_cancelled');
      // A remembered admin is not the same identity as its expired impersonation.
      // Never carry another account's private views or drafts into that identity.
      if (!previous.user || !data.user || previous.user.id !== data.user.id
        || previous.impersonation.active !== data.impersonation.active) return null;
      const original = previous.impersonation.originalUser;
      const nextOriginal = data.impersonation.originalUser;
      if (previous.impersonation.active
        ? !original || !nextOriginal || original.id !== nextOriginal.id
        : original !== null || nextOriginal !== null) return null;
      this.apply(data, false);
      return data;
    })();
    this.recoveryRequest = request;
    const clear = () => { if (this.recoveryRequest === request) this.recoveryRequest = undefined; };
    void request.then(clear, clear);
    return request;
  }
  /** Rotate an identity without accepting expiry frames from its previous lease. */
  withIdentityChange<T>(action: () => Promise<T>): Promise<T> {
    return this.changeIdentity(action, () => this.refresh());
  }
  private async changeIdentity<T>(action: () => Promise<T>, complete: (value: T) => void | Promise<void>): Promise<T> {
    // Separate controls (for example password and sign-out) share one identity.
    // Let a restoring HTTP request settle before logout/password/switch writes:
    // even an ignored late response could otherwise restore its PHP cookie.
    while (this.identityOperation || this.recoveryRequest) await (this.identityOperation ?? this.recoveryRequest)!.catch(() => {});
    ++this.refreshSequence;
    const fallback = this.snapshot;
    this.realtime.suspend();
    const operation = (async () => {
      try {
        const value = await action();
        ++this.refreshSequence;
        await complete(value);
        return value;
      } catch (error) {
        // Recover the authoritative identity after both rejected and ambiguous
        // writes, preserving the action's error if the recovery request fails.
        try { await this.refresh(); } catch { this.apply(fallback); }
        throw error;
      }
    })();
    this.identityOperation = operation;
    try { return await operation; }
    finally { if (this.identityOperation === operation) this.identityOperation = undefined; }
  }
  async login(username: string, password: string, remember: boolean): Promise<void> {
    await this.refresh();
    await this.changeIdentity(() => this.api.post<SessionData>('/session', { username, password, remember }), data => this.apply(data));
  }
  async logout(): Promise<void> {
    await this.changeIdentity(() => this.api.delete<SessionData>('/session'), data => this.apply(data));
  }

}

export const signedIn: CanActivateFn = async (_, state) => {
  const session = inject(Session);
  const router = inject(Router);
  try { await session.load(); } catch { /* The login screen offers retry feedback. */ }
  return session.user() ? true : router.createUrlTree(['/login'], { queryParams: { returnUrl: state.url } });
};
