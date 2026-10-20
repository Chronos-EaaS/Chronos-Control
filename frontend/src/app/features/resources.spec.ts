import { Component, inject, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { ActivatedRoute, provideRouter } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { Api } from '../core/api';
import { Session } from '../core/session';
import { UnsavedChanges } from '../core/unsaved';
import { Resources } from './resources';

@Component({ template: '<h1>{{ name() }}</h1>' }) class CreatedResource {
  readonly name = signal('');
  constructor() {
    const route = inject(ActivatedRoute); const api = inject(Api);
    void api.get<{ name: string }>(`/${route.snapshot.url[0].path}/${route.snapshot.paramMap.get('id')}`).then(resource => this.name.set(resource.name));
  }
}

describe('SYSTEM-02/04 actual create-system dialog and repository credentials', () => {
  const originalShow = Object.getOwnPropertyDescriptor(HTMLDialogElement.prototype, 'showModal');
  const originalClose = Object.getOwnPropertyDescriptor(HTMLDialogElement.prototype, 'close');
  let enabled = true;
  let stored: Record<string, unknown>;
  let api: { get: ReturnType<typeof vi.fn>; post: ReturnType<typeof vi.fn> };
  beforeEach(() => {
    enabled = true; stored = {};
    // JSDOM has no native modal implementation; browser tests cover focus and modality.
    Object.defineProperty(HTMLDialogElement.prototype, 'showModal', { configurable: true, value: function(this: HTMLDialogElement) { this.setAttribute('open', ''); } });
    Object.defineProperty(HTMLDialogElement.prototype, 'close', { configurable: true, value: function(this: HTMLDialogElement) { this.removeAttribute('open'); } });
    api = { get: vi.fn(async (path: string) => {
      if (path === '/meta') return { features: { remoteRepositories: enabled } };
      if (path === '/users') return { items: [{ id: 1, firstName: 'Fixture', lastName: 'Admin', username: 'admin' }], total: 1 };
      if (path === '/systems') return { items: [], total: 0, page: 1, pageSize: 5 };
      if (path === '/projects') return { items: [], total: 0, page: 1, pageSize: 5 };
      if (/^\/(systems|projects)\/3$/.test(path)) return stored;
      throw new Error(`Unexpected unit-test request ${path}`);
    }), post: vi.fn(async (_path: string, body: object) => { stored = { id: 3, ...structuredClone(body) }; return stored; }) };
    TestBed.configureTestingModule({ providers: [
      provideRouter([{ path: 'systems', component: Resources, data: { kind: 'systems' } }, { path: 'systems/:id', component: CreatedResource }, { path: 'projects', component: Resources, data: { kind: 'projects' } }, { path: 'projects/:id', component: CreatedResource }]),
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

  it.each([
    { kind: 'systems', view: 'cards' },
    { kind: 'systems', view: 'table' },
    { kind: 'projects', view: 'table' },
  ] as const)('keeps $kind $view creation unavailable until the initial list settles', async ({ kind, view }) => {
    let release!: (value: unknown) => void; let held = false;
    const initial = new Promise(resolve => { release = resolve; });
    const get = api.get.getMockImplementation() as (path: string) => Promise<unknown>;
    api.get.mockImplementation((path: string) => {
      if (path === `/${kind}` && !held) { held = true; return initial; }
      return get(path);
    });
    const harness = await RouterTestingHarness.create(`/${kind}`);
    const component = harness.routeDebugElement!.componentInstance as Resources;
    component.view = view; harness.detectChanges();
    const label = kind === 'systems' ? 'Add system' : 'Create project';
    const button = [...harness.routeNativeElement!.querySelectorAll('button')].find(button => button.textContent?.includes(label))!;
    const dialog = harness.routeNativeElement!.querySelector<HTMLDialogElement>('dialog')!;
    expect(component.busy()).toBe(true); expect(button.disabled).toBe(true);
    button.click(); await Promise.resolve(); harness.detectChanges();
    expect(dialog.open).toBe(false); expect(api.get).toHaveBeenCalledTimes(1);
    release({ items: [], total: 0, page: 1, pageSize: 5 });
    await harness.fixture.whenStable(); harness.detectChanges();
    expect(component.busy()).toBe(false); expect(button.disabled).toBe(false);
    button.click(); await harness.fixture.whenStable(); harness.detectChanges();
    expect(dialog.open).toBe(true); expect(component.optionsReady()).toBe(true);
    expect(api.get).toHaveBeenCalledWith('/users', { page: 1, pageSize: 100 });
  });

  it.each(['projects', 'systems'] as const)('keeps %s creation disabled through delayed options and preserves input at the first enabled render', async kind => {
    const harness = await RouterTestingHarness.create(`/${kind}`); await harness.fixture.whenStable(); harness.detectChanges();
    const component = harness.routeDebugElement!.componentInstance as Resources;
    let release!: (value: unknown) => void;
    const delayed = new Promise(resolve => { release = resolve; }); const get = api.get.getMockImplementation() as (path: string) => Promise<unknown>;
    api.get.mockImplementation((path: string) => path === (kind === 'systems' ? '/meta' : '/systems') ? delayed : get(path));
    const dialog = harness.routeNativeElement!.querySelector<HTMLDialogElement>('dialog')!;
    const fieldset = dialog.querySelector<HTMLFieldSetElement>('fieldset')!;
    const name = dialog.querySelector<HTMLInputElement>('#create-name')!;
    let disabledWhenShown: boolean | undefined; let initialName: string | undefined;
    vi.spyOn(dialog, 'showModal').mockImplementation(() => { disabledWhenShown = fieldset.disabled; initialName = name.value; dialog.setAttribute('open', ''); });
    const typed = `Immediate ${kind} Zürich`;
    // Observe the first actual usable DOM state, without waiting for fixture
    // stability before typing. This catches a queued default overwriting input.
    const observer = new MutationObserver(() => {
      if (fieldset.disabled || !dialog.open) return;
      name.value = typed; name.dispatchEvent(new Event('input', { bubbles: true }));
      if (kind === 'projects') { const select = dialog.querySelector<HTMLSelectElement>('#create-system')!; select.selectedIndex = 1; select.dispatchEvent(new Event('change', { bubbles: true })); }
    });
    observer.observe(fieldset, { attributes: true, attributeFilter: ['disabled'] });
    try {
      const opening = component.openCreate(); await Promise.resolve();
      expect(dialog.open).toBe(true); expect(disabledWhenShown).toBe(true); expect(initialName).toBe(''); expect(name.matches(':disabled')).toBe(true);
      dialog.querySelector('form')!.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })); expect(api.post).not.toHaveBeenCalled();
      release(kind === 'systems' ? { features: { remoteRepositories: true } } : { items: [{ id: 1, name: 'Fixture system' }], total: 1, page: 1, pageSize: 100 });
      await opening; await Promise.resolve();
      expect(name.matches(':disabled')).toBe(false); expect(name.value).toBe(typed); expect(component.name).toBe(typed);
      const requestCount = api.get.mock.calls.length; await component.openCreate();
      expect(api.get.mock.calls).toHaveLength(requestCount); expect(name.value).toBe(typed);
      observer.disconnect(); dialog.querySelector('form')!.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
      await harness.fixture.whenStable(); harness.detectChanges();
      expect(api.post).toHaveBeenCalledWith(`/${kind}`, kind === 'projects' ? { name: typed, description: '', systemId: 1, ownerId: 1 } : { name: typed, description: '', ownerId: 1, cem: false });
      expect(harness.routeNativeElement!.querySelector('h1')!.textContent).toBe(typed);
    } finally { observer.disconnect(); }
  });

  it('keeps failed option loading disabled and prevents a synthetic submission', async () => {
    const get = api.get.getMockImplementation() as (path: string) => Promise<unknown>;
    api.get.mockImplementation((path: string) => path === '/meta' ? Promise.reject(new Error('Options unavailable')) : get(path));
    const { harness, component } = await setup();
    expect(harness.routeNativeElement!.querySelector('dialog')!.textContent).toContain('Options unavailable');
    expect(harness.routeNativeElement!.querySelector<HTMLInputElement>('#create-name')!.matches(':disabled')).toBe(true);
    await component.create(); expect(api.post).not.toHaveBeenCalled();
  });

});
