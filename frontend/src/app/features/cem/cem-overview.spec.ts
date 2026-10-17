import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { Api } from '../../core/api';
import { CemOverview } from './cem-overview';

describe('CEM-01 configured pagination and explicit page-size choices', () => {
  const api = { get: vi.fn() };
  beforeEach(() => {
    vi.resetAllMocks();
    api.get.mockImplementation(async (_path: string, query: Record<string, unknown>) => ({ items: [], total: 17, page: query['page'], pageSize: query['pageSize'] ?? 5, missingAfterSeconds: 86400 }));
    TestBed.configureTestingModule({ imports: [CemOverview], providers: [provideRouter([]), { provide: Api, useValue: api }] });
  });
  afterEach(() => { vi.restoreAllMocks(); TestBed.resetTestingModule(); });
  async function setup() { const fixture = TestBed.createComponent(CemOverview); await fixture.whenStable(); fixture.detectChanges(); return fixture; }
  it('accepts the configured server default without overriding it in the initial request', async () => {
    const fixture = await setup();
    expect(api.get).toHaveBeenCalledWith('/cem/nodes', { search: '', includeMissing: false, sort: 'hostname', page: 1 });
    expect(fixture.componentInstance.pageSize).toBe(5); expect(fixture.componentInstance.pages()).toBe(4);
    expect((fixture.nativeElement.querySelector('#node-page-size') as HTMLSelectElement).selectedOptions[0].textContent).toBe('5');
  });
  it('retains a chosen size across sorting, searching and refresh until the view is recreated', async () => {
    const fixture = await setup(); const component = fixture.componentInstance;
    component.page = 3;
    const size = fixture.nativeElement.querySelector('#node-page-size') as HTMLSelectElement;
    expect(size).toBeTruthy(); size.value = Array.from(size.options).find(option => option.textContent === '10')!.value;
    size.dispatchEvent(new Event('change', { bubbles: true })); await fixture.whenStable();
    expect(api.get).toHaveBeenLastCalledWith('/cem/nodes', expect.objectContaining({ page: 1, pageSize: 10 }));
    component.sortBy('environment'); await fixture.whenStable();
    expect(api.get).toHaveBeenLastCalledWith('/cem/nodes', expect.objectContaining({ pageSize: 10, sort: 'environment' }));
    component.search = 'fixture'; await component.load();
    expect(api.get).toHaveBeenLastCalledWith('/cem/nodes', expect.objectContaining({ pageSize: 10, search: 'fixture' }));
    fixture.destroy(); const recreated = await setup();
    expect(api.get).toHaveBeenLastCalledWith('/cem/nodes', expect.not.objectContaining({ pageSize: expect.anything() }));
    expect(recreated.componentInstance.pageSize).toBe(5);
  });
  it('keeps unusual configured sizes selectable and ignores an older pagination response', async () => {
    api.get.mockResolvedValueOnce({ items: [], total: 17, page: 1, pageSize: 7, missingAfterSeconds: 86400 });
    const fixture = await setup(); const component = fixture.componentInstance;
    expect([...fixture.nativeElement.querySelectorAll('#node-page-size option')].map((option: any) => option.textContent)).toContain('7');
    let finish!: (value: unknown) => void; api.get.mockImplementationOnce(() => new Promise(resolve => { finish = resolve; }));
    const outdated = component.load(); component.sortBy('id'); await fixture.whenStable();
    finish({ items: [], total: 99, page: 5, pageSize: 99, missingAfterSeconds: 99 }); await outdated;
    expect(component.pageSize).toBe(5); expect(component.total()).toBe(17); expect(component.missingAfter()).toBe(86400);
  });
});
