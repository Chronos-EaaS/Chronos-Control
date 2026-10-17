import { Location } from '@angular/common';
import { provideLocationMocks } from '@angular/common/testing';
import { Component } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { By } from '@angular/platform-browser';
import { NavigationCancel, NavigationEnd, Router, RouterOutlet, RouteReuseStrategy, provideRouter } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import { firstValueFrom, filter, take } from 'rxjs';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { mayEnterResource } from '../app.routes';
import { ExperimentDetail } from '../features/workflow/experiment-detail';
import { Api } from './api';
import { ResourceRouteReuseStrategy } from './resource-reuse';
import { UnsavedChanges } from './unsaved';

let layoutsCreated = 0;
@Component({ imports: [RouterOutlet], template: '<router-outlet />' })
class PersistentLayout { constructor() { ++layoutsCreated; } }

const permissions = { canEdit: true };
const record = (id: number) => ({ id, internalId: id, name: `Experiment ${id}`, description: 'Saved description', projectId: 1, projectName: 'Project', systemId: 1, systemName: 'System', archived: false, resultId: '', defaultEnvironment: 'system-local', permissions });

describe('SHELL-05 resource navigation guards with actual experiment editors', () => {
  let api: { get: ReturnType<typeof vi.fn>; patch: ReturnType<typeof vi.fn> };
  beforeEach(() => {
    layoutsCreated = 0;
    api = { get: vi.fn(async (path: string) => {
      const match = /^\/experiments\/([12])$/.exec(path); if (match) return record(Number(match[1]));
      if (path.endsWith('/run-options')) return { environments: [{ key: 'system-local', name: 'Local', type: 'system' }], versions: [] };
      if (path.endsWith('/result-configurations')) return { version: '1.0', elements: {} };
      if (path.endsWith('/evaluations') || path.endsWith('/events')) return { items: [], total: 0, page: 1, pageSize: 5 };
      throw new Error(`Unexpected unit-test request ${path}`);
    }), patch: vi.fn(async (path: string, body: object) => ({ ...record(Number(path.split('/').at(-1))), ...body })) };
    TestBed.configureTestingModule({ providers: [
      provideRouter([{ path: '', component: PersistentLayout, canActivateChild: [mayEnterResource], children: [{ path: 'experiments/:id', component: ExperimentDetail, runGuardsAndResolvers: 'paramsOrQueryParamsChange' }] }]),
      provideLocationMocks(), { provide: RouteReuseStrategy, useClass: ResourceRouteReuseStrategy }, { provide: Api, useValue: api },
    ] });
  });
  afterEach(() => vi.restoreAllMocks());
  async function setup() { TestBed.inject(Router).setUpLocationChangeListener(); const harness = await RouterTestingHarness.create('/experiments/1'); await harness.fixture.whenStable(); harness.detectChanges(); return harness; }
  function editor(harness: RouterTestingHarness): ExperimentDetail { return harness.fixture.debugElement.query(By.directive(ExperimentDetail)).componentInstance as ExperimentDetail; }
  async function draft(harness: RouterTestingHarness, value: string) {
    const input = harness.routeNativeElement!.querySelector<HTMLInputElement>('#experiment-name')!;
    input.value = value; input.dispatchEvent(new Event('input', { bubbles: true })); await harness.fixture.whenStable(); harness.detectChanges();
  }
  it('rejected ID navigation preserves the original editor and saves only to the original resource', async () => {
    const harness = await setup(); const original = editor(harness); await draft(harness, 'Keep this draft');
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false);
    await harness.navigateByUrl('/experiments/2'); await harness.fixture.whenStable(); harness.detectChanges();
    expect(TestBed.inject(Router).url).toBe('/experiments/1'); expect(editor(harness)).toBe(original); expect(original.name).toBe('Keep this draft'); expect(confirm).toHaveBeenCalledTimes(1);
    await original.save(); expect(api.patch).toHaveBeenCalledWith('/experiments/1', { name: 'Keep this draft', description: 'Saved description' });
    expect(api.get).not.toHaveBeenCalledWith('/experiments/2');
  });
  it('accepted ID navigation creates a fresh editor while the enclosing layout remains mounted', async () => {
    const harness = await setup(); const original = editor(harness); await draft(harness, 'Discard this draft'); vi.spyOn(window, 'confirm').mockReturnValue(true);
    await harness.navigateByUrl('/experiments/2'); await harness.fixture.whenStable(); harness.detectChanges();
    expect(editor(harness)).not.toBe(original); expect(editor(harness).experiment()?.id).toBe(2); expect(editor(harness).name).toBe('Experiment 2');
    expect(TestBed.inject(UnsavedChanges).dirty()).toBe(false); expect(layoutsCreated).toBe(1);
  });
  it('guards query changes and browser back navigation without applying a draft to another URL', async () => {
    const harness = await setup(); const router = TestBed.inject(Router); const location = TestBed.inject(Location);
    const original = editor(harness); await draft(harness, 'Query draft'); const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false);
    await harness.navigateByUrl('/experiments/1?tab=results'); expect(router.url).toBe('/experiments/1'); expect(editor(harness)).toBe(original);
    confirm.mockReturnValue(true); await harness.navigateByUrl('/experiments/1?tab=results'); await harness.fixture.whenStable(); harness.detectChanges();
    expect(editor(harness)).not.toBe(original); expect(layoutsCreated).toBe(1);
    await harness.navigateByUrl('/experiments/2'); await harness.fixture.whenStable(); await draft(harness, 'Back draft'); confirm.mockReturnValue(false);
    const finished = firstValueFrom(router.events.pipe(filter(event => event instanceof NavigationEnd || event instanceof NavigationCancel), take(1)));
    location.back(); await finished; await harness.fixture.whenStable(); harness.detectChanges();
    expect(router.url).toBe('/experiments/2'); expect(editor(harness).name).toBe('Back draft'); expect(TestBed.inject(UnsavedChanges).dirty()).toBe(true);
  });
});
