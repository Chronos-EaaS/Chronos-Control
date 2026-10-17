import { Component, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { Api } from '../core/api';
import { Session } from '../core/session';
import { UnsavedChanges } from '../core/unsaved';
import { Resources } from './resources';

@Component({ template: 'Created system' }) class CreatedSystem {}

describe('SYSTEM-02/04 actual create-system dialog and repository credentials', () => {
  const originalShow = Object.getOwnPropertyDescriptor(HTMLDialogElement.prototype, 'showModal');
  const originalClose = Object.getOwnPropertyDescriptor(HTMLDialogElement.prototype, 'close');
  let enabled = true;
  let api: { get: ReturnType<typeof vi.fn>; post: ReturnType<typeof vi.fn> };
  beforeEach(() => {
    enabled = true;
    // JSDOM has no native modal implementation; browser tests cover focus and modality.
    Object.defineProperty(HTMLDialogElement.prototype, 'showModal', { configurable: true, value: function(this: HTMLDialogElement) { this.setAttribute('open', ''); } });
    Object.defineProperty(HTMLDialogElement.prototype, 'close', { configurable: true, value: function(this: HTMLDialogElement) { this.removeAttribute('open'); } });
    api = { get: vi.fn(async (path: string) => {
      if (path === '/meta') return { features: { remoteRepositories: enabled } };
      if (path === '/users') return { items: [{ id: 1, firstName: 'Fixture', lastName: 'Admin', username: 'admin' }], total: 1 };
      if (path === '/systems') return { items: [], total: 0, page: 1, pageSize: 5 };
      throw new Error(`Unexpected unit-test request ${path}`);
    }), post: vi.fn(async () => ({ id: 3 })) };
    TestBed.configureTestingModule({ providers: [
      provideRouter([{ path: 'systems', component: Resources, data: { kind: 'systems' } }, { path: 'systems/:id', component: CreatedSystem }]),
      { provide: Api, useValue: api }, { provide: Session, useValue: { isAdmin: () => true, user: signal({ id: 1 }) } },
    ] });
  });
  afterEach(() => { vi.restoreAllMocks(); if (originalShow) Object.defineProperty(HTMLDialogElement.prototype, 'showModal', originalShow); else delete (HTMLDialogElement.prototype as unknown as Record<string, unknown>)['showModal']; if (originalClose) Object.defineProperty(HTMLDialogElement.prototype, 'close', originalClose); else delete (HTMLDialogElement.prototype as unknown as Record<string, unknown>)['close']; });
  async function setup(url = '/systems') {
    const harness = await RouterTestingHarness.create(); const component = await harness.navigateByUrl(url, Resources);
    await harness.fixture.whenStable(); harness.detectChanges();
    const open = [...harness.routeNativeElement!.querySelectorAll('button')].find(button => button.textContent?.includes('Add system'))!;
    if (!harness.routeNativeElement!.querySelector<HTMLDialogElement>('dialog')!.open) open.click(); await harness.fixture.whenStable(); harness.detectChanges();
    return { harness, component };
  }
  async function text(harness: RouterTestingHarness, id: string, value: string) {
    const input = harness.routeNativeElement!.querySelector<HTMLInputElement>(`#${id}`)!;
    input.value = value; input.dispatchEvent(new Event('input', { bubbles: true })); await harness.fixture.whenStable(); harness.detectChanges();
  }
  async function remote(harness: RouterTestingHarness) {
    harness.routeNativeElement!.querySelector<HTMLInputElement>('#create-remote')!.click(); await harness.fixture.whenStable(); harness.detectChanges();
  }
  async function submit(harness: RouterTestingHarness) {
    const form = harness.routeNativeElement!.querySelector('dialog form')!;
    form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })); await harness.fixture.whenStable(); harness.detectChanges();
  }
  it('omits repository data completely for ordinary local creation', async () => {
    const { harness } = await setup(); await text(harness, 'create-name', 'Local system'); await submit(harness);
    expect(api.post).toHaveBeenCalledWith('/systems', { name: 'Local system', description: '', ownerId: 1, cem: false });
  });
  it('submits the selected Mercurial repository and separate masked credentials, then clears them', async () => {
    const { harness, component } = await setup(); await text(harness, 'create-name', 'Remote system'); await remote(harness);
    const select = harness.routeNativeElement!.querySelector<HTMLSelectElement>('#create-repository-type')!;
    select.value = 'hg'; select.dispatchEvent(new Event('change', { bubbles: true })); await harness.fixture.whenStable();
    await text(harness, 'create-repository-url', 'ssh://git@example.test/owner/system'); await text(harness, 'create-repository-branch', 'stable');
    await text(harness, 'create-repository-username', 'fixture'); await text(harness, 'create-repository-password', 'Synthetic token');
    expect(harness.routeNativeElement!.querySelector<HTMLInputElement>('#create-repository-password')!.type).toBe('password');
    await submit(harness);
    expect(api.post).toHaveBeenCalledWith('/systems', { name: 'Remote system', description: '', ownerId: 1, cem: false, repository: { type: 'hg', url: 'ssh://git@example.test/owner/system', branch: 'stable', username: 'fixture', password: 'Synthetic token' } });
    expect(component.repositoryPassword).toBe(''); expect(TestBed.inject(UnsavedChanges).dirty()).toBe(false);
  });
  it('does not expose remote creation when the server capability is disabled', async () => {
    enabled = false; const { harness } = await setup(); expect(harness.routeNativeElement!.querySelector('#create-remote')).toBeNull();
  });
  it('requires explicit discard and clears a secret when the dialog closes', async () => {
    const { harness, component } = await setup(); await remote(harness); await text(harness, 'create-repository-password', 'Synthetic token');
    const confirmation = vi.spyOn(window, 'confirm').mockReturnValue(false); component.cancelDialog();
    expect(component.repositoryPassword).toBe('Synthetic token'); expect(harness.routeNativeElement!.querySelector<HTMLDialogElement>('dialog')!.open).toBe(true);
    confirmation.mockReturnValue(true); component.cancelDialog(); expect(component.repositoryPassword).toBe(''); expect(TestBed.inject(UnsavedChanges).dirty()).toBe(false);
  });
  it('honors legacy create=1 links by opening the fully populated create dialog', async () => {
    const { harness } = await setup('/systems?create=1');
    expect(harness.routeNativeElement!.querySelector<HTMLDialogElement>('dialog')!.open).toBe(true);
    expect(harness.routeNativeElement!.querySelector<HTMLInputElement>('#create-name')).not.toBeNull();
    expect(harness.routeNativeElement!.querySelector<HTMLSelectElement>('#create-owner')!.textContent).toContain('Fixture Admin');
    expect(api.get.mock.calls.filter(([path]) => path === '/meta')).toHaveLength(1);
  });

});
