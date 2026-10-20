import { ComponentFixture, TestBed } from '@angular/core/testing';
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

  async function render(serverId: string | null, fail = false, siteName?: string) {
    let push!: (data: unknown) => void;
    const close = vi.fn();
    const watch = vi.fn((_path, _query, apply) => { push = apply; if (!fail) queueMicrotask(() => apply({ uiBuildId: serverId, siteName })); return { ready: Promise.resolve(), close }; });
    const latest = vi.fn();
    TestBed.configureTestingModule({ providers: [
      { provide: Realtime, useValue: { watch } }, { provide: UI_BUILD_ID, useValue: 'build-previous' },
      { provide: Session, useValue: { load: vi.fn().mockResolvedValue(undefined) } },
      { provide: ReloadPage, useValue: { latest } },
    ] });
    fixture = TestBed.createComponent(VersionNotice);
    await fixture.whenStable(); fixture.detectChanges();
    return { watch, push, close, latest, element: fixture.nativeElement as HTMLElement };
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
});
