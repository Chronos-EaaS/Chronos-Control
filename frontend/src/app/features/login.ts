import { Component, DestroyRef, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router } from '@angular/router';
import { Session } from '../core/session';
import { errorMessage } from '../core/api';
import { Feedback } from '../shared/feedback';

@Component({
  imports: [FormsModule, Feedback],
  template: `<main class="login-screen">
    <section class="login-identity" aria-label="Chronos"><img src="/chronos-mark.svg" width="72" height="72" alt="" /><p class="brand-name">Chronos <span>Control</span></p><h1>From experiment<br />to insight.</h1><p>Configure your systems, run reproducible evaluations, and explore the results.</p><div class="identity-orbit" aria-hidden="true"></div></section>
    <section class="login-form-area"><div class="login-form-card">
      <div class="mobile-brand"><img src="/chronos-mark.svg" width="44" height="44" alt="" /> Chronos Control</div>
      <span class="eyebrow">YOUR EVALUATION WORKSPACE</span><h2>Welcome back</h2><p class="text-secondary">Sign in to continue to Chronos.</p>
      <app-feedback [error]="error()" />
      <form #form="ngForm" (ngSubmit)="signIn()">
        <label for="username" class="form-label">Username</label><input id="username" class="form-control form-control-lg" name="username" autocomplete="username" required [(ngModel)]="username" [disabled]="busy()" />
        <label for="password" class="form-label mt-3">Password</label><input id="password" class="form-control form-control-lg" type="password" name="password" autocomplete="current-password" required [(ngModel)]="password" [disabled]="busy()" />
        <div class="form-check my-4"><input id="remember" class="form-check-input" type="checkbox" name="remember" [(ngModel)]="remember" /><label class="form-check-label" for="remember">Remember me</label></div>
        <button class="btn btn-primary btn-lg w-100" type="submit" [disabled]="busy() || form.invalid">{{ busy() ? 'Signing in…' : 'Sign in' }}</button>
      </form><p class="login-footer">Chronos Control · Version 3</p>
    </div></section>
  </main>`,
})
export class Login {
  private readonly session = inject(Session);
  private readonly router = inject(Router);
  private readonly route = inject(ActivatedRoute);
  username = ''; password = ''; remember = false;
  readonly error = signal(''); readonly busy = signal(false);
  constructor() { inject(DestroyRef).onDestroy(() => { this.password = ''; }); }
  async signIn(): Promise<void> {
    if (this.busy()) return;
    this.error.set(''); this.busy.set(true);
    try {
      await this.session.login(this.username.trim(), this.password, this.remember);
      const target = this.route.snapshot.queryParamMap.get('returnUrl');
      await this.router.navigateByUrl(target?.startsWith('/') && !target.startsWith('//') ? target : '/');
    } catch (error) { this.error.set(errorMessage(error)); }
    finally { this.busy.set(false); }
  }
}
