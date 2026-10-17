import { Component, DestroyRef, InjectionToken, inject, signal } from '@angular/core';
import { Api } from '../core/api';
import { Metadata } from '../core/models';
import { UnsavedChanges } from '../core/unsaved';
import { ReloadPage } from '../core/reload';

declare const __CHRONOS_BUILD_ID__: string | undefined;
export const clientBuildId = typeof __CHRONOS_BUILD_ID__ === 'string' ? __CHRONOS_BUILD_ID__ : 'development';
export const UI_BUILD_ID = new InjectionToken<string>('Loaded UI build identifier', { providedIn: 'root', factory: () => clientBuildId });

@Component({
  selector: 'app-version-notice',
  template: `@if (available()) {
    <aside class="version-notice shadow-lg" aria-label="Application update" aria-live="polite">
      <span>A newer version of Chronos is available.</span>
      <button class="btn btn-primary btn-sm" type="button" (click)="reload()">Load latest version</button>
    </aside>
  }`,
})
export class VersionNotice {
  private readonly api = inject(Api);
  private readonly changes = inject(UnsavedChanges);
  private readonly loadedBuild = inject(UI_BUILD_ID);
  private readonly page = inject(ReloadPage);
  readonly available = signal(false);
  private checking = false;
  private latest: string | null = null;
  constructor() {
    const checkOnVisible = () => { if (document.visibilityState === 'visible') void this.check(); };
    document.addEventListener('visibilitychange', checkOnVisible);
    const timer = window.setInterval(checkOnVisible, 60_000);
    inject(DestroyRef).onDestroy(() => { window.clearInterval(timer); document.removeEventListener('visibilitychange', checkOnVisible); });
    void this.check();
  }
  private async check(): Promise<void> {
    if (this.checking) return;
    this.checking = true;
    try {
      const metadata = await this.api.get<Metadata>('/meta', { _check: Date.now() });
      this.latest = metadata.uiBuildId;
      this.available.set(Boolean(this.latest && this.loadedBuild !== 'development' && this.latest !== this.loadedBuild));
    } catch { /* A failed version check must not interrupt an active workflow. */ }
    finally { this.checking = false; }
  }
  reload(): void {
    if (!this.changes.mayDiscard()) return;
    this.changes.dirty.set(false);
    this.page.latest(this.latest);
  }
}
