import { Component, DestroyRef, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router } from '@angular/router';
import { Session } from '../core/session';
import { errorMessage } from '../core/api';
import { ApplicationMetadata } from '../core/application-metadata';
import { Feedback } from '../shared/feedback';
import { Icon } from '../shared/icon';

@Component({
  imports: [FormsModule, Feedback, Icon],
  templateUrl: './login.html',
  styleUrl: './login.scss',
})
export class Login {
  private readonly session = inject(Session);
  private readonly router = inject(Router);
  private readonly route = inject(ActivatedRoute);
  username = '';
  password = '';
  remember = true;
  readonly applicationVersion = inject(ApplicationMetadata).version;
  readonly error = signal('');
  readonly busy = signal(false);
  constructor() {
    inject(DestroyRef).onDestroy(() => {
      this.password = '';
    });
  }
  async signIn(): Promise<void> {
    if (this.busy()) return;
    this.error.set('');
    this.busy.set(true);
    try {
      await this.session.login(this.username.trim(), this.password, this.remember);
      const target = this.route.snapshot.queryParamMap.get('returnUrl');
      await this.router.navigateByUrl(
        target?.startsWith('/') && !target.startsWith('//') ? target : '/',
      );
    } catch (error) {
      this.error.set(errorMessage(error));
    } finally {
      this.busy.set(false);
    }
  }
}
