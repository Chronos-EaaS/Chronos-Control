import { Component, DestroyRef, HostListener, inject, signal } from '@angular/core';
import { Router, RouterLink, RouterLinkActive, RouterOutlet } from '@angular/router';
import { Session } from '../core/session';
import { errorMessage } from '../core/api';
import { Feedback } from './feedback';
import { Icon } from './icon';
import { UnsavedChanges } from '../core/unsaved';

@Component({
  imports: [RouterOutlet, RouterLink, RouterLinkActive, Feedback, Icon],
  template: `<div class="app-wrapper" [class.sidebar-open]="menuOpen()" [class.sidebar-collapse]="collapsed()">
    <a href="#main-content" class="skip-link">Skip to content</a>
    <header class="app-header navbar navbar-expand bg-body border-bottom">
      <div class="container-fluid">
        <button id="navigation-toggle" type="button" class="btn nav-toggle" aria-label="Toggle navigation" aria-controls="main-navigation" [attr.aria-expanded]="mobile() ? menuOpen() : !collapsed()" (click)="toggle()"><app-icon name="menu" /></button>
        <span class="workspace-label">Evaluation workspace</span>
        <details class="account-menu ms-auto">
          <summary><span class="avatar" aria-hidden="true">{{ initials() }}</span><span class="account-name">{{ session.displayName() }}</span><span aria-hidden="true">⌄</span></summary>
          <div class="account-popover shadow">
            <strong>{{ session.displayName() }}</strong><span class="text-secondary small">{{ session.user()?.roleName }}</span>
            <a routerLink="/profile">Your profile</a>
            <button type="button" [disabled]="signingOut()" (click)="logout()">{{ session.impersonation().active ? 'Return to administrator' : 'Sign out' }}</button>
          </div>
        </details>
      </div>
    </header>
    <aside id="main-navigation" class="app-sidebar bg-navy shadow" data-bs-theme="dark" [attr.inert]="mobile() && !menuOpen() ? '' : null" (keydown)="drawerKey($event)">
      <div class="sidebar-brand"><a routerLink="/" class="brand-link" aria-label="Chronos Control home" (click)="close()"><img src="/chronos-mark.svg" alt="" width="38" height="38" /><span class="brand-text">Chronos <small>CONTROL</small></span></a></div>
      <div class="sidebar-wrapper">
        <span class="navigation-section">WORKSPACE</span>
        <nav aria-label="Main navigation"><ul class="nav sidebar-menu flex-column">
          <li class="nav-item"><a routerLink="/" [routerLinkActiveOptions]="{ exact: true }" routerLinkActive="active" class="nav-link" (click)="close()"><app-icon name="home" /><span>Overview</span></a></li>
          <li class="nav-item"><a routerLink="/projects" routerLinkActive="active" class="nav-link" (click)="close()"><app-icon name="projects" /><span>Projects</span></a></li>
          <li class="nav-item"><a routerLink="/status" routerLinkActive="active" class="nav-link" (click)="close()"><app-icon name="clock" /><span>Status</span></a></li>
          <li class="nav-item"><a routerLink="/systems" routerLinkActive="active" class="nav-link" (click)="close()"><app-icon name="systems" /><span>Systems</span></a></li>
          @if (session.isAdmin()) { <li class="nav-item"><a routerLink="/cem" routerLinkActive="active" class="nav-link" (click)="close()"><app-icon name="systems" /><span>CEM</span></a></li><li class="nav-item"><a routerLink="/admin" routerLinkActive="active" class="nav-link" (click)="close()"><app-icon name="user" /><span>Administration</span></a></li> }
        </ul></nav>
        <div class="sidebar-caption">Reproducible experiments.<br />Clearer results.</div>
      </div>
    </aside>
    @if (mobile() && menuOpen()) { <button type="button" class="sidebar-scrim" aria-label="Close navigation" (click)="close(true)"></button> }
    <main id="main-content" class="app-main" tabindex="-1">
      @if (session.impersonation().active) { <div class="impersonation-banner" role="status">You are viewing Chronos as {{ session.user()?.username }}. <button class="btn btn-link btn-sm" (click)="logout()">Return to administrator</button></div> }
      <div class="app-content"><app-feedback [error]="error()" /><router-outlet /></div>
    </main>
    <footer class="app-footer"><span>Chronos Control · University of Basel</span><span>Version 3</span></footer>
  </div>`,
})
export class Shell {
  readonly session = inject(Session);
  private readonly router = inject(Router);
  private readonly changes = inject(UnsavedChanges);
  readonly menuOpen = signal(false);
  readonly collapsed = signal(false);
  readonly mobile = signal(window.matchMedia('(max-width: 991.98px)').matches);
  readonly error = signal('');
  readonly signingOut = signal(false);
  constructor() {
    const media = window.matchMedia('(max-width: 991.98px)');
    const changed = () => { this.mobile.set(media.matches); this.menuOpen.set(false); };
    media.addEventListener('change', changed);
    inject(DestroyRef).onDestroy(() => media.removeEventListener('change', changed));
  }
  initials(): string { return this.session.displayName().split(' ').map(part => part[0]).slice(0, 2).join('').toUpperCase(); }
  toggle(): void {
    if (this.mobile()) {
      this.menuOpen.update(open => !open);
      if (this.menuOpen()) window.setTimeout(() => document.querySelector<HTMLAnchorElement>('#main-navigation .nav-link')?.focus());
    } else this.collapsed.update(value => !value);
  }
  close(returnFocus = false): void {
    this.menuOpen.set(false);
    if (returnFocus) document.getElementById('navigation-toggle')?.focus();
  }
  @HostListener('document:keydown.escape') escape(): void { if (this.menuOpen()) this.close(true); }
  drawerKey(event: KeyboardEvent): void {
    if (!this.mobile() || !this.menuOpen() || event.key !== 'Tab') return;
    const links = [...document.querySelectorAll<HTMLElement>('#main-navigation a[href], #main-navigation button')];
    if (!links.length) return;
    if (event.shiftKey && document.activeElement === links[0]) { event.preventDefault(); links.at(-1)?.focus(); }
    else if (!event.shiftKey && document.activeElement === links.at(-1)) { event.preventDefault(); links[0].focus(); }
  }
  async logout(): Promise<void> {
    if (this.signingOut() || !this.changes.mayDiscard()) return;
    this.signingOut.set(true); this.error.set('');
    try { await this.session.logout(); this.changes.dirty.set(false); await this.router.navigateByUrl(this.session.user() ? '/' : '/login'); }
    catch (error) { this.error.set(errorMessage(error)); }
    finally { this.signingOut.set(false); }
  }
}
