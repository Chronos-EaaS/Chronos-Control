import { Component, DestroyRef, HostListener, inject, signal } from '@angular/core';
import { Router, RouterLink, RouterLinkActive, RouterOutlet } from '@angular/router';
import { Session } from '../core/session';
import { Api, errorMessage } from '../core/api';
import { Metadata } from '../core/models';
import { Feedback } from './feedback';
import { Icon } from './icon';
import { UnsavedChanges } from '../core/unsaved';

@Component({
  imports: [RouterOutlet, RouterLink, RouterLinkActive, Feedback, Icon],
  templateUrl: './shell.html',
})
export class Shell {
  readonly session = inject(Session);
  private readonly router = inject(Router);
  private readonly changes = inject(UnsavedChanges);
  private readonly api = inject(Api);
  readonly applicationVersion = signal<string | null>(null);
  readonly menuOpen = signal(false);
  readonly collapsed = signal(false);
  readonly mobile = signal(window.matchMedia('(max-width: 991.98px)').matches);
  readonly error = signal('');
  readonly signingOut = signal(false);
  constructor() {
    const media = window.matchMedia('(max-width: 991.98px)');
    const changed = () => {
      this.mobile.set(media.matches);
      this.menuOpen.set(false);
    };
    media.addEventListener('change', changed);
    inject(DestroyRef).onDestroy(() => media.removeEventListener('change', changed));
    void this.loadVersion();
  }
  private async loadVersion(): Promise<void> {
    try {
      const metadata = await this.api.get<Metadata>('/meta');
      this.applicationVersion.set(metadata.applicationVersion || null);
    } catch {
      /* A failed version lookup must not interrupt an active workflow. */
    }
  }
  initials(): string {
    return this.session
      .displayName()
      .split(' ')
      .map((part) => part[0])
      .slice(0, 2)
      .join('')
      .toUpperCase();
  }
  toggle(): void {
    if (this.mobile()) {
      this.menuOpen.update((open) => !open);
      if (this.menuOpen())
        window.setTimeout(() =>
          document.querySelector<HTMLAnchorElement>('#main-navigation .nav-link')?.focus(),
        );
    } else this.collapsed.update((value) => !value);
  }
  close(returnFocus = false): void {
    this.menuOpen.set(false);
    if (returnFocus) document.getElementById('navigation-toggle')?.focus();
  }
  @HostListener('document:keydown.escape') escape(): void {
    if (this.menuOpen()) this.close(true);
  }
  drawerKey(event: KeyboardEvent): void {
    if (!this.mobile() || !this.menuOpen() || event.key !== 'Tab') return;
    const links = [
      ...document.querySelectorAll<HTMLElement>(
        '#main-navigation a[href], #main-navigation button',
      ),
    ];
    if (!links.length) return;
    if (event.shiftKey && document.activeElement === links[0]) {
      event.preventDefault();
      links.at(-1)?.focus();
    } else if (!event.shiftKey && document.activeElement === links.at(-1)) {
      event.preventDefault();
      links[0].focus();
    }
  }
  async logout(): Promise<void> {
    if (this.signingOut() || !this.changes.mayDiscard()) return;
    this.signingOut.set(true);
    this.error.set('');
    try {
      await this.session.logout();
      this.changes.clear();
      await this.router.navigateByUrl(this.session.user() ? '/' : '/login');
    } catch (error) {
      this.error.set(errorMessage(error));
    } finally {
      this.signingOut.set(false);
    }
  }
}
