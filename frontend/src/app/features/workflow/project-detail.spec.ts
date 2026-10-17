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
});
