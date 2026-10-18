import { Component, inject, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { ActivatedRoute, provideRouter } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { Api } from '../../core/api';
import { UnsavedChanges } from '../../core/unsaved';
import { ExperimentDetail } from './experiment-detail';

const experiment = { id: 1, internalId: 1, name: 'Saved experiment', description: 'Saved description', projectId: 1, projectName: 'Project', systemId: 1, systemName: 'System', archived: false, resultId: 'system-1', defaultEnvironment: 'system-local', permissions: { canEdit: true } };
const document = { version: '1.0', elements: { 'system-1': { name: 'System results', all: [{ id: 'bar', type: 'bar-plot', name: 'Metric', parameter: 'metric' }], job: [] } } };
const runOptions = { environments: [{ key: 'system-local', name: 'Local', type: 'system' }, { key: 'cem-fixture', name: 'Fixture CEM', type: 'cem' }], defaultEnvironment: 'system-local', versions: [{ parameter: 'version', label: 'Software', values: ['main'] }] };

@Component({ template: '<h1>{{ name() }}</h1>' }) class ScheduledEvaluation {
  readonly name = signal('');
  constructor() {
    const route = inject(ActivatedRoute); const api = inject(Api);
    void api.get<{ name: string }>(`/evaluations/${route.snapshot.paramMap.get('id')}`).then(evaluation => this.name.set(evaluation.name));
  }
}

describe('EXPERIMENT-05/08 actual host and nested editor draft coordination', () => {
  const originalShow = Object.getOwnPropertyDescriptor(HTMLDialogElement.prototype, 'showModal');
  const originalClose = Object.getOwnPropertyDescriptor(HTMLDialogElement.prototype, 'close');
  let api: { get: ReturnType<typeof vi.fn>; patch: ReturnType<typeof vi.fn>; post: ReturnType<typeof vi.fn> };
  let stored: Record<string, unknown>;
  beforeEach(() => {
    stored = {};
    Object.defineProperty(HTMLDialogElement.prototype, 'showModal', { configurable: true, value: function(this: HTMLDialogElement) { this.setAttribute('open', ''); } });
    Object.defineProperty(HTMLDialogElement.prototype, 'close', { configurable: true, value: function(this: HTMLDialogElement) { this.removeAttribute('open'); } });
    api = {
      get: vi.fn(async (path: string) => {
        if (path === '/experiments/1') return structuredClone(experiment);
        if (path === '/experiments/1/run-options') return { environments: [{ key: 'system-local', name: 'Local', type: 'system' }, { key: 'cem-fixture', name: 'Fixture CEM', type: 'cem' }], defaultEnvironment: 'system-local', versions: [] };
        if (path === '/experiments/1/result-configurations') return structuredClone(document);
        if (path === '/evaluations/17') return stored;
        if (path.endsWith('/evaluations') || path.endsWith('/events')) return { items: [], total: 0, page: 1, pageSize: 5 };
        throw new Error(`Unexpected unit-test request: ${path}`);
      }),
      patch: vi.fn(async (_path: string, body: object) => ({ ...experiment, ...body })),
      post: vi.fn(async (_path: string, body: object) => { stored = { id: 17, ...structuredClone(body) }; return stored; }),
    };
    TestBed.configureTestingModule({ providers: [provideRouter([{ path: 'experiments/:id', component: ExperimentDetail }, { path: 'evaluations/:id', component: ScheduledEvaluation }]), { provide: Api, useValue: api }] });
  });
  afterEach(() => { vi.restoreAllMocks(); if (originalShow) Object.defineProperty(HTMLDialogElement.prototype, 'showModal', originalShow); else delete (HTMLDialogElement.prototype as unknown as Record<string, unknown>)['showModal']; if (originalClose) Object.defineProperty(HTMLDialogElement.prototype, 'close', originalClose); else delete (HTMLDialogElement.prototype as unknown as Record<string, unknown>)['close']; });
  async function setup() {
    const harness = await RouterTestingHarness.create('/experiments/1');
    await harness.fixture.whenStable(); harness.detectChanges();
    return harness;
  }
  function button(harness: RouterTestingHarness, name: string): HTMLButtonElement {
    const element = [...harness.routeNativeElement!.querySelectorAll('button')].find(item => item.textContent?.trim() === name);
    if (!element) throw new Error(`Button not found: ${name}`);
    return element;
  }
  it('preserves a real nested configuration draft and requires explicit save/discard before Run', async () => {
    const harness = await setup(); button(harness, 'Copy to this experiment').click(); await harness.fixture.whenStable(); harness.detectChanges();
    expect(TestBed.inject(UnsavedChanges).dirty()).toBe(true);
    button(harness, 'Run evaluation').click(); await harness.fixture.whenStable(); harness.detectChanges();
    expect(harness.routeNativeElement!.textContent).toContain('Save or discard changes in Result configurations');
    expect((harness.routeNativeElement!.querySelector('dialog') as HTMLDialogElement).open).toBe(false);
    expect(api.get.mock.calls.filter(([path]) => String(path).endsWith('/run-options'))).toHaveLength(1);
    expect(TestBed.inject(UnsavedChanges).dirty()).toBe(true);
    expect(button(harness, 'Save experiment configurations').disabled).toBe(false);
  });
  it('saving metadata cannot clear the unsaved warning for nested results', async () => {
    const harness = await setup(); const name = harness.routeNativeElement!.querySelector<HTMLInputElement>('#experiment-name')!;
    name.value = 'Edited metadata'; name.dispatchEvent(new Event('input', { bubbles: true })); await harness.fixture.whenStable();
    button(harness, 'Copy to this experiment').click(); await harness.fixture.whenStable(); harness.detectChanges();
    button(harness, 'Save experiment').click(); await harness.fixture.whenStable(); harness.detectChanges();
    expect(api.patch).toHaveBeenCalledWith('/experiments/1', { name: 'Edited metadata', description: 'Saved description' });
    expect(TestBed.inject(UnsavedChanges).dirty()).toBe(true);
    button(harness, 'Discard configuration changes').click(); await harness.fixture.whenStable(); harness.detectChanges();
    expect(TestBed.inject(UnsavedChanges).dirty()).toBe(false);
  });
  it('cancelled metadata-discard confirmation leaves the form and dirty warning intact', async () => {
    const harness = await setup(); const name = harness.routeNativeElement!.querySelector<HTMLInputElement>('#experiment-name')!;
    name.value = 'Keep this draft'; name.dispatchEvent(new Event('input', { bubbles: true })); await harness.fixture.whenStable();
    vi.spyOn(window, 'confirm').mockReturnValue(false); button(harness, 'Run evaluation').click(); await harness.fixture.whenStable(); harness.detectChanges();
    expect(name.value).toBe('Keep this draft'); expect(TestBed.inject(UnsavedChanges).dirty()).toBe(true);
    expect(api.get.mock.calls.filter(([path]) => String(path).endsWith('/run-options'))).toHaveLength(1);
  });
  it('EXPERIMENT-04 persists the selected default environment with metadata', async () => {
    const harness = await setup(); const select = harness.routeNativeElement!.querySelector<HTMLSelectElement>('#experiment-environment')!;
    select.value = 'cem-fixture'; select.dispatchEvent(new Event('change', { bubbles: true })); await harness.fixture.whenStable(); harness.detectChanges();
    button(harness, 'Save experiment').click(); await harness.fixture.whenStable(); harness.detectChanges();
    expect(api.patch).toHaveBeenCalledWith('/experiments/1', { name: 'Saved experiment', description: 'Saved description', defaultEnvironment: 'cem-fixture' });
    expect(TestBed.inject(UnsavedChanges).dirty()).toBe(false);
  });
  it('keeps current evaluations visible when an older archived-filter response arrives last', async () => {
    const harness = await setup(), component = harness.routeDebugElement!.componentInstance as ExperimentDetail;
    let finishArchived!: (value: object) => void, finishCurrent!: (value: object) => void;
    api.get.mockImplementation((_path: string, query?: Record<string, unknown>) => new Promise(resolve => { if (query?.['archived'] === 'true') finishArchived = resolve; else finishCurrent = resolve; }));
    component.archived = 'true'; const archived = component.loadEvaluations();
    component.archived = 'false'; const current = component.loadEvaluations();
    finishCurrent({ items: [{ id: 1, internalId: 1, name: 'Current evaluation', description: '', archived: false }], total: 1, page: 1, pageSize: 5 }); await current;
    finishArchived({ items: [{ id: 2, internalId: 2, name: 'Archived evaluation', description: '', archived: true }], total: 1, page: 1, pageSize: 5 }); await archived; harness.detectChanges();
    expect(component.archived).toBe('false'); expect(component.evaluations().map(row => row.name)).toEqual(['Current evaluation']);
    expect(harness.routeNativeElement!.textContent).toContain('Current evaluation');
  });

  it('preserves immediate name and version edits after delayed run options, then reads back the scheduled name', async () => {
    const harness = await setup(); const component = harness.routeDebugElement!.componentInstance as ExperimentDetail;
    let release!: (value: typeof runOptions) => void; const delayed = new Promise<typeof runOptions>(resolve => { release = resolve; });
    const get = api.get.getMockImplementation() as (path: string) => Promise<unknown>; api.get.mockImplementation((path: string) => path === '/experiments/1/run-options' ? delayed : get(path));
    const dialog = harness.routeNativeElement!.querySelector<HTMLDialogElement>('dialog')!; const fieldset = dialog.querySelector<HTMLFieldSetElement>('fieldset')!;
    const name = dialog.querySelector<HTMLInputElement>('#run-name')!; let disabledWhenShown: boolean | undefined; let initialName: string | undefined;
    vi.spyOn(dialog, 'showModal').mockImplementation(() => { disabledWhenShown = fieldset.disabled; initialName = name.value; dialog.setAttribute('open', ''); });
    const observer = new MutationObserver(() => {
      if (fieldset.disabled || !dialog.open) return;
      name.value = 'V3 scheduled evaluation'; name.dispatchEvent(new Event('input', { bubbles: true }));
      const version = dialog.querySelector<HTMLInputElement>('#run-version-version-0')!;
      version.value = 'immediate-version'; version.dispatchEvent(new Event('input', { bubbles: true }));
    });
    observer.observe(fieldset, { attributes: true, attributeFilter: ['disabled'] });
    try {
      const opening = component.openRun(); await Promise.resolve();
      expect(dialog.open).toBe(true); expect(disabledWhenShown).toBe(true); expect(initialName).toMatch(/^Evaluation \d{2}\.\d{2}\.\d{4}/); expect(name.matches(':disabled')).toBe(true);
      dialog.querySelector('form')!.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })); expect(api.post).not.toHaveBeenCalled();
      release(structuredClone(runOptions)); await opening; await Promise.resolve();
      expect(name.matches(':disabled')).toBe(false); expect(name.value).toBe('V3 scheduled evaluation');
      expect(dialog.querySelector<HTMLInputElement>('#run-version-version-0')!.value).toBe('immediate-version');
      const requestCount = api.get.mock.calls.length; await component.openRun();
      expect(api.get.mock.calls).toHaveLength(requestCount); expect(name.value).toBe('V3 scheduled evaluation');
      observer.disconnect(); dialog.querySelector('form')!.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
      await harness.fixture.whenStable(); harness.detectChanges();
      expect(api.post).toHaveBeenCalledWith('/experiments/1/evaluations', { name: 'V3 scheduled evaluation', description: '', environment: 'system-local', versions: { version: ['immediate-version'] } });
      expect(harness.routeNativeElement!.querySelector('h1')!.textContent).toBe('V3 scheduled evaluation');
    } finally { observer.disconnect(); }
  });

  it('does not enable or submit a run whose option request failed', async () => {
    const harness = await setup(); const component = harness.routeDebugElement!.componentInstance as ExperimentDetail;
    api.get.mockRejectedValueOnce(new Error('Run options unavailable'));
    await component.openRun(); harness.detectChanges();
    expect(harness.routeNativeElement!.querySelector('dialog')!.textContent).toContain('Run options unavailable');
    expect(harness.routeNativeElement!.querySelector<HTMLInputElement>('#run-name')!.matches(':disabled')).toBe(true);
    await component.run(); expect(api.post).not.toHaveBeenCalled();
  });

});
