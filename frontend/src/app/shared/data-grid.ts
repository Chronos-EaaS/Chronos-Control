import { NgTemplateOutlet } from '@angular/common';
import { Component, TemplateRef, computed, contentChild, effect, inject, input, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { Api } from '../core/api';
import { allPages } from '../core/collections';
import { PageData } from '../core/models';
import { Icon } from './icon';

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
  selector: 'app-data-grid', imports: [FormsModule, NgTemplateOutlet, Icon],
  templateUrl: './data-grid.html',
  styleUrl: './data-grid.scss',
})
export class DataGrid<T extends { id: string | number }> {
  private readonly router = inject(Router, { optional: true });
  readonly rows = input.required<readonly T[]>(); readonly columns = input.required<readonly DataColumn<T>[]>();
  readonly additionalSortColumns = input<readonly DataColumn<T>[]>([]);
  readonly rowLink = input<((row: T) => string | readonly (string | number)[] | null) | null>(null);
  readonly label = input.required<string>(); readonly defaultPageSize = input(25); readonly busy = input(false);
  readonly compact = input(false);
  readonly heading = input(''); readonly headingId = input<string | null>(null);
  readonly cell = contentChild<TemplateRef<{ $implicit: T; column: DataColumn<T> }>>(TemplateRef);
  readonly namespace = `chronos-grid-${++gridId}`; readonly search = signal(''); readonly order = signal(''); readonly page = signal(1); readonly pageSize = signal(25);
  private chosenSize = false;
  readonly sizes = computed(() => [...new Set([5, 10, 25, 50, 100, this.pageSize()])].sort((a, b) => a - b));
  private readonly allColumns = computed(() => {
    const visible = this.columns(), keys = new Set(visible.map(column => column.key));
    return [...visible, ...this.additionalSortColumns().filter(column => !keys.has(column.key))];
  });
  readonly sortableColumns = computed(() => this.allColumns().filter(column => column.sortable !== false));
  readonly filteredRows = computed(() => {
    const search = this.search().trim().toLocaleLowerCase('en');
    const columns = this.allColumns().filter(column => column.searchable !== false);
    return this.rows().filter(row => !search || columns.some(column => String(column.value(row) ?? '').toLocaleLowerCase('en').includes(search)));
  });
  readonly effectiveOrder = computed(() => this.sortableColumns().some(column => column.key === this.order().replace(/^-/, '')) ? this.order() : this.sortableColumns()[0]?.key || '');
  readonly sortKey = computed(() => this.effectiveOrder().replace(/^-/, ''));
  readonly descending = computed(() => this.effectiveOrder().startsWith('-'));
  readonly sortedRows = computed(() => {
    const order = this.effectiveOrder();
    const column = this.sortableColumns().find(column => column.key === this.sortKey());
    if (!column) return this.filteredRows();
    const direction = order.startsWith('-') ? -1 : 1;
    return [...this.filteredRows()].sort((a, b) => direction * compareValues(column.value(a), column.value(b)));
  });
  readonly pageCount = computed(() => Math.max(1, Math.ceil(this.filteredRows().length / this.pageSize())));
  readonly safePage = computed(() => Math.min(this.page(), this.pageCount()));
  readonly visibleRows = computed(() => this.sortedRows().slice((this.safePage() - 1) * this.pageSize(), this.safePage() * this.pageSize()));
  readonly rangeEnd = computed(() => Math.min(this.safePage() * this.pageSize(), this.filteredRows().length));
  constructor() { effect(() => { const configured = this.defaultPageSize(); if (!this.chosenSize) this.pageSize.set(Number.isInteger(configured) && configured > 0 ? Math.min(100, configured) : 25); }); }
  openRow(event: MouseEvent, row: T): void {
    if (event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
    if (event.target instanceof Element && event.target.closest('a,button,input,select,textarea,label,summary,[contenteditable],[role="button"],[role="link"]')) return;
    if (window.getSelection()?.toString()) return;
    const target = this.rowLink()?.(row);
    if (target && this.router) {
      if (typeof target === 'string') void this.router.navigateByUrl(target);
      else void this.router.navigate([...target]);
    }
  }
  setSearch(value: string): void { this.search.set(value); this.page.set(1); }
  setSize(value: number): void { this.chosenSize = true; this.pageSize.set(value); this.page.set(1); }
  sortBy(key: string): void { this.order.set(this.effectiveOrder() === key ? `-${key}` : key); this.page.set(1); }
  setSortColumn(key: string): void { if (this.sortableColumns().some(column => column.key === key)) { this.order.set((this.descending() ? '-' : '') + key); this.page.set(1); } }
  reverseSort(): void { if (this.sortKey()) { this.order.set((this.descending() ? '' : '-') + this.sortKey()); this.page.set(1); } }
}
