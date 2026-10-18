import { TestBed } from '@angular/core/testing';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { Router } from '@angular/router';
import { DataColumn, DataGrid } from './data-grid';

interface Row { id: number; name: string; description: string; hidden?: string; }
const columns: DataColumn<Row>[] = [
  { key: 'id', label: 'ID', value: row => row.id }, { key: 'name', label: 'Name', value: row => row.name },
  { key: 'description', label: 'Description', value: row => row.description },
];
const rows: Row[] = [
  { id: 10, name: 'Item 10', description: 'Repeated description' },
  { id: 2, name: 'Item 2', description: 'Repeated description' },
  { id: 1, name: 'Zürich', description: 'Unicode & XML <text>' },
  { id: 3, name: 'Fourth', description: 'Last record', hidden: 'secret marker' },
];

describe('PROJECT/EXPERIMENT child table search, sorting and pagination', () => {
  const navigate = vi.fn(async () => true), navigateByUrl = vi.fn(async () => true);
  beforeEach(() => { navigate.mockClear(); navigateByUrl.mockClear(); TestBed.configureTestingModule({ imports: [DataGrid], providers: [{ provide: Router, useValue: { navigate, navigateByUrl } }] }); });
  function setup(size = 2) {
    const fixture = TestBed.createComponent(DataGrid<Row>);
    fixture.componentRef.setInput('rows', rows); fixture.componentRef.setInput('columns', columns);
    fixture.componentRef.setInput('label', 'Experiments'); fixture.componentRef.setInput('defaultPageSize', size);
    fixture.detectChanges();
    return fixture;
  }
  function ids(element: HTMLElement): string[] { return [...element.querySelectorAll('tbody tr')].map(row => row.querySelector('td')?.textContent?.trim() ?? ''); }
  it('applies configured rows per page and exposes native column/row semantics', () => {
    const fixture = setup(); const element = fixture.nativeElement as HTMLElement;
    expect(ids(element)).toEqual(['1', '2']);
    expect(element.querySelector('caption')?.textContent).toContain('Experiments');
    expect(element.querySelector('th')?.getAttribute('aria-sort')).toBe('ascending');
    expect(element.textContent).toContain('Page 1 of 2');
  });
  it('sorts numeric IDs and natural text correctly without mutating its source', () => {
    const fixture = setup(5), grid = fixture.componentInstance;
    grid.sortBy('id'); fixture.detectChanges(); expect(ids(fixture.nativeElement)).toEqual(['10', '3', '2', '1']);
    grid.sortBy('name'); fixture.detectChanges(); expect(grid.visibleRows().map(row => row.name)).toEqual(['Fourth', 'Item 2', 'Item 10', 'Zürich']);
    expect(rows.map(row => row.id)).toEqual([10, 2, 1, 3]);
  });
  it('searches displayed values including Unicode and descriptions, preserving literal markup as text', () => {
    const fixture = setup(), grid = fixture.componentInstance;
    grid.setSearch('unicode & xml'); fixture.detectChanges(); expect(ids(fixture.nativeElement)).toEqual(['1']);
    expect((fixture.nativeElement as HTMLElement).textContent).toContain('Unicode & XML <text>');
    expect((fixture.nativeElement as HTMLElement).querySelector('text')).toBeNull();
    grid.setSearch('secret marker'); fixture.detectChanges(); expect(grid.visibleRows()).toEqual([]);
  });
  it('resets to the first page after searching and exposes useful empty pagination', () => {
    const fixture = setup(1), grid = fixture.componentInstance;
    grid.page.set(3); grid.setSearch('missing'); fixture.detectChanges();
    expect(grid.safePage()).toBe(1); expect(grid.pageCount()).toBe(1);
    expect((fixture.nativeElement as HTMLElement).textContent).toContain('0–0 of 0 matching rows');
    expect([...fixture.nativeElement.querySelectorAll('nav button')].every((button: unknown) => (button as HTMLButtonElement).disabled)).toBe(true);
    grid.setSearch(''); fixture.detectChanges(); expect(ids(fixture.nativeElement)).toEqual(['1']);
  });
  it('keeps an explicitly selected size across data reloads and clamps removed last pages', () => {
    const fixture = setup(1), grid = fixture.componentInstance;
    grid.setSize(2); fixture.componentRef.setInput('defaultPageSize', 10); fixture.detectChanges(); expect(grid.pageSize()).toBe(2);
    grid.page.set(2); fixture.componentRef.setInput('rows', [rows[2]]); fixture.detectChanges();
    expect(grid.safePage()).toBe(1); expect(ids(fixture.nativeElement)).toEqual(['1']);
  });
  it('opens a project row from a plain cell click when row navigation is enabled', () => {
    const fixture = setup(); fixture.componentRef.setInput('rowLink', (row: Row) => ['/experiments', row.id]); fixture.detectChanges();
    fixture.nativeElement.querySelector('tbody td').dispatchEvent(new MouseEvent('click', { bubbles: true, button: 0 }));
    expect(navigate).toHaveBeenCalledWith(['/experiments', 1]);
    expect(navigateByUrl).not.toHaveBeenCalled();
  });
  it('preserves nested controls, native links and modified clicks without navigating the row', () => {
    const fixture = setup(); fixture.componentRef.setInput('rowLink', (row: Row) => '/experiments/' + row.id); fixture.detectChanges();
    const cell = fixture.nativeElement.querySelector('tbody td') as HTMLTableCellElement;
    for (const tag of ['button', 'a', 'input', 'select', 'label']) {
      const control = document.createElement(tag); cell.append(control);
      control.dispatchEvent(new MouseEvent('click', { bubbles: true, button: 0 })); control.remove();
    }
    for (const options of [{ ctrlKey: true }, { metaKey: true }, { shiftKey: true }, { altKey: true }, { button: 1 }]) cell.dispatchEvent(new MouseEvent('click', { bubbles: true, ...options }));
    expect(navigate).not.toHaveBeenCalled(); expect(navigateByUrl).not.toHaveBeenCalled();
    cell.dispatchEvent(new MouseEvent('click', { bubbles: true, button: 0 }));
    expect(navigateByUrl).toHaveBeenCalledWith('/experiments/1');
  });
  it('leaves existing grids inert unless they explicitly opt into row navigation', () => {
    const fixture = setup(); fixture.nativeElement.querySelector('tbody td').dispatchEvent(new MouseEvent('click', { bubbles: true }));
    expect(navigate).not.toHaveBeenCalled(); expect(navigateByUrl).not.toHaveBeenCalled();
  });
  it('keeps the existing full toolbar and visible single-page pagination by default', () => {
    const fixture = setup(5), element = fixture.nativeElement as HTMLElement;
    expect(fixture.componentInstance.compact()).toBe(false);
    expect(element.querySelector('.grid-toolbar')?.classList.contains('grid-controls-compact')).toBe(false);
    expect(element.querySelector('.grid-search label span')?.classList.contains('visually-hidden')).toBe(false);
    expect(element.querySelector('.grid-footer')?.classList.contains('visually-hidden')).toBe(false);
    expect(element.querySelector('.grid-pagination')?.textContent).toContain('Page 1 of 1');
    expect(element.querySelector('.grid-sort')).toBeNull();
  });
  it('keeps compact single-page search and page size available with an accessible result count', async () => {
    const fixture = setup(5); fixture.componentRef.setInput('compact', true); fixture.detectChanges();
    const element = fixture.nativeElement as HTMLElement;
    expect(element.querySelector('.grid-toolbar')?.classList.contains('grid-controls-compact')).toBe(true);
    expect(element.querySelector('.grid-search label')?.textContent?.trim()).toBe('Search experiments');
    expect(element.querySelector('.grid-footer')?.classList.contains('visually-hidden')).toBe(true);
    expect(element.querySelector('.grid-pagination')).toBeNull();
    expect(element.querySelector('.grid-footer [role="status"]')?.getAttribute('aria-live')).toBe('polite');
    expect(element.querySelector('.grid-footer [role="status"]')?.textContent).toContain('1–4 of 4 rows');
    const search = element.querySelector<HTMLInputElement>('input[type="search"]')!;
    search.value = 'Unicode'; search.dispatchEvent(new Event('input', { bubbles: true }));
    await fixture.whenStable(); fixture.detectChanges();
    expect(ids(element)).toEqual(['1']);
    expect(element.querySelector('.grid-footer [role="status"]')?.textContent).toContain('1–1 of 1 matching rows');
    const size = element.querySelector<HTMLSelectElement>('select')!;
    expect(size.disabled).toBe(false);
    expect([...size.options].map(option => option.textContent?.trim())).toContain('10');
    size.value = [...size.options].find(option => option.textContent?.trim() === '10')!.value;
    size.dispatchEvent(new Event('change', { bubbles: true })); await fixture.whenStable(); fixture.detectChanges();
    expect(fixture.componentInstance.pageSize()).toBe(10);
  });
  it('shows the full compact pagination whenever more than one page is available', async () => {
    const fixture = setup(2); fixture.componentRef.setInput('compact', true); fixture.detectChanges();
    const element = fixture.nativeElement as HTMLElement;
    expect(element.querySelector('.grid-footer')?.classList.contains('visually-hidden')).toBe(false);
    expect(element.querySelector('.grid-pagination')?.textContent).toContain('Page 1 of 2');
    [...element.querySelectorAll<HTMLButtonElement>('.grid-pagination button')].find(button => button.textContent?.trim() === 'Next')!.click(); fixture.detectChanges();
    expect(ids(element)).toEqual(['3', '10']);
    expect(element.querySelector('.grid-pagination')?.textContent).toContain('Page 2 of 2');
    fixture.componentInstance.setSearch('Unicode'); fixture.detectChanges();
    expect(element.querySelector('.grid-pagination')).toBeNull();
    fixture.componentInstance.setSearch(''); fixture.detectChanges();
    expect(element.querySelector('.grid-pagination')?.textContent).toContain('Page 1 of 2');
    fixture.componentInstance.setSize(5); fixture.detectChanges();
    expect(element.querySelector('.grid-pagination')).toBeNull();
    expect(element.querySelector('.grid-footer [role="status"]')?.textContent).toContain('1–4 of 4 rows');
  });
  it('offers additional sort and search fields without adding visible table columns', async () => {
    const fixture = setup(5); fixture.componentRef.setInput('compact', true);
    fixture.componentRef.setInput('additionalSortColumns', [
      { key: 'experiment', label: 'Experiment', value: (row: Row) => ({ 10: 'Zulu', 2: 'Alpha', 1: 'Mike', 3: 'Beta' })[row.id] },
      { key: 'starred', label: 'Starred', value: (row: Row) => [10, 1].includes(row.id) ? 'Starred' : 'Not starred' },
      { key: 'note', label: 'Internal note', value: () => 'Excluded metadata', sortable: false, searchable: false },
    ]); fixture.detectChanges();
    const element = fixture.nativeElement as HTMLElement, grid = fixture.componentInstance;
    const sort = element.querySelector<HTMLSelectElement>('.grid-sort-column')!;
    expect([...sort.options].map(option => option.textContent?.trim())).toEqual(['ID', 'Name', 'Description', 'Experiment', 'Starred']);
    expect(element.querySelectorAll('thead th')).toHaveLength(3);
    expect(element.querySelector('tbody tr')?.querySelectorAll('td')).toHaveLength(3);
    sort.value = 'experiment'; sort.dispatchEvent(new Event('change', { bubbles: true })); await fixture.whenStable(); fixture.detectChanges();
    expect(ids(element)).toEqual(['2', '3', '1', '10']);
    const direction = element.querySelector<HTMLButtonElement>('.grid-sort-direction')!;
    expect(direction.getAttribute('aria-label')).toBe('Sort experiments descending');
    direction.click(); fixture.detectChanges();
    expect(ids(element)).toEqual(['10', '1', '3', '2']);
    expect(direction.getAttribute('aria-label')).toBe('Sort experiments ascending');
    const search = element.querySelector<HTMLInputElement>('input[type="search"]')!;
    search.value = 'Alpha'; search.dispatchEvent(new Event('input', { bubbles: true })); await fixture.whenStable(); fixture.detectChanges();
    expect(ids(element)).toEqual(['2']);
    search.value = 'Not starred'; search.dispatchEvent(new Event('input', { bubbles: true })); await fixture.whenStable(); fixture.detectChanges();
    expect(ids(element)).toEqual(['3', '2']);
    grid.setSearch(''); grid.setSortColumn('starred'); fixture.detectChanges();
    expect(grid.sortKey()).toBe('starred'); expect(grid.descending()).toBe(true);
    expect(ids(element)).toEqual(['10', '1', '2', '3']);
    grid.setSearch('Excluded metadata'); fixture.detectChanges(); expect(grid.visibleRows()).toEqual([]);
    grid.setSearch(''); grid.setSortColumn('name'); fixture.detectChanges(); await fixture.whenStable(); fixture.detectChanges();
    expect(element.querySelector('th[aria-sort="descending"]')?.textContent).toContain('Name');
    expect(sort.value).toBe('name');
  });
});
