import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { Api } from '../../core/api';
import { UnsavedChanges } from '../../core/unsaved';
import { ExperimentDetail } from './experiment-detail';

const experiment = { id: 1, internalId: 1, name: 'Saved experiment', description: 'Saved description', projectId: 1, projectName: 'Project', systemId: 1, systemName: 'System', archived: false, resultId: 'system-1', defaultEnvironment: 'system-local', permissions: { canEdit: true } };
const document = { version: '1.0', elements: { 'system-1': { name: 'System results', all: [{ id: 'bar', type: 'bar-plot', name: 'Metric', parameter: 'metric' }], job: [] } } };

describe('EXPERIMENT-05/08 actual host and nested editor draft coordination', () => {
  let api: { get: ReturnType<typeof vi.fn>; patch: ReturnType<typeof vi.fn> };
  beforeEach(() => {
    api = {
      get: vi.fn(async (path: string) => {
        if (path === '/experiments/1') return structuredClone(experiment);
        if (path === '/experiments/1/run-options') return { environments: [{ key: 'system-local', name: 'Local', type: 'system' }, { key: 'cem-fixture', name: 'Fixture CEM', type: 'cem' }], defaultEnvironment: 'system-local', versions: [] };
        if (path === '/experiments/1/result-configurations') return structuredClone(document);
        if (path.endsWith('/evaluations') || path.endsWith('/events')) return { items: [], total: 0, page: 1, pageSize: 5 };
        throw new Error(`Unexpected unit-test request: ${path}`);
      }),
      patch: vi.fn(async (_path: string, body: object) => ({ ...experiment, ...body })),
    };
    TestBed.configureTestingModule({ providers: [provideRouter([{ path: 'experiments/:id', component: ExperimentDetail }]), { provide: Api, useValue: api }] });
  });
  afterEach(() => vi.restoreAllMocks());
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

});
