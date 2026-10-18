import { ComponentFixture, TestBed } from '@angular/core/testing';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { Api } from '../core/api';
import { ReloadPage } from '../core/reload';
import { UnsavedChanges } from '../core/unsaved';
import { UI_BUILD_ID, VersionNotice } from './version-notice';

describe('stale UI version notice', () => {
  let fixture: ComponentFixture<VersionNotice> | undefined;
  const initialTitle = document.title;
  afterEach(() => { fixture?.destroy(); fixture = undefined; document.title = initialTitle; vi.restoreAllMocks(); });

  async function render(serverId: string | null, fail = false, siteName?: string) {
    const get = fail ? vi.fn().mockRejectedValue(new Error('offline')) : vi.fn().mockResolvedValue({ uiBuildId: serverId, siteName });
    const latest = vi.fn();
    TestBed.configureTestingModule({ providers: [
      { provide: Api, useValue: { get } }, { provide: UI_BUILD_ID, useValue: 'build-previous' },
      { provide: ReloadPage, useValue: { latest } },
    ] });
    fixture = TestBed.createComponent(VersionNotice);
    await fixture.whenStable(); fixture.detectChanges();
    return { get, latest, element: fixture.nativeElement as HTMLElement };
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
  it('rechecks when the tab becomes visible again', async () => {
    const { get } = await render('build-previous');
    vi.spyOn(document, 'visibilityState', 'get').mockReturnValue('visible');
    document.dispatchEvent(new Event('visibilitychange'));
    await fixture!.whenStable();
    expect(get).toHaveBeenCalledTimes(2);
  });
});
