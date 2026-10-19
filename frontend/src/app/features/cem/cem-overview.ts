import { Icon } from '../../shared/icon';
import { Component, DestroyRef, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { Api, errorMessage } from '../../core/api';
import { CemNodeData, CemNodePage } from '../../core/admin-models';
import { dateTime } from '../../core/time';
import { Feedback } from '../../shared/feedback';

@Component({
  imports: [FormsModule, RouterLink, Feedback, Icon],
  templateUrl: './cem-overview.html',
})
export class CemOverview {
  private readonly router = inject(Router); private readonly api = inject(Api); readonly nodes = signal<CemNodeData[]>([]); readonly loading = signal(true); readonly error = signal(''); readonly total = signal(0); readonly missingAfter = signal(86400); readonly date = dateTime;
  search = ''; includeMissing = false; sort = 'hostname'; page = 1; pageSize = 20; private pageSizeChosen = false; private loadId = 0;
  constructor() { const timer = window.setInterval(() => { if (document.visibilityState === 'visible' && !this.loading()) void this.load(); }, 30_000); inject(DestroyRef).onDestroy(() => { clearInterval(timer); ++this.loadId; }); void this.load(); }
  openNode(event: MouseEvent, id: string): void {
    if (event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || window.getSelection()?.toString()) return;
    if (event.target instanceof Element && event.target.closest('a,button,input,select,textarea,label')) return;
    void this.router.navigate(['/cem/nodes', id]);
  }
  pages(): number { return Math.max(1, Math.ceil(this.total() / this.pageSize)); }
  sizes(): number[] { return [...new Set([5, 10, 25, 50, 100, this.pageSize])].sort((left, right) => left - right); }
  sizeChanged(): void { this.pageSizeChosen = true; this.page = 1; void this.load(); }
  sortMark(key: string): string { return this.sort === key ? '↑' : this.sort === `-${key}` ? '↓' : ''; }
  sortBy(key: string): void { this.sort = this.sort === key ? `-${key}` : key; this.page = 1; void this.load(); }
  async load(): Promise<void> {
    const request = ++this.loadId; this.loading.set(true); this.error.set('');
    const query = { search: this.search, includeMissing: this.includeMissing, sort: this.sort, page: this.page, ...(this.pageSizeChosen ? { pageSize: this.pageSize } : {}) };
    try { const data = await this.api.get<CemNodePage>('/cem/nodes', query); if (request === this.loadId) { this.nodes.set(data.items); this.total.set(data.total); this.page = data.page; this.pageSize = data.pageSize; this.missingAfter.set(data.missingAfterSeconds); } }
    catch (error) { if (request === this.loadId) this.error.set(errorMessage(error)); } finally { if (request === this.loadId) this.loading.set(false); }
  }
}
