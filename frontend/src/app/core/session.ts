import { DestroyRef, Injectable, computed, effect, inject, signal } from '@angular/core';
import { CanActivateFn, Router } from '@angular/router';
import { Api } from './api';
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
  private identityOperation?: Promise<unknown>;
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
    effect(() => {
      if (this.api.sessionExpired()) {
        this.user.set(null); this.initialRequest = undefined;
        this.liveSession?.close(); this.liveSession = undefined;
        this.realtime.configure({ user: null, csrfToken: '', impersonation: { active: false, originalUser: null } });
      }
    });
    inject(DestroyRef).onDestroy(() => this.liveSession?.close());
  }
  private apply(data: SessionData, reconnect = true): void {
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
    return this.initialRequest ??= this.refresh().catch(error => { this.initialRequest = undefined; throw error; });
  }
  async refresh(): Promise<void> { this.apply(await this.api.get<SessionData>('/session')); }
  /** Rotate an identity without accepting expiry frames from its previous lease. */
  withIdentityChange<T>(action: () => Promise<T>): Promise<T> {
    return this.changeIdentity(action, () => this.refresh());
  }
  private async changeIdentity<T>(action: () => Promise<T>, complete: (value: T) => void | Promise<void>): Promise<T> {
    // Separate controls (for example password and sign-out) share one identity.
    while (this.identityOperation) await this.identityOperation.catch(() => {});
    const fallback = this.snapshot;
    this.realtime.suspend();
    const operation = (async () => {
      try {
        const value = await action();
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
