import { NgTemplateOutlet } from '@angular/common';
import { Component, TemplateRef, computed, contentChild, effect, input, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Api } from '../core/api';
import { allPages } from '../core/collections';
import { PageData } from '../core/models';

export interface DataColumn<T> {
  key: string; label: string; value: (row: T) => string | number | boolean | null | undefined;
  sortable?: boolean; searchable?: boolean; className?: string;
}
let gridId = 0;
const collator = new Intl.Collator('en', { numeric: true, sensitivity: 'base' });

export function compareValues(left: unknown, right: unknown): number {
  if (left === null || left === undefined) return right === null || right === undefined ? 0 : 1;
  if (right === null || right === undefined) return -1;
  if (typeof left === 'number' && typeof right === 'number') return left - right;
  return collator.compare(String(left), String(right));
}

/** Preserve the configured default while loading a bounded DOM page from all authorized rows. */
export async function localGridRows<T>(api: Api, path: string, query: Record<string, string | number | boolean> = {}): Promise<{ rows: T[]; pageSize: number }> {
  const first = await api.get<PageData<T>>(path, { ...query, page: 1 });
  const rows = first.items.length >= first.total ? first.items : await allPages<T>(api, path, query);
  return { rows, pageSize: first.pageSize };
}

@Component({
  selector: 'app-data-grid', imports: [FormsModule, NgTemplateOutlet],
  styles: [':host{display:block;min-width:0}.grid-table{max-width:100%;overflow:auto}.grid-controls{display:flex;gap:1rem;flex-wrap:wrap;align-items:end;justify-content:space-between;padding:1rem}.grid-search{min-width:12rem;max-width:28rem;flex:1}.grid-pagination{display:flex;gap:.75rem;align-items:center;flex-wrap:wrap}.grid-size{width:auto;min-width:5rem}.grid-hint{padding:0 1rem}.table{margin-bottom:0}.table-sort{min-height:40px}'],
  template: `<div class="grid-controls"><div class="grid-search"><label class="form-label" [for]="namespace + '-search'">Search {{ label().toLowerCase() }}</label><input type="search" class="form-control" [id]="namespace + '-search'" [ngModel]="search()" (ngModelChange)="setSearch($event)" /></div><div class="d-flex gap-2 align-items-center"><label [for]="namespace + '-size'">Rows per page</label><select class="form-select grid-size" [id]="namespace + '-size'" [ngModel]="pageSize()" (ngModelChange)="setSize($event)">@for (size of sizes(); track size) { <option [ngValue]="size">{{ size }}</option> }</select></div></div>
    <div class="grid-table" tabindex="0" [attr.aria-label]="label() + ' table, horizontally scrollable'" [attr.aria-busy]="busy()"><table class="table table-hover align-middle"><caption class="visually-hidden">{{ label() }}</caption><thead><tr>@for (column of columns(); track column.key) { <th scope="col" [attr.aria-sort]="column.sortable === false ? null : effectiveOrder() === column.key ? 'ascending' : effectiveOrder() === '-' + column.key ? 'descending' : 'none'">@if (column.sortable !== false) { <button class="table-sort" type="button" [attr.aria-label]="'Sort by ' + column.label" (click)="sortBy(column.key)">{{ column.label }} <span aria-hidden="true">{{ effectiveOrder() === column.key ? '↑' : effectiveOrder() === '-' + column.key ? '↓' : '' }}</span></button> } @else { {{ column.label }} }</th> }</tr></thead><tbody>
      @for (row of visibleRows(); track row.id) { <tr>@for (column of columns(); track column.key) { <td [class]="column.className ?? ''">@if (cell(); as template) { <ng-container [ngTemplateOutlet]="template" [ngTemplateOutletContext]="{ $implicit: row, column: column }" /> } @else { {{ column.value(row) ?? '—' }} }</td> }</tr> }
      @empty { <tr><td [attr.colspan]="columns().length" class="text-secondary p-4">{{ busy() ? 'Loading rows…' : 'No rows match this view.' }}</td></tr> }
    </tbody></table></div><p class="grid-hint form-text d-md-none mt-2">Scroll horizontally to view all columns.</p>
    <div class="grid-controls"><span role="status" aria-live="polite">{{ filteredRows().length ? (safePage() - 1) * pageSize() + 1 : 0 }}–{{ rangeEnd() }} of {{ filteredRows().length }}{{ search() ? ' matching rows' : ' rows' }}</span><nav class="grid-pagination" [attr.aria-label]="label() + ' pages'"><button type="button" class="btn btn-sm btn-outline-secondary" [disabled]="safePage() <= 1" (click)="page.set(safePage() - 1)">Previous</button><span>Page {{ safePage() }} of {{ pageCount() }}</span><button type="button" class="btn btn-sm btn-outline-secondary" [disabled]="safePage() >= pageCount()" (click)="page.set(safePage() + 1)">Next</button></nav></div>`,
})
export class DataGrid<T extends { id: string | number }> {
  readonly rows = input.required<readonly T[]>(); readonly columns = input.required<readonly DataColumn<T>[]>();
  readonly label = input.required<string>(); readonly defaultPageSize = input(25); readonly busy = input(false);
  readonly cell = contentChild<TemplateRef<{ $implicit: T; column: DataColumn<T> }>>(TemplateRef);
  readonly namespace = `chronos-grid-${++gridId}`; readonly search = signal(''); readonly order = signal(''); readonly page = signal(1); readonly pageSize = signal(25);
  private chosenSize = false;
  readonly sizes = computed(() => [...new Set([5, 10, 25, 50, 100, this.pageSize()])].sort((a, b) => a - b));
  readonly filteredRows = computed(() => {
    const search = this.search().trim().toLocaleLowerCase('en');
    const columns = this.columns().filter(column => column.searchable !== false);
    return this.rows().filter(row => !search || columns.some(column => String(column.value(row) ?? '').toLocaleLowerCase('en').includes(search)));
  });
  readonly effectiveOrder = computed(() => this.order() || this.columns().find(column => column.sortable !== false)?.key || '');
  readonly sortedRows = computed(() => {
    const order = this.effectiveOrder();
    const column = this.columns().find(column => column.key === order.replace(/^-/, ''));
    if (!column) return this.filteredRows();
    const direction = order.startsWith('-') ? -1 : 1;
    return [...this.filteredRows()].sort((a, b) => direction * compareValues(column.value(a), column.value(b)));
  });
  readonly pageCount = computed(() => Math.max(1, Math.ceil(this.filteredRows().length / this.pageSize())));
  readonly safePage = computed(() => Math.min(this.page(), this.pageCount()));
  readonly visibleRows = computed(() => this.sortedRows().slice((this.safePage() - 1) * this.pageSize(), this.safePage() * this.pageSize()));
  readonly rangeEnd = computed(() => Math.min(this.safePage() * this.pageSize(), this.filteredRows().length));
  constructor() { effect(() => { const configured = this.defaultPageSize(); if (!this.chosenSize) this.pageSize.set(Number.isInteger(configured) && configured > 0 ? Math.min(100, configured) : 25); }); }
  setSearch(value: string): void { this.search.set(value); this.page.set(1); }
  setSize(value: number): void { this.chosenSize = true; this.pageSize.set(value); this.page.set(1); }
  sortBy(key: string): void { this.order.set(this.effectiveOrder() === key ? `-${key}` : key); this.page.set(1); }
}
