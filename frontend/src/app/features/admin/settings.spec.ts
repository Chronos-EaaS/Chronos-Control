import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { SettingSection, StorageStatus } from '../../core/admin-models';
import { AdminDrafts } from './admin-drafts';
import { AdminSettings } from './settings';

const section: SettingSection = { name: 'other', revision: 'fixture-revision', settings: [
  { key: 'rowsPerPage', value: '20', secret: false, configured: true },
] };
const storage: StorageStatus = { path: '/fixture/data', exists: true, readable: true, writable: true,
  mounted: null, freeBytes: 1024, totalBytes: 2048, checkedAt: '2026-10-20T10:00:00+02:00' };
const environment = { name: 'fixture', nodeCount: 0 };

describe('administration settings readiness', () => {
  let http: HttpTestingController;
  beforeEach(() => {
    TestBed.configureTestingModule({ imports: [AdminSettings], providers: [
      provideHttpClient(), provideHttpClientTesting(), provideRouter([]), AdminDrafts,
    ] });
    http = TestBed.inject(HttpTestingController);
  });
  afterEach(() => { http.verify(); vi.restoreAllMocks(); TestBed.resetTestingModule(); });

  it('keeps initial controls inert until the complete layout is ready, then adds an environment exactly once', async () => {
    const fixture = TestBed.createComponent(AdminSettings), component = fixture.componentInstance;
    fixture.detectChanges();
    http.expectOne('/api/ui/admin/settings').flush({ data: { sections: [section] } });
    http.expectOne('/api/ui/admin/environments').flush({ data: { items: [environment] } });
    const initialStorage = http.expectOne('/api/ui/admin/storage');
    await Promise.resolve(); fixture.detectChanges();
    const element = fixture.nativeElement as HTMLElement;
    const input = element.querySelector<HTMLInputElement>('#new-environment')!;
    const add = element.querySelector<HTMLButtonElement>('form button[type="submit"]')!;
    const refresh = element.querySelector<HTMLButtonElement>('.card-header button')!;
    expect(component.loading()).toBe(true);
    expect(input.matches(':disabled')).toBe(true); expect(add.matches(':disabled')).toBe(true);
    expect(refresh.disabled).toBe(true); expect(input.value).toBe('');
    const confirm = vi.spyOn(window, 'confirm');
    component.environmentName = 'new-fixture';
    await component.addEnvironment(); await component.saveSection(section);
    await component.removeEnvironment(environment); await component.refreshStorage();
    expect(confirm).not.toHaveBeenCalled(); expect(component.saving()).toBe(false);
    http.expectNone(request => request.method !== 'GET');
    http.expectNone('/api/ui/admin/storage');

    component.environmentName = '';
    initialStorage.flush({ data: storage });
    await vi.waitFor(() => { fixture.detectChanges(); expect(component.loading()).toBe(false); });
    await fixture.whenStable(); fixture.detectChanges();
    expect(component.loading()).toBe(false); expect(input.matches(':disabled')).toBe(false);
    expect(refresh.disabled).toBe(false); expect(element.textContent).toContain('/fixture/data');
    expect(element.querySelector<HTMLInputElement>('#setting-other-rowsPerPage')!.value).toBe('20');
    input.value = 'new-fixture'; input.dispatchEvent(new Event('input', { bubbles: true }));
    await fixture.whenStable(); fixture.detectChanges();
    expect(add.matches(':disabled')).toBe(false);
    input.form!.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })); fixture.detectChanges();
    const added = http.expectOne('/api/ui/admin/environments');
    expect(added.request.method).toBe('POST'); expect(added.request.body).toEqual({ name: 'new-fixture' });
    expect(input.matches(':disabled')).toBe(true); expect(refresh.disabled).toBe(true);
    await component.addEnvironment(); await component.saveSection(section);
    await component.removeEnvironment(environment); await component.refreshStorage();
    expect(confirm).not.toHaveBeenCalled(); http.expectNone(request => request.method !== 'GET');
    http.expectNone('/api/ui/admin/storage');
    added.flush({ data: { items: [environment, { name: 'new-fixture', nodeCount: 0 }] } });
    await vi.waitFor(() => { fixture.detectChanges(); expect(component.saving()).toBe(false); });
    await fixture.whenStable(); fixture.detectChanges();
    expect(element.textContent).toContain('The CEM environment was added.');
    expect([...element.querySelectorAll('tbody th')].map(header => header.textContent?.trim())).toEqual(['fixture', 'new-fixture']);
    expect(input.value).toBe(''); expect(input.matches(':disabled')).toBe(false);
    expect(element.querySelector<HTMLButtonElement>('tbody button')!.disabled).toBe(false);
  });

  it('does not start another storage request while its manual refresh is pending', async () => {
    const fixture = TestBed.createComponent(AdminSettings), component = fixture.componentInstance;
    fixture.detectChanges();
    http.expectOne('/api/ui/admin/settings').flush({ data: { sections: [section] } });
    http.expectOne('/api/ui/admin/environments').flush({ data: { items: [environment] } });
    http.expectOne('/api/ui/admin/storage').flush({ data: storage });
    await vi.waitFor(() => { fixture.detectChanges(); expect(component.loading()).toBe(false); });
    const first = component.refreshStorage(), request = http.expectOne('/api/ui/admin/storage');
    fixture.detectChanges();
    expect((fixture.nativeElement as HTMLElement).querySelector<HTMLButtonElement>('.card-header button')!.disabled).toBe(true);
    await component.refreshStorage(); http.expectNone('/api/ui/admin/storage');
    request.flush({ data: { ...storage, freeBytes: 0 } }); await first; fixture.detectChanges();
    expect(component.storage()?.freeBytes).toBe(0); expect(component.storageBusy()).toBe(false);
  });
});
