import { TestBed } from '@angular/core/testing';
import { ActivatedRoute, convertToParamMap, ParamMap, provideRouter } from '@angular/router';
import { Subject } from 'rxjs';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { Api, ApiError } from '../../core/api';
import { Activity, PageData } from '../../core/models';
import { LiveQuery, LiveSubscription, Realtime } from '../../core/realtime';
import { ExperimentDetail } from './experiment-detail';
import { ProjectDetail } from './project-detail';

interface Watch {
  path: string; query: LiveQuery; closed: boolean;
  deliver(data: PageData<Activity>): void;
  fail(error: ApiError): void;
}
class ActivityPush {
  readonly watches: Watch[] = [];
  watch<T>(path: string, query: LiveQuery, apply: (data: T) => void, failed?: (error: ApiError) => void): LiveSubscription<T> {
    let resolve!: (data: T) => void, reject!: (error: ApiError) => void;
    const ready = new Promise<T>((accept, refuse) => { resolve = accept; reject = refuse; });
    const watch: Watch = { path, query, closed: false,
      deliver: data => { apply(data as T); resolve(data as T); },
      fail: error => { failed?.(error); reject(error); },
    };
    this.watches.push(watch);
    return { ready, refresh: () => ready, update: () => ready, checkpoint: () => {}, close: () => { watch.closed = true; } };
  }
}
const page = (id: number): PageData<Activity> => ({
  items: [{ id, title: `Event ${id}`, text: 'Pushed activity', at: '2026-10-20T12:34:56+02:00' }],
  total: 1, page: 1, pageSize: 5,
});

describe.each(['project', 'experiment'] as const)('%s timeline is independent of the editor', kind => {
  afterEach(() => TestBed.resetTestingModule());
  async function setup() {
    const params = new Subject<ParamMap>(), live = new ActivityPush();
    const api = { get: vi.fn(async (path: string) => {
      if (path.endsWith('/events')) throw new Error('Activity must use the existing WebSocket.');
      if (/^\/(projects|experiments)\/\d+$/.test(path)) return {
        id: Number(path.split('/').at(-1)), name: 'Saved name', description: 'Saved description',
        permissions: { canManageMembers: false }, defaultEnvironment: '',
      };
      if (path.endsWith('/run-options')) return { environments: [] };
      return { items: [], total: 0, page: 1, pageSize: 5 };
    }) };
    TestBed.configureTestingModule({ providers: [provideRouter([]), { provide: Api, useValue: api },
      { provide: Realtime, useValue: live }, { provide: ActivatedRoute, useValue: { paramMap: params } },
    ] });
    TestBed.overrideComponent(ProjectDetail, { set: { template: '', imports: [] } });
    TestBed.overrideComponent(ExperimentDetail, { set: { template: '', imports: [] } });
    const fixture = kind === 'project' ? TestBed.createComponent(ProjectDetail) : TestBed.createComponent(ExperimentDetail);
    params.next(convertToParamMap({ id: '1' }));
    await vi.waitFor(() => expect(fixture.componentInstance.busy()).toBe(false));
    return { fixture, component: fixture.componentInstance, params, live, api };
  }

  it('opens one stream with the original query while the editor completes before any activity response', async () => {
    const { component, live, api } = await setup();
    expect(live.watches).toHaveLength(1);
    expect(live.watches[0].path).toBe(`/${kind}s/1/events`);
    expect(live.watches[0].query).toEqual(kind === 'project' ? {} : { pageSize: 20, sort: '-id' });
    expect(component.name).toBe('Saved name'); expect(component.events()).toEqual([]);
    expect(api.get.mock.calls.some(([path]) => path.endsWith('/events'))).toBe(false);
    live.watches[0].deliver(page(1));
    expect(component.events().map(event => event.id)).toEqual([1]);
    expect(component.busy()).toBe(false);
  });

  it('keeps a draft and the same subscription when activity arrives or other cards reload', async () => {
    const { component, live } = await setup();
    component.name = 'Unsaved name'; component.description = 'Unsaved description'; component.changes.setDirty(true);
    live.watches[0].deliver(page(2));
    await component.load();
    live.watches[0].deliver(page(3));
    expect(component.name).toBe('Unsaved name'); expect(component.description).toBe('Unsaved description');
    expect(component.changes.dirty()).toBe(true);
    expect(component.events().map(event => event.id)).toEqual([3]);
    expect(live.watches).toHaveLength(1); expect(live.watches[0].closed).toBe(false);
  });

  it('isolates activity errors and ignores frames after navigation or destruction', async () => {
    const { fixture, component, live, params } = await setup();
    const previous = live.watches[0];
    component.error.set('A separate save failed.');
    previous.fail(new ApiError('Activity temporarily unavailable.', 503, 'live_unavailable'));
    await Promise.resolve();
    expect(component.activityError()).toBe('Activity temporarily unavailable.');
    expect(component.error()).toBe('A separate save failed.'); expect(component.busy()).toBe(false);
    previous.deliver(page(4));
    expect(component.activityError()).toBe(''); expect(component.error()).toBe('A separate save failed.');
    params.next(convertToParamMap({ id: '2' }));
    await vi.waitFor(() => expect(component.busy()).toBe(false));
    expect(previous.closed).toBe(true); expect(component.events()).toEqual([]);
    const current = live.watches[1]; current.deliver(page(5));
    // Simulate queued frames whose subscription closed immediately before delivery.
    previous.deliver(page(99)); previous.fail(new ApiError('Old activity error.', 503, 'live_unavailable'));
    expect(component.events().map(event => event.id)).toEqual([5]); expect(component.activityError()).toBe('');
    fixture.destroy(); expect(current.closed).toBe(true);
    current.deliver(page(100)); current.fail(new ApiError('Destroyed view.', 503, 'live_unavailable'));
    expect(component.events().map(event => event.id)).toEqual([5]); expect(component.activityError()).toBe('');
  });
});
