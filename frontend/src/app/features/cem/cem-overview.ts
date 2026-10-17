import { Component, DestroyRef, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { Api, errorMessage } from '../../core/api';
import { CemNodeData, CemNodePage } from '../../core/admin-models';
import { dateTime } from '../../core/time';
import { Feedback } from '../../shared/feedback';

@Component({
  imports: [FormsModule, RouterLink, Feedback],
  template: `<div class="page-heading"><div><span class="eyebrow">EXECUTION INFRASTRUCTURE</span><h1>CEM nodes</h1><p class="text-secondary">Inspect connected nodes, running jobs and health warnings.</p></div><button class="btn btn-outline-primary" (click)="load()" [disabled]="loading()">Refresh nodes</button></div>
    <app-feedback [busy]="loading()" [error]="error()" />
    <section class="card"><div class="card-body"><form class="table-toolbar" (ngSubmit)="page = 1; load()"><div class="table-search"><label class="visually-hidden" for="node-search">Search nodes</label><input id="node-search" class="form-control" name="search" [(ngModel)]="search" type="search" placeholder="Search hostname, ID or environment…" /></div><div class="d-flex flex-wrap gap-3 align-items-center"><div class="form-check"><input id="include-missing" class="form-check-input" name="includeMissing" type="checkbox" [(ngModel)]="includeMissing" (ngModelChange)="page = 1; load()" /><label class="form-check-label" for="include-missing">Include missing nodes</label></div><button class="btn btn-outline-primary">Search</button></div></form>
      <div class="table-responsive"><table class="table align-middle"><caption class="visually-hidden">CEM execution nodes</caption><thead><tr><th scope="col"><button class="table-sort" (click)="sortBy('hostname')">Hostname {{ sortMark('hostname') }}</button></th><th scope="col"><button class="table-sort" (click)="sortBy('id')">Node ID {{ sortMark('id') }}</button></th><th scope="col"><button class="table-sort" (click)="sortBy('environment')">Environment {{ sortMark('environment') }}</button></th><th scope="col">Status</th><th scope="col"><button class="table-sort" (click)="sortBy('updated')">Last seen {{ sortMark('updated') }}</button></th></tr></thead><tbody>
        @for (node of nodes(); track node.id) { <tr><th scope="row"><a [routerLink]="['/cem/nodes', node.id]">{{ node.hostname }}</a>@if (node.warning) { <small class="d-block text-warning-emphasis">Health warning</small> }</th><td class="text-break"><code>{{ node.id }}</code></td><td>{{ node.environment }}</td><td>@if (node.missing) { <span class="badge text-bg-danger">Missing</span> } @else if (node.currentJobId !== null) { <a [routerLink]="['/jobs', node.currentJobId]" class="badge text-bg-primary">Job #{{ node.currentJobId }}</a> } @else { <span class="badge text-bg-success">Idle</span> }</td><td class="text-nowrap">{{ date(node.lastSeenAt, true) }}</td></tr> }
        @empty { @if (!loading()) { <tr><td colspan="5" class="empty-state">No nodes match your filters.</td></tr> } }
      </tbody></table></div><div class="table-pagination"><div class="d-flex gap-2 align-items-center"><label for="node-page-size">Rows per page</label><select id="node-page-size" class="form-select form-select-sm w-auto" [(ngModel)]="pageSize" (ngModelChange)="sizeChanged()">@for (size of sizes(); track size) { <option [ngValue]="size">{{ size }}</option> }</select></div><span>{{ total() }} nodes · Page {{ page }} of {{ pages() }}</span><div class="d-flex gap-2"><button class="btn btn-outline-secondary" [disabled]="page <= 1 || loading()" (click)="page = page - 1; load()">Previous</button><button class="btn btn-outline-secondary" [disabled]="page >= pages() || loading()" (click)="page = page + 1; load()">Next</button></div></div>
      <p class="form-text mb-0 mt-3">Nodes are considered missing after {{ missingAfter() }} seconds without an update. Times use Europe/Zurich.</p>
    </div></section>`,
})
export class CemOverview {
  private readonly api = inject(Api); readonly nodes = signal<CemNodeData[]>([]); readonly loading = signal(true); readonly error = signal(''); readonly total = signal(0); readonly missingAfter = signal(86400); readonly date = dateTime;
  search = ''; includeMissing = false; sort = 'hostname'; page = 1; pageSize = 20; private pageSizeChosen = false; private loadId = 0;
  constructor() { const timer = window.setInterval(() => { if (document.visibilityState === 'visible' && !this.loading()) void this.load(); }, 30_000); inject(DestroyRef).onDestroy(() => { clearInterval(timer); ++this.loadId; }); void this.load(); }
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
