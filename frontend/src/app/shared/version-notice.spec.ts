import { ComponentFixture, TestBed } from '@angular/core/testing';
import { signal } from '@angular/core';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { Realtime } from '../core/realtime';
import { ReloadPage } from '../core/reload';
import { UnsavedChanges } from '../core/unsaved';
import { UI_BUILD_ID, VersionNotice } from './version-notice';
import { Session } from '../core/session';

describe('stale UI version notice', () => {
  let fixture: ComponentFixture<VersionNotice> | undefined;
  const initialTitle = document.title;
  afterEach(() => { fixture?.destroy(); fixture = undefined; document.title = initialTitle; vi.restoreAllMocks(); });

  async function render(serverId: string | null, fail = false, siteName?: string, loadedBuild = 'build-previous') {
    let push!: (data: unknown) => void;
    const close = vi.fn();
    const watch = vi.fn((_path, _query, apply) => { push = apply; if (!fail) queueMicrotask(() => apply({ uiBuildId: serverId, siteName })); return { ready: Promise.resolve(), close }; });
    const latest = vi.fn();
    const buildId = signal<string | null>(null), disconnected = signal(false);
    TestBed.configureTestingModule({ providers: [
      { provide: Realtime, useValue: { watch, buildId, disconnected } }, { provide: UI_BUILD_ID, useValue: loadedBuild },
      { provide: Session, useValue: { load: vi.fn().mockResolvedValue(undefined) } },
      { provide: ReloadPage, useValue: { latest } },
    ] });
    fixture = TestBed.createComponent(VersionNotice);
    await fixture.whenStable(); fixture.detectChanges();
    return { watch, push, close, latest, buildId, disconnected, element: fixture.nativeElement as HTMLElement };
  }

  it('stays quiet when the loaded build is current or metadata is absent', async () => {
    const { element } = await render('build-previous');
    expect(element.querySelector('aside')).toBeNull();
  });
  it('keeps the configured site name as the browser title without changing the update notice', async () => {
    document.title = 'Chronos Control';
    const { element } = await render('build-previous', false, 'Research & <Lab> – Zürich');
    expect(document.title).toBe('Research & <Lab> – Zürich');
    expect(element.querySelector('aside')).toBeNull();
  });
  it('announces an available build and reloads only when explicitly requested', async () => {
    const { element, latest } = await render('build-current');
    expect(element.textContent).toContain('A newer version of Chronos is available.');
    expect(latest).not.toHaveBeenCalled();
    (element.querySelector('button') as HTMLButtonElement).click();
    expect(latest).toHaveBeenCalledWith('build-current');
  });
  it('keeps working quietly during a failed version check', async () => {
    const { element } = await render(null, true);
    expect(element.querySelector('aside')).toBeNull();
  });
  it('protects unsaved work if the user declines to reload', async () => {
    const { element, latest } = await render('build-current');
    TestBed.inject(UnsavedChanges).setDirty(true);
    vi.spyOn(window, 'confirm').mockReturnValue(false);
    (element.querySelector('button') as HTMLButtonElement).click();
    expect(latest).not.toHaveBeenCalled();
  });
  it('accepts pushed metadata without making visibility requests', async () => {
    const { watch, push } = await render('build-previous');
    vi.spyOn(document, 'visibilityState', 'get').mockReturnValue('visible');
    document.dispatchEvent(new Event('visibilitychange'));
    await fixture!.whenStable();
    expect(watch).toHaveBeenCalledTimes(1);
    push({ uiBuildId: 'new-build' }); fixture!.detectChanges();
    expect(fixture!.componentInstance.available()).toBe(true);
  });
  it('announces a heartbeat build when metadata is unavailable and retains same-build/development guards', async () => {
    const { element, buildId, latest } = await render(null, true);
    buildId.set('build-previous'); fixture!.detectChanges();
    expect(element.querySelector('aside')).toBeNull();
    buildId.set('build-current'); fixture!.detectChanges();
    expect(element.querySelector('aside')).not.toBeNull();
    (element.querySelector('button') as HTMLButtonElement).click();
    expect(latest).toHaveBeenCalledExactlyOnceWith('build-current');
    buildId.set('build-previous'); fixture!.detectChanges();
    expect(element.querySelector('aside')).toBeNull();
    fixture!.destroy(); TestBed.resetTestingModule();
    const development = await render('build-current', false, undefined, 'development');
    development.buildId.set('build-current'); fixture!.detectChanges();
    expect(development.element.querySelector('aside')).toBeNull();
  });
  it('mounts the reload action inside an open dialog and restores its home when the dialog closes', async () => {
    const dialog = document.createElement('dialog'); dialog.open = true; document.body.append(dialog);
    try {
      const { element, latest } = await render('build-current');
      expect(dialog.contains(element)).toBe(true);
      (element.querySelector('button') as HTMLButtonElement).click();
      expect(latest).toHaveBeenCalledExactlyOnceWith('build-current');
      dialog.open = false; dialog.dispatchEvent(new Event('toggle'));
      expect(dialog.contains(element)).toBe(false);
      expect(element.isConnected).toBe(true);
    } finally { dialog.remove(); }
  });
  it('keeps a known update while connection feedback occupies the bottom notice, then restores the reload action', async () => {
    const { element, disconnected } = await render('build-current');
    disconnected.set(true); fixture!.detectChanges();
    expect(fixture!.componentInstance.available()).toBe(true);
    expect(element.querySelector('aside')).toBeNull();
    disconnected.set(false); fixture!.detectChanges();
    expect(element.querySelector('button')).not.toBeNull();
  });
});
