import { Component, DestroyRef, ElementRef, InjectionToken, afterEveryRender, computed, effect, inject, viewChild } from '@angular/core';
import { Title } from '@angular/platform-browser';
import { ApplicationMetadata } from '../core/application-metadata';
import { UnsavedChanges } from '../core/unsaved';
import { ReloadPage } from '../core/reload';
import { Realtime } from '../core/realtime';

declare const __CHRONOS_BUILD_ID__: string | undefined;
export const clientBuildId = typeof __CHRONOS_BUILD_ID__ === 'string' ? __CHRONOS_BUILD_ID__ : 'development';
export const UI_BUILD_ID = new InjectionToken<string>('Loaded UI build identifier', { providedIn: 'root', factory: () => clientBuildId });

@Component({
  selector: 'app-version-notice',
  template: `@if (available() && !realtime.disconnected()) {
    <aside #notice popover="manual" class="version-notice shadow-lg" aria-label="Application update" aria-live="polite">
      <span>A newer version of Chronos is available.</span>
      <button class="btn btn-primary btn-sm" type="button" (click)="reload()">Load latest version</button>
    </aside>
  }`,
  styles: `.version-notice { inset: auto; left: 50%; bottom: max(1rem, env(safe-area-inset-bottom)); margin: 0; }
    .version-notice:popover-open { display: flex; }`,
})
export class VersionNotice {
  private readonly metadata = inject(ApplicationMetadata);
  readonly realtime = inject(Realtime);
  private readonly host = inject<ElementRef<HTMLElement>>(ElementRef).nativeElement;
  private readonly notice = viewChild<ElementRef<HTMLElement>>('notice');
  private home?: Comment;
  private shown?: HTMLElement;
  private readonly changes = inject(UnsavedChanges);
  private readonly loadedBuild = inject(UI_BUILD_ID);
  private readonly page = inject(ReloadPage);
  private readonly title = inject(Title);
  readonly available = computed(() => {
    const latest = this.latestBuild();
    return Boolean(latest && this.loadedBuild !== 'development' && latest !== this.loadedBuild);
  });
  private readonly latestBuild = computed(() => this.realtime.buildId() ?? this.metadata.data()?.uiBuildId ?? null);
  constructor() {
    effect(() => {
      const siteName = this.metadata.data()?.siteName;
      if (siteName) this.title.setTitle(siteName);
    });
    // Navigation can destroy the dialog containing this host without changing
    // a version signal or delivering its queued toggle event to the document.
    afterEveryRender(() => this.syncNotice());
    const dialogChanged = (event: Event) => { if (event.target instanceof HTMLDialogElement) this.syncNotice(true); };
    document.addEventListener('toggle', dialogChanged, true);
    inject(DestroyRef).onDestroy(() => {
      document.removeEventListener('toggle', dialogChanged, true);
      if (this.shown?.isConnected) this.shown.hidePopover?.();
      this.home?.before(this.host); this.home?.remove();
    });
  }
  private syncNotice(dialogChanged = false): void {
    if (!this.home && this.host.parentNode) { this.home = document.createComment('Chronos update notice'); this.host.before(this.home); }
    const notice = this.notice()?.nativeElement;
    // A top-layer popover outside a modal is still inert. Mount the component
    // inside the active dialog so its reload action remains keyboard-accessible.
    const modal = notice ? document.activeElement?.closest<HTMLDialogElement>('dialog[open]')
      ?? [...document.querySelectorAll<HTMLDialogElement>('dialog[open]')].at(-1) : undefined;
    const moved = modal ? this.host.parentNode !== modal
      : Boolean(this.home?.parentNode && (this.host.parentNode !== this.home.parentNode || this.host.nextSibling !== this.home));
    if (!moved && !dialogChanged && this.shown === notice) return;
    if (this.shown?.isConnected) this.shown.hidePopover?.(); this.shown = undefined;
    if (moved) { if (modal) modal.append(this.host); else this.home?.before(this.host); }
    if (notice?.isConnected) { notice.showPopover?.(); this.shown = notice; }
  }
  reload(): void {
    if (!this.changes.mayDiscard()) return;
    this.changes.clear();
    this.page.latest(this.latestBuild());
  }
}
