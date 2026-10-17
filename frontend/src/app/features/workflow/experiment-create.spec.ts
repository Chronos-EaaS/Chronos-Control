import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { Api } from '../../core/api';
import { ExperimentCreate } from './experiment-create';

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
  async function setup() {
    const harness = await RouterTestingHarness.create(); const navigating = harness.navigateByUrl('/projects/7/experiments/new', ExperimentCreate);
    await vi.waitFor(() => http.expectOne('/api/v3/projects/7/experiment-options').flush({ data: schema() }));
    await vi.waitFor(() => http.expectOne('/api/v3/systems/3/extensions').flush({ data: { aliases: { parameters: { 'custom-integer': 'integer', 'custom-boolean': 'boolean' } } } }));
    const component = await navigating; harness.detectChanges();
    await vi.waitFor(() => http.expectOne('/api/v3/projects/7/parameter-presets').flush({ data: { items: [], schemaHash: 'synthetic-schema-hash' } }));
    await harness.fixture.whenStable(); harness.detectChanges(); return { harness, component };
  }
  async function savePreset(harness: RouterTestingHarness) {
    const name = harness.routeNativeElement!.querySelector<HTMLInputElement>('#preset-name')!; name.value = 'Displayed defaults'; name.dispatchEvent(new Event('input', { bubbles: true })); await harness.fixture.whenStable(); harness.detectChanges();
    const button = [...harness.routeNativeElement!.querySelectorAll('button')].find(button => button.textContent?.trim() === 'Save as new preset')!; expect(button.disabled).toBe(false); button.click();
    const request = http.expectOne('/api/v3/projects/7/parameter-presets'); expect(request.request.method).toBe('POST'); expect(request.request.headers.get('X-CSRF-Token')).toBe('synthetic-csrf-token');
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
});
