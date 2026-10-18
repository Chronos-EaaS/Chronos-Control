import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { Api } from '../../core/api';
import { ProjectDetail } from './project-detail';

const project = { id: 1, name: 'Fixture project', description: '', systemId: 1, systemName: 'Fixture system', ownerId: 3, permissions: { canEdit: true, canArchive: false, canManageMembers: false } };
const starred = { id: 17, internalId: 3, experimentInternalId: 2, name: 'Archived starred evaluation', experimentId: 9, experimentName: 'Archived experiment', archived: true, starred: true };

describe('PROJECT-09 archived starred evaluations and hierarchical identifiers', () => {
  let api: { get: ReturnType<typeof vi.fn> };
  beforeEach(() => {
    api = { get: vi.fn(async (path: string, query?: Record<string, unknown>) => {
      if (path === '/projects/1') return project;
      if (path.endsWith('/evaluations')) return { items: query?.['archived'] === 'all' ? [starred] : [], total: query?.['archived'] === 'all' ? 1 : 0, page: 1, pageSize: 7 };
      if (path.endsWith('/experiments')) return { items: [], total: 0, page: 1, pageSize: 7 };
      if (path.endsWith('/members') || path.endsWith('/events')) return { items: [] };
      throw new Error(`Unexpected unit-test request ${path}`);
    }) };
    TestBed.configureTestingModule({ providers: [provideRouter([{ path: 'projects/:id', component: ProjectDetail }]), { provide: Api, useValue: api }] });
  });
  it('retains archived starred evaluations, links their internal hierarchy and searches experiment context', async () => {
    const harness = await RouterTestingHarness.create('/projects/1'); await harness.fixture.whenStable(); harness.detectChanges();
    const grid = [...harness.routeNativeElement!.querySelectorAll('app-data-grid')].find(element => element.textContent?.includes('Archived starred evaluation'))!;
    expect(grid).toBeDefined();
    const id = [...grid.querySelectorAll('a')].find(link => link.textContent?.trim() === '2.3')!;
    expect(id.getAttribute('href')).toBe('/evaluations/17');
    const search = grid.querySelector<HTMLInputElement>('input[type="search"]')!;
    search.value = 'Archived experiment'; search.dispatchEvent(new Event('input', { bubbles: true })); await harness.fixture.whenStable(); harness.detectChanges();
    expect(grid.textContent).toContain('Archived starred evaluation');
    expect(grid.querySelector<HTMLSelectElement>('select')?.value).toContain('7');
  });
  it('keeps current experiments visible when an older archived-filter response arrives last', async () => {
    const harness = await RouterTestingHarness.create('/projects/1'); await harness.fixture.whenStable(); harness.detectChanges();
    const component = harness.routeDebugElement!.componentInstance as ProjectDetail;
    let finishArchived!: (value: object) => void, finishCurrent!: (value: object) => void;
    api.get.mockImplementation((_path: string, query?: Record<string, unknown>) => new Promise(resolve => { if (query?.['archived'] === 'true') finishArchived = resolve; else finishCurrent = resolve; }));
    component.archived = 'true'; const archived = component.reloadExperiments();
    component.archived = 'false'; const current = component.reloadExperiments();
    finishCurrent({ items: [{ id: 1, internalId: 1, name: 'Current experiment', description: '', archived: false }], total: 1, page: 1, pageSize: 7 }); await current;
    finishArchived({ items: [{ id: 2, internalId: 2, name: 'Archived experiment', description: '', archived: true }], total: 1, page: 1, pageSize: 7 }); await archived; harness.detectChanges();
    expect(component.archived).toBe('false'); expect(component.experiments().map(row => row.name)).toEqual(['Current experiment']);
    expect(harness.routeNativeElement!.textContent).toContain('Current experiment');
    expect(harness.routeNativeElement!.querySelector('#experiment-state')?.textContent).toContain('Current experiments');
  });
  it('keeps project editing disabled until the initial related requests finish', async () => {
    const get = api.get.getMockImplementation()! as (path: string, query?: Record<string, unknown>) => Promise<unknown>;
    let finishCandidates!: (value: { items: never[] }) => void;
    const candidates = new Promise<{ items: never[] }>(resolve => { finishCandidates = resolve; });
    api.get.mockImplementation((path: string, query?: Record<string, unknown>) => {
      if (path === '/projects/1') return Promise.resolve({ ...project, permissions: { canEdit: true, canArchive: true, canManageMembers: true } });
      if (path.endsWith('/members') && query?.['candidates'] === true) return candidates;
      return get(path, query);
    });
    const harness = await RouterTestingHarness.create('/projects/1'); await harness.fixture.whenStable(); harness.detectChanges();
    const page = harness.routeNativeElement!;
    const name = page.querySelector<HTMLInputElement>('#project-name')!;
    const description = page.querySelector<HTMLTextAreaElement>('#project-description')!;
    const archive = [...page.querySelectorAll('button')].find(button => button.textContent?.trim() === 'Archive project')!;
    try {
      expect(page.querySelector('h1')?.textContent).toContain(project.name);
      expect(api.get).toHaveBeenCalledWith('/projects/1/members', { candidates: true });
      expect(page.querySelector('.loading-state')).not.toBeNull();
      expect(name.matches(':disabled')).toBe(true);
      expect(description.matches(':disabled')).toBe(true);
      expect(archive.disabled).toBe(true);
    } finally {
      finishCandidates({ items: [] });
      await vi.waitFor(() => expect((harness.routeDebugElement!.componentInstance as ProjectDetail).busy()).toBe(false));
      await harness.fixture.whenStable(); harness.detectChanges();
    }
    const loading = page.querySelector<HTMLElement>('.loading-state')!;
    expect(loading.style.visibility).toBe('hidden'); expect(loading.hasAttribute('role')).toBe(false);
    expect(loading.getAttribute('aria-hidden')).toBe('true');
    expect(name.matches(':disabled')).toBe(false);
    expect(description.matches(':disabled')).toBe(false);
    expect(archive.disabled).toBe(false);
    name.value = 'Edited after loading'; name.dispatchEvent(new Event('input', { bubbles: true }));
    await harness.fixture.whenStable(); harness.detectChanges();
    const save = [...page.querySelectorAll('button')].find(button => button.textContent?.trim() === 'Save project')!;
    expect(name.value).toBe('Edited after loading'); expect(save.disabled).toBe(false);
  });
});
