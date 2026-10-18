import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { By } from '@angular/platform-browser';
import { provideRouter } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { Api } from '../../core/api';
import { ExperimentCreate } from './experiment-create';
import { ExperimentParameters } from '../parameters/experiment-parameters';

const schema = () => ({ project: { id: 7, name: 'Synthetic project', systemId: 3 }, values: {}, maxJobs: 20, defaults: { runs: 1, distribution: 'alter', warmUp: true, environment: 'system-local' }, groups: [{ id: 'main', title: 'Prepared defaults', depends: '', dependsValue: '', elements: [
  { id: 'quantity', type: 'custom-integer', name: 'Quantity', parameter: 'quantity', default: 0 },
  { id: 'enabled', type: 'custom-boolean', name: 'Enabled', parameter: 'enabled', default: false },
  { id: 'total', type: 'integer', name: 'Total', parameter: 'total', default: 80 },
  { id: 'share', type: 'percentage', name: 'Share', parameter: 'share', dependency: 'total', default: 25, 'parameter-type': 'int' },
  { id: 'portion', type: 'percentage-interval', name: 'Portion', parameter: 'portion', dependency: 'total', 'default-start': 0, 'default-end': 100, 'default-step': 50, 'parameter-type': 'int' },
] }] });

describe('EXTENSION-01 actual ExperimentCreate preset integration', () => {
  let http: HttpTestingController;
  beforeEach(() => {
    TestBed.configureTestingModule({ providers: [provideHttpClient(), provideHttpClientTesting(), provideRouter([{ path: 'projects/:id/experiments/new', component: ExperimentCreate }])] });
    http = TestBed.inject(HttpTestingController); TestBed.inject(Api).csrfToken.set('synthetic-csrf-token');
  });
  afterEach(() => { http.verify(); TestBed.resetTestingModule(); });
  async function setup(options: unknown = schema()) {
    const harness = await RouterTestingHarness.create(); const navigating = harness.navigateByUrl('/projects/7/experiments/new', ExperimentCreate);
    await vi.waitFor(() => http.expectOne('/api/ui/projects/7/experiment-options').flush({ data: options }));
    await vi.waitFor(() => http.expectOne('/api/ui/systems/3/extensions').flush({ data: { aliases: { parameters: { 'custom-integer': 'integer', 'custom-boolean': 'boolean' } } } }));
    const component = await navigating; harness.detectChanges();
    await vi.waitFor(() => http.expectOne('/api/ui/projects/7/parameter-presets').flush({ data: { items: [], schemaHash: 'synthetic-schema-hash' } }));
    await harness.fixture.whenStable(); harness.detectChanges(); return { harness, component };
  }
  async function savePreset(harness: RouterTestingHarness) {
    const name = harness.routeNativeElement!.querySelector<HTMLInputElement>('#preset-name')!; name.value = 'Displayed defaults'; name.dispatchEvent(new Event('input', { bubbles: true })); await harness.fixture.whenStable(); harness.detectChanges();
    const button = [...harness.routeNativeElement!.querySelectorAll('button')].find(button => button.textContent?.trim() === 'Save as new preset')!; expect(button.disabled).toBe(false); button.click();
    const request = http.expectOne('/api/ui/projects/7/parameter-presets'); expect(request.request.method).toBe('POST'); expect(request.request.headers.get('X-CSRF-Token')).toBe('synthetic-csrf-token');
    const body = request.request.body; request.flush({ data: { ...body, id: 'synthetic-preset', revision: 'revision-1', compatible: true, updatedAt: '2026-01-15T12:00:00+01:00' } }); await harness.fixture.whenStable(); harness.detectChanges(); return body;
  }
  it('saves displayed zero/false alias defaults and derived fields before any parameter edit', async () => {
    const { harness, component } = await setup(); const root = harness.routeNativeElement!;
    expect(component.values()).toEqual({}); expect(component.valid()).toBe(true); expect(root.querySelector<HTMLInputElement>('input[name="quantity"]')!.value).toBe('0'); expect(root.querySelector<HTMLSelectElement>('select[name="enabled"]')!.value).toBe('false'); expect(root.querySelector<HTMLInputElement>('input[name="share"]')!.value).toBe('20');
    expect(root.querySelector('input[name$="-numberOfVersions"]')).toBeNull();
    const body = await savePreset(harness);
    expect(body).toEqual({ name: 'Displayed defaults', schemaHash: 'synthetic-schema-hash', values: { quantity: 0, enabled: false, total: 80, 'share-percentage': 25, share: 20, 'portion-percentage-start': 0, 'portion-percentage-end': 100, 'portion-percentage-step': 50, 'portion-start': 0, 'portion-end': 80, 'portion-step': 40 } });
    expect(component.values()).toEqual({}); expect(root.textContent).toContain('Parameter preset saved.');
  });
  it('saves the current derived draft after a real source input event', async () => {
    const { harness } = await setup(); const input = harness.routeNativeElement!.querySelector<HTMLInputElement>('input[name="total"]')!; input.value = '200'; input.dispatchEvent(new Event('input', { bubbles: true })); await harness.fixture.whenStable(); harness.detectChanges();
    expect(harness.routeNativeElement!.querySelector<HTMLInputElement>('input[name="share"]')!.value).toBe('50');
    expect((await savePreset(harness)).values).toMatchObject({ quantity: 0, enabled: false, total: '200', share: 50, 'portion-end': 200, 'portion-step': 100 });
  });
  it('updates run/distribution/unchecked warm-up dependent groups without merging parent fields into parameter drafts', async () => {
    const groups = [
      { id: 'repeat-group', title: 'Repeated-run details', depends: 'runs', dependsValue: '2', elements: [{ id: 'repeat-detail', type: 'integer', name: 'Repeat detail', parameter: 'repeatDetail', default: 0 }] },
      { id: 'order-group', title: 'Sequential details', depends: 'run-distribution', dependsValue: 'order', elements: [{ id: 'order-detail', type: 'integer', name: 'Order detail', parameter: 'orderDetail', default: 0 }] },
      { id: 'cold-group', title: 'No warm-up details', depends: 'phase_warmUp', dependsValue: '0', elements: [{ id: 'cold-detail', type: 'integer', name: 'Cold detail', parameter: 'coldDetail', default: 0 }] },
    ];
    const { harness, component } = await setup({ ...schema(), groups }); const root = harness.routeNativeElement!;
    expect(root.querySelector('input[name="repeatDetail"]')).toBeNull();
    const runs = root.querySelector<HTMLInputElement>('#experiment-runs')!; runs.value = '2'; runs.dispatchEvent(new Event('input', { bubbles: true })); await harness.fixture.whenStable(); harness.detectChanges();
    expect(root.querySelector('input[name="repeatDetail"]')).not.toBeNull();
    const distribution = root.querySelector<HTMLSelectElement>('#experiment-distribution')!; distribution.value = 'order'; distribution.dispatchEvent(new Event('change', { bubbles: true }));
    const warmUp = root.querySelector<HTMLInputElement>('#experiment-warm-up')!; warmUp.checked = false; warmUp.dispatchEvent(new Event('change', { bubbles: true })); await harness.fixture.whenStable(); harness.detectChanges();
    expect(root.querySelector('input[name="orderDetail"]')).not.toBeNull(); expect(root.querySelector('input[name="coldDetail"]')).not.toBeNull();
    const parameters = harness.routeDebugElement!.query(By.directive(ExperimentParameters)).componentInstance as ExperimentParameters;
    expect(parameters.submissionValues()['elements']).toEqual(['repeat-detail', 'order-detail', 'cold-detail']);
    for (const key of ['runs', 'run-distribution', 'phase_warmUp']) { expect(parameters.draft()).not.toHaveProperty(key); expect(component.values()).not.toHaveProperty(key); expect(parameters.submissionValues()).not.toHaveProperty(key); }
    runs.value = '1'; runs.dispatchEvent(new Event('input', { bubbles: true })); distribution.value = 'alter'; distribution.dispatchEvent(new Event('change', { bubbles: true })); warmUp.checked = true; warmUp.dispatchEvent(new Event('change', { bubbles: true })); await harness.fixture.whenStable(); harness.detectChanges();
    expect(root.querySelector('input[name="repeatDetail"]')).toBeNull(); expect(root.querySelector('input[name="orderDetail"]')).toBeNull(); expect(root.querySelector('input[name="coldDetail"]')).toBeNull();
    expect(parameters.submissionValues()['elements']).toEqual([]);
  });

  async function pendingCopy(id = 11) {
    const harness = await RouterTestingHarness.create();
    const component = await harness.navigateByUrl(`/projects/7/experiments/new?copy=${id}`, ExperimentCreate);
    await vi.waitFor(() => http.expectOne(`/api/ui/projects/7/experiment-options?copy=${id}`).flush({ data: schema() }));
    await vi.waitFor(() => http.expectOne('/api/ui/systems/3/extensions').flush({ data: { aliases: { parameters: { 'custom-integer': 'integer', 'custom-boolean': 'boolean' } } } }));
    const source = await vi.waitFor(() => http.expectOne(`/api/ui/experiments/${id}`));
    harness.detectChanges(); return { harness, component, source };
  }
  async function finishCopy(harness: RouterTestingHarness) {
    await vi.waitFor(() => http.expectOne('/api/ui/projects/7/parameter-presets').flush({ data: { items: [], schemaHash: 'synthetic-schema-hash' } }));
    await harness.fixture.whenStable(); harness.detectChanges();
  }
  async function editName(harness: RouterTestingHarness, value: string) {
    const input = harness.routeNativeElement!.querySelector<HTMLInputElement>('#experiment-name')!;
    input.value = value; input.dispatchEvent(new Event('input', { bubbles: true }));
    await harness.fixture.whenStable(); harness.detectChanges();
  }
  it('withholds the complete copy form until delayed metadata finishes, then retains native edits and dirty state', async () => {
    const { harness, component, source } = await pendingCopy();
    expect(component.busy()).toBe(true); expect(component.schema()).toBeNull();
    expect(harness.routeNativeElement!.querySelector('form')).toBeNull();
    await component.create(); http.expectNone(request => request.method === 'POST');
    source.flush({ data: { id: 11, name: 'Copied Zürich', description: 'Saved source description' } });
    await finishCopy(harness);
    expect(component.busy()).toBe(false);
    expect(harness.routeNativeElement!.querySelector<HTMLInputElement>('#experiment-name')!.value).toBe('Copied Zürich (copy)');
    expect(harness.routeNativeElement!.querySelector<HTMLTextAreaElement>('#experiment-description')!.value).toBe('Saved source description');
    expect(component.changes.dirty()).toBe(false);
    await editName(harness, 'My edited copy');
    const quantity = harness.routeNativeElement!.querySelector<HTMLInputElement>('input[name="quantity"]')!;
    quantity.value = '50'; quantity.dispatchEvent(new Event('input', { bubbles: true }));
    await harness.fixture.whenStable(); harness.detectChanges();
    expect(component.name).toBe('My edited copy'); expect(component.values()['quantity']).toBe('50');
    expect(component.changes.dirty()).toBe(true);
  });
  it('keeps a failed source copy unavailable instead of exposing a partial editable form', async () => {
    const { harness, component, source } = await pendingCopy();
    source.flush({ error: { message: 'The source experiment is unavailable.' } }, { status: 500, statusText: 'Server Error' });
    await vi.waitFor(() => expect(component.busy()).toBe(false));
    await harness.fixture.whenStable(); harness.detectChanges();
    expect(component.busy()).toBe(false); expect(component.schema()).toBeNull();
    expect(harness.routeNativeElement!.querySelector('form')).toBeNull();
    expect(harness.routeNativeElement!.textContent).toContain('The source experiment is unavailable.');
    http.expectNone('/api/ui/projects/7/parameter-presets');
  });
  it('ignores a superseded copy response when the same routed component receives a different copy query', async () => {
    const { harness, component, source: oldSource } = await pendingCopy();
    const reused = await harness.navigateByUrl('/projects/7/experiments/new?copy=22', ExperimentCreate);
    expect(reused).toBe(component);
    await vi.waitFor(() => http.expectOne('/api/ui/projects/7/experiment-options?copy=22').flush({ data: schema() }));
    await vi.waitFor(() => http.expectOne('/api/ui/systems/3/extensions').flush({ data: { aliases: { parameters: { 'custom-integer': 'integer', 'custom-boolean': 'boolean' } } } }));
    const currentSource = await vi.waitFor(() => http.expectOne('/api/ui/experiments/22'));
    currentSource.flush({ data: { id: 22, name: 'Current copy', description: 'Current source description' } });
    await finishCopy(harness); await editName(harness, 'Retained new draft');
    oldSource.flush({ data: { id: 11, name: 'Outdated source', description: 'Must not overwrite the new source' } });
    await harness.fixture.whenStable(); harness.detectChanges();
    expect(component.copyId).toBe(22); expect(component.name).toBe('Retained new draft');
    expect(component.description).toBe('Current source description');
    expect(component.changes.dirty()).toBe(true);
    expect(harness.routeNativeElement!.querySelector<HTMLInputElement>('#experiment-name')!.value).toBe('Retained new draft');
  });
});
