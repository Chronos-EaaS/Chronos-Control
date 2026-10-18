import { Component, DestroyRef, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router } from '@angular/router';
import { Session } from '../core/session';
import { Api, errorMessage } from '../core/api';
import { Metadata } from '../core/models';
import { Feedback } from '../shared/feedback';

@Component({
  imports: [FormsModule, Feedback],
  templateUrl: './login.html',
  styleUrl: './login.scss',
})
export class Login {
  private readonly session = inject(Session);
  private readonly router = inject(Router);
  private readonly route = inject(ActivatedRoute);
  private readonly api = inject(Api);
  username = ''; password = ''; remember = true;
  readonly applicationVersion = signal<string | null>(null);
  readonly error = signal(''); readonly busy = signal(false);
  constructor() {
    inject(DestroyRef).onDestroy(() => { this.password = ''; });
    void this.loadVersion();
  }
  private async loadVersion(): Promise<void> {
    try { const metadata = await this.api.get<Metadata>('/meta'); this.applicationVersion.set(metadata.applicationVersion || null); }
    catch { /* Version metadata must not prevent signing in. */ }
  }
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
