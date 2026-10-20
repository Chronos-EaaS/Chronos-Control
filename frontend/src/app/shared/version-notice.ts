import { Component, InjectionToken, computed, effect, inject } from '@angular/core';
import { Title } from '@angular/platform-browser';
import { ApplicationMetadata } from '../core/application-metadata';
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
  private readonly metadata = inject(ApplicationMetadata);
  private readonly changes = inject(UnsavedChanges);
  private readonly loadedBuild = inject(UI_BUILD_ID);
  private readonly page = inject(ReloadPage);
  private readonly title = inject(Title);
  readonly available = computed(() => {
    const latest = this.metadata.data()?.uiBuildId;
    return Boolean(latest && this.loadedBuild !== 'development' && latest !== this.loadedBuild);
  });
  constructor() {
    effect(() => {
      const siteName = this.metadata.data()?.siteName;
      if (siteName) this.title.setTitle(siteName);
    });
  }
  reload(): void {
    if (!this.changes.mayDiscard()) return;
    this.changes.clear();
    this.page.latest(this.metadata.data()?.uiBuildId ?? null);
  }
}
