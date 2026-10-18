import { Component, DestroyRef, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { Api, errorMessage } from '../../core/api';
import { PageData } from '../../core/models';
import { Evaluation, Job } from '../../core/workflow-models';
import { Session } from '../../core/session';
import { Feedback } from '../../shared/feedback';

@Component({
  imports: [FormsModule, RouterLink, Feedback],
  templateUrl: './status.html',
})
export class Status {
  private readonly api = inject(Api); readonly session = inject(Session);
  readonly busy = signal(true); readonly error = signal(''); readonly total = signal(0); readonly evaluations = signal<Evaluation[]>([]); readonly jobs = signal<Job[]>([]);
  kind: 'evaluations' | 'jobs' = 'evaluations'; all = false; state = 'active'; search = ''; sort = 'hierarchy'; page = 1; pageSize = 25; private pageSizeChosen = false; private sequence = 0;
  constructor() { if (inject(ActivatedRoute).snapshot.queryParamMap.get('view') === 'jobs') { this.kind = 'jobs'; this.sort = 'id'; } void this.load(); const timer = setInterval(() => { if (!document.hidden && !this.busy()) void this.load(); }, 5000); inject(DestroyRef).onDestroy(() => { ++this.sequence; clearInterval(timer); }); }
  switchView(kind: 'evaluations' | 'jobs'): void { this.kind = kind; this.sort = kind === 'evaluations' ? 'hierarchy' : 'id'; this.state = 'active'; this.page = 1; this.pageSizeChosen = false; void this.load(); }
  columns(): { key: string; label: string }[] { return this.kind === 'evaluations' ? [
    { key: 'hierarchy', label: 'ID' }, { key: 'name', label: 'Name' }, { key: 'experiment', label: 'Experiment' }, { key: 'project', label: 'Project' }, { key: 'system', label: 'System' }, { key: 'status', label: 'Status' },
  ] : [ { key: 'id', label: 'ID' }, { key: 'description', label: 'Description' }, { key: 'evaluation', label: 'Evaluation' }, { key: 'experiment', label: 'Experiment' }, { key: 'project', label: 'Project' }, { key: 'owner', label: 'User' }, { key: 'type', label: 'Type' }, { key: 'system', label: 'System' }, { key: 'status', label: 'Status' }, { key: 'progress', label: 'Progress' } ]; }
  sortState(key: string): string { return this.sort === key ? 'ascending' : this.sort === `-${key}` ? 'descending' : 'none'; }
  sortMark(key: string): string { return this.sort === key ? '↑' : this.sort === `-${key}` ? '↓' : ''; }
  progress(value: number | null): number { return Math.max(0, Math.min(100, value ?? 0)); }
  sizes(): number[] { return [...new Set([5, 10, 25, 50, 100, this.pageSize])].sort((a, b) => a - b); }
  sizeChanged(): void { this.pageSizeChosen = true; this.filter(); }
  filter(): void { this.page = 1; void this.load(); }
  sortBy(key: string): void { this.sort = this.sort === key ? `-${key}` : key; this.page = 1; void this.load(); }
  changePage(delta: number): void { this.page += delta; void this.load(); }
  async load(): Promise<void> {
    const sequence = ++this.sequence; this.busy.set(true); this.error.set(''); const kind = this.kind;
    const query: Record<string, string | number | boolean> = { all: this.all, search: this.search, sort: this.sort, page: this.page, ...(kind === 'jobs' ? { status: this.state } : { state: this.state === 'active' ? 'running' : 'all' }) }; if (this.pageSizeChosen) query['pageSize'] = this.pageSize;
    try { const data = await this.api.get<PageData<Evaluation | Job>>(`/${kind}`, query); if (sequence !== this.sequence) return; if (kind === 'evaluations') this.evaluations.set(data.items as Evaluation[]); else this.jobs.set(data.items as Job[]); this.total.set(data.total); this.page = data.page; this.pageSize = data.pageSize; }
    catch (error) { if (sequence === this.sequence) this.error.set(errorMessage(error)); } finally { if (sequence === this.sequence) this.busy.set(false); }
  }
}
