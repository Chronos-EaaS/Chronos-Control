import { Injectable, computed, effect, inject, signal } from '@angular/core';
import { CanActivateFn, Router } from '@angular/router';
import { Api } from './api';
import { SessionData, User } from './models';

@Injectable({ providedIn: 'root' })
export class Session {
  private readonly api = inject(Api);
  private initialRequest?: Promise<void>;
  readonly user = signal<User | null>(null);
  readonly impersonation = signal<SessionData['impersonation']>({ active: false, originalUser: null });
  readonly isAdmin = computed(() => (this.user()?.role ?? 0) >= 1);
  readonly isSuperAdmin = computed(() => this.user()?.role === 2);
  readonly displayName = computed(() => {
    const user = this.user();
    return user ? [user.firstName, user.lastName].filter(Boolean).join(' ') || user.username : '';
  });
  constructor() {
    effect(() => {
      if (this.api.sessionExpired()) { this.user.set(null); this.initialRequest = undefined; }
    });
  }
  private apply(data: SessionData): void {
    this.api.csrfToken.set(data.csrfToken);
    this.user.set(data.user);
    this.impersonation.set(data.impersonation);
  }
  load(): Promise<void> {
    return this.initialRequest ??= this.refresh().catch(error => { this.initialRequest = undefined; throw error; });
  }
  async refresh(): Promise<void> { this.apply(await this.api.get<SessionData>('/session')); }
  async login(username: string, password: string, remember: boolean): Promise<void> {
    await this.refresh();
    this.apply(await this.api.post<SessionData>('/session', { username, password, remember }));
  }
  async logout(): Promise<void> { this.apply(await this.api.delete<SessionData>('/session')); }
}

export const signedIn: CanActivateFn = async (_, state) => {
  const session = inject(Session);
  const router = inject(Router);
  try { await session.load(); } catch { /* The login screen offers retry feedback. */ }
  return session.user() ? true : router.createUrlTree(['/login'], { queryParams: { returnUrl: state.url } });
};
