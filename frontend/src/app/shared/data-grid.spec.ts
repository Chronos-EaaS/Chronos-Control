import { TestBed } from '@angular/core/testing';
import { beforeEach, describe, expect, it } from 'vitest';
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
  beforeEach(() => TestBed.configureTestingModule({ imports: [DataGrid] }));
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
});
