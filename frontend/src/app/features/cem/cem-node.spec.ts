import { TestBed } from '@angular/core/testing';
import { ActivatedRoute, convertToParamMap, ParamMap } from '@angular/router';
import { Subject } from 'rxjs';
import { describe, expect, it, vi } from 'vitest';
import { Api } from '../../core/api';
import { Realtime } from '../../core/realtime';
import { provideRealtimeMock, RealtimeMock } from '../../core/realtime-testing';
import { CemNode } from './cem-node';

describe('CEM-02 live node subscription lifetime', () => {
  it('pushes node details and rejects old frames after navigation or destruction', async () => {
    const params = new Subject<ParamMap>();
    const api = { get: vi.fn(async (path: string) => ({ node: { id: path.split('/').at(-1), cpuPercent: 0 }, events: [] })) };
    TestBed.configureTestingModule({ imports: [CemNode], providers: [
      { provide: Api, useValue: api }, provideRealtimeMock(api),
      { provide: ActivatedRoute, useValue: { paramMap: params } },
    ] });
    TestBed.overrideComponent(CemNode, { set: { template: '', imports: [] } });
    const fixture = TestBed.createComponent(CemNode), component = fixture.componentInstance;
    params.next(convertToParamMap({ id: 'one' })); await fixture.whenStable();
    const realtime = TestBed.inject(Realtime) as unknown as RealtimeMock;
    const old = [...realtime.listeners][0];
    realtime.push('/cem/nodes/one', { node: { id: 'one', cpuPercent: 81 }, events: [] });
    expect(component.detail()?.node.cpuPercent).toBe(81); expect(component.loading()).toBe(false);
    expect(api.get).toHaveBeenCalledTimes(1);
    params.next(convertToParamMap({ id: 'two' })); await fixture.whenStable();
    old.apply({ node: { id: 'one', cpuPercent: 99 }, events: [] });
    expect(component.detail()?.node.id).toBe('two'); expect(realtime.listeners.size).toBe(1);
    fixture.destroy(); expect(realtime.listeners.size).toBe(0);
  });
});
