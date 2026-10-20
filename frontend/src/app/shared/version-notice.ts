import { Component, DestroyRef, InjectionToken, inject, signal } from '@angular/core';
import { Title } from '@angular/platform-browser';
import { Realtime } from '../core/realtime';
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
  private readonly realtime = inject(Realtime);
  private readonly changes = inject(UnsavedChanges);
  private readonly loadedBuild = inject(UI_BUILD_ID);
  private readonly page = inject(ReloadPage);
  private readonly title = inject(Title);
  readonly available = signal(false);
  private latest: string | null = null;
  constructor() {
    const subscription = this.realtime.watch<Metadata>('/meta', {}, metadata => {
      if (metadata.siteName) this.title.setTitle(metadata.siteName);
      this.latest = metadata.uiBuildId;
      this.available.set(Boolean(this.latest && this.loadedBuild !== 'development' && this.latest !== this.loadedBuild));
    });
    inject(DestroyRef).onDestroy(() => subscription.close());
  }
  reload(): void {
    if (!this.changes.mayDiscard()) return;
    this.changes.clear();
    this.page.latest(this.latest);
  }
}
