import { Location } from '@angular/common';
import { provideLocationMocks } from '@angular/common/testing';
import { Component } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { By } from '@angular/platform-browser';
import { BaseRouteReuseStrategy, NavigationCancel, NavigationEnd, Router, RouterOutlet, RouteReuseStrategy, provideRouter } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import { firstValueFrom, filter, take } from 'rxjs';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { mayEnterResource, routes as applicationRoutes } from '../app.routes';
import { ExperimentResults } from '../features/results/experiment-results';
import { ResultConfigurationEditor } from '../features/results/result-configuration-editor';
import { ExperimentDetail } from '../features/workflow/experiment-detail';
import { Api } from './api';
import { ResourceRouteReuseStrategy } from './resource-reuse';
import { UnsavedChanges } from './unsaved';

let layoutsCreated = 0;
@Component({ imports: [RouterOutlet], template: '<router-outlet />' })
class PersistentLayout { constructor() { ++layoutsCreated; } }
class ReuseSameResourceRoute extends BaseRouteReuseStrategy {}

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
      provideRouter([{ path: '', component: PersistentLayout, canActivateChild: [mayEnterResource], children: [{ ...applicationRoutes.find(route => route.path === '')!.children!.find(route => route.path === 'experiments/:id')!, loadComponent: undefined, component: ExperimentDetail }] }]),
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
  it('a late save from the previous editor cannot remove the next editor draft protection', async () => {
    const harness = await setup(); const router = TestBed.inject(Router); const original = editor(harness);
    await draft(harness, 'Save the first experiment');
    let finishSave!: (value: ReturnType<typeof record>) => void;
    api.patch.mockImplementationOnce(() => new Promise<ReturnType<typeof record>>(resolve => { finishSave = resolve; }));
    const saving = original.save();
    expect(api.patch).toHaveBeenCalledWith('/experiments/1', { name: 'Save the first experiment', description: 'Saved description' });
    const confirmation = vi.spyOn(window, 'confirm').mockReturnValue(true);
    await harness.navigateByUrl('/experiments/2'); await harness.fixture.whenStable(); harness.detectChanges();
    const next = editor(harness); expect(next).not.toBe(original);
    await draft(harness, 'Protect the second experiment draft');
    expect(TestBed.inject(UnsavedChanges).dirty()).toBe(true);
    finishSave({ ...record(1), name: 'Save the first experiment' }); await saving; await harness.fixture.whenStable(); harness.detectChanges();
    expect(next.name).toBe('Protect the second experiment draft');
    expect(TestBed.inject(UnsavedChanges).dirty()).toBe(true);
    confirmation.mockClear().mockReturnValue(false);
    await harness.navigateByUrl('/experiments/1'); await harness.fixture.whenStable(); harness.detectChanges();
    expect(router.url).toBe('/experiments/2'); expect(editor(harness)).toBe(next);
    expect(confirmation).toHaveBeenCalledTimes(1);
  });
  it('a reused resource editor ignores a late save for its previous ID, including busy and draft state', async () => {
    TestBed.overrideProvider(RouteReuseStrategy, { useValue: new ReuseSameResourceRoute() });
    const harness = await setup(); const original = editor(harness); await draft(harness, 'Save ID one');
    let finishFirst!: (value: ReturnType<typeof record>) => void;
    let finishSecond!: (value: ReturnType<typeof record>) => void;
    api.patch.mockImplementationOnce(() => new Promise<ReturnType<typeof record>>(resolve => { finishFirst = resolve; }));
    const firstSave = original.save(); vi.spyOn(window, 'confirm').mockReturnValue(true);
    await harness.navigateByUrl('/experiments/2'); await harness.fixture.whenStable(); harness.detectChanges();
    expect(editor(harness)).toBe(original); expect(original.experiment()?.id).toBe(2);
    await draft(harness, 'Save ID two');
    api.patch.mockImplementationOnce(() => new Promise<ReturnType<typeof record>>(resolve => { finishSecond = resolve; }));
    const secondSave = original.save();
    expect(api.patch).toHaveBeenLastCalledWith('/experiments/2', { name: 'Save ID two', description: 'Saved description' });
    finishFirst({ ...record(1), name: 'Save ID one' }); await firstSave; harness.detectChanges();
    expect(original.experiment()?.id).toBe(2); expect(original.name).toBe('Save ID two');
    expect(original.saving()).toBe(true); expect(TestBed.inject(UnsavedChanges).dirty()).toBe(true);
    finishSecond({ ...record(2), name: 'Save ID two' }); await secondSave; harness.detectChanges();
    expect(original.experiment()?.id).toBe(2); expect(original.saving()).toBe(false);
    expect(TestBed.inject(UnsavedChanges).dirty()).toBe(false);
  });
  it('guards non-context query changes and browser back navigation without applying a draft to another URL', async () => {
    const harness = await setup(); const router = TestBed.inject(Router); const location = TestBed.inject(Location);
    const original = editor(harness); await draft(harness, 'Query draft'); const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false);
    await harness.navigateByUrl('/experiments/1?mode=readonly'); expect(router.url).toBe('/experiments/1'); expect(editor(harness)).toBe(original);
    confirm.mockReturnValue(true); await harness.navigateByUrl('/experiments/1?mode=readonly'); await harness.fixture.whenStable(); harness.detectChanges();
    expect(editor(harness)).not.toBe(original); expect(layoutsCreated).toBe(1);
    await harness.navigateByUrl('/experiments/2'); await harness.fixture.whenStable(); await draft(harness, 'Back draft'); confirm.mockReturnValue(false);
    const finished = firstValueFrom(router.events.pipe(filter(event => event instanceof NavigationEnd || event instanceof NavigationCancel), take(1)));
    location.back(); await finished; await harness.fixture.whenStable(); harness.detectChanges();
    expect(router.url).toBe('/experiments/2'); expect(editor(harness).name).toBe('Back draft'); expect(TestBed.inject(UnsavedChanges).dirty()).toBe(true);
  });
  it('keeps the actual parent and nested draft across view-context navigation and Back/Forward without discard prompts', async () => {
    const get = api.get.getMockImplementation() as (path: string) => Promise<unknown>;
    api.get.mockImplementation(async (path: string) => path.endsWith('/result-configurations') ? {
      version: '1.0', elements: {
        'system-1': { name: 'System defaults', all: [], job: [] },
        'experiment-1-alpha': { name: 'Alpha', all: [], job: [] },
        'experiment-1-beta': { name: 'Beta', all: [], job: [] },
      },
    } : get(path));
    const harness = await setup();
    const original = editor(harness);
    const results = harness.fixture.debugElement.query(By.directive(ExperimentResults)).componentInstance as ExperimentResults;
    const configurationEditor = () => harness.fixture.debugElement.query(By.directive(ResultConfigurationEditor)).componentInstance as ResultConfigurationEditor;
    await draft(harness, 'Keep experiment metadata');
    const copy = [...harness.routeNativeElement!.querySelectorAll('button')].find(button => button.textContent?.trim() === 'Copy to this experiment')!;
    copy.click(); await harness.fixture.whenStable(); harness.detectChanges();
    const draftId = configurationEditor().selectedId();
    configurationEditor().rename('Unsaved Zürich result draft');
    await harness.fixture.whenStable(); harness.detectChanges();
    results.editConfiguration('experiment-1-beta', 'job');
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false);
    const router = TestBed.inject(Router), location = TestBed.inject(Location);
    const assertDraft = (id: string, scope: 'all' | 'job') => {
      harness.detectChanges();
      expect(editor(harness)).toBe(original);
      expect(harness.fixture.debugElement.query(By.directive(ExperimentResults)).componentInstance).toBe(results);
      expect(original.name).toBe('Keep experiment metadata');
      expect(results.editable().elements[draftId].name).toBe('Unsaved Zürich result draft');
      expect(results.dirty()).toBe(true);
      expect(TestBed.inject(UnsavedChanges).dirty()).toBe(true);
      expect(configurationEditor().selectedId()).toBe(id);
      expect(configurationEditor().scopes()[0].key).toBe(scope);
    };
    const firstUrl = '/experiments/1?configuration=experiment-1-alpha&scope=all&tab=results';
    const secondUrl = '/experiments/1?configuration=experiment-1-beta&scope=job&tab=results';
    await harness.navigateByUrl(firstUrl); await harness.fixture.whenStable(); harness.detectChanges();
    expect(router.url).toBe(firstUrl); assertDraft('experiment-1-alpha', 'all');
    await harness.navigateByUrl(secondUrl); await harness.fixture.whenStable(); harness.detectChanges();
    expect(router.url).toBe(secondUrl); assertDraft('experiment-1-beta', 'job');
    for (const [direction, url, id, scope] of [
      ['back', firstUrl, 'experiment-1-alpha', 'all'], ['forward', secondUrl, 'experiment-1-beta', 'job'],
    ] as const) {
      const finished = firstValueFrom(router.events.pipe(filter(event => event instanceof NavigationEnd || event instanceof NavigationCancel), take(1)));
      location[direction](); await finished; await harness.fixture.whenStable(); harness.detectChanges();
      expect(router.url).toBe(url); assertDraft(id, scope);
    }
    expect(confirm).not.toHaveBeenCalled();
    expect(api.get.mock.calls.filter(([path]) => path === '/experiments/1')).toHaveLength(1);
    expect(api.get.mock.calls.filter(([path]) => path === '/experiments/1/result-configurations')).toHaveLength(1);
    expect(api.patch).not.toHaveBeenCalled();
    await harness.navigateByUrl('/experiments/2'); await harness.fixture.whenStable(); harness.detectChanges();
    expect(router.url).toBe(secondUrl); assertDraft('experiment-1-beta', 'job');
    expect(confirm).toHaveBeenCalledTimes(1);
  });

});
