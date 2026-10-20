import { Icon } from '../../shared/icon';
import { Component, DestroyRef, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { errorMessage } from '../../core/api';
import { LiveSubscription, Realtime } from '../../core/realtime';
import { CemNodeData, CemNodePage } from '../../core/admin-models';
import { dateTime } from '../../core/time';
import { Feedback } from '../../shared/feedback';

@Component({
  imports: [FormsModule, RouterLink, Feedback, Icon],
  templateUrl: './cem-overview.html',
})
export class CemOverview {
  private readonly router = inject(Router); private readonly realtime = inject(Realtime); readonly nodes = signal<CemNodeData[]>([]); readonly loading = signal(true); readonly error = signal(''); readonly total = signal(0); readonly missingAfter = signal(86400); readonly date = dateTime;
  search = ''; includeMissing = false; sort = 'hostname'; page = 1; pageSize = 20; private pageSizeChosen = false; private loadId = 0; private subscription?: LiveSubscription<CemNodePage>;
  constructor() {
    inject(DestroyRef).onDestroy(() => { this.subscription?.close(); ++this.loadId; });
    void this.load();
  }
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
    try {
      this.subscription?.close();
      this.subscription = this.realtime.watch<CemNodePage>('/cem/nodes', query, data => {
        if (request !== this.loadId) return;
        this.nodes.set(data.items); this.total.set(data.total); this.page = data.page;
        this.pageSize = data.pageSize; this.missingAfter.set(data.missingAfterSeconds); this.error.set('');
      }, error => { if (request === this.loadId) this.error.set(errorMessage(error)); });
      await this.subscription.ready;
    }
    catch (error) { if (request === this.loadId) this.error.set(errorMessage(error)); } finally { if (request === this.loadId) this.loading.set(false); }
  }
}
