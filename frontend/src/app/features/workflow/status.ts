import { Component, DestroyRef, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { errorMessage } from '../../core/api';
import { LiveSubscription, Realtime } from '../../core/realtime';
import { PageData } from '../../core/models';
import { Evaluation, Job } from '../../core/workflow-models';
import { Session } from '../../core/session';
import { Feedback } from '../../shared/feedback';

@Component({
  imports: [FormsModule, RouterLink, Feedback],
  templateUrl: './status.html',
  styleUrl: './workflow-reference.scss',
})
export class Status {
  private readonly rowRouter = inject(Router, { optional: true });
  private readonly realtime = inject(Realtime);
  readonly session = inject(Session);
  readonly busy = signal(true);
  readonly error = signal('');
  readonly total = signal(0);
  readonly evaluations = signal<Evaluation[]>([]);
  readonly jobs = signal<Job[]>([]);
  kind: 'evaluations' | 'jobs' = 'evaluations';
  all = false;
  state = 'active';
  search = '';
  sort = 'hierarchy';
  page = 1;
  pageSize = 25;
  private pageSizeChosen = false;
  private sequence = 0;
  private subscription?: LiveSubscription<PageData<Evaluation | Job>>;
  constructor() {
    if (inject(ActivatedRoute).snapshot.queryParamMap.get('view') === 'jobs') {
      this.kind = 'jobs';
      this.sort = 'id';
    }
    void this.load();
    inject(DestroyRef).onDestroy(() => {
      ++this.sequence; this.subscription?.close();
    });
  }
  switchView(kind: 'evaluations' | 'jobs'): void {
    this.kind = kind;
    this.sort = kind === 'evaluations' ? 'hierarchy' : 'id';
    this.state = 'active';
    this.page = 1;
    this.pageSizeChosen = false;
    void this.load();
  }
  columns(): { key: string; label: string }[] {
    return this.kind === 'evaluations'
      ? [
          { key: 'hierarchy', label: 'ID' },
          { key: 'name', label: 'Name' },
          { key: 'experiment', label: 'Experiment' },
          { key: 'project', label: 'Project' },
          { key: 'system', label: 'System' },
          { key: 'status', label: 'Status' },
        ]
      : [
          { key: 'id', label: 'ID' },
          { key: 'description', label: 'Description' },
          { key: 'evaluation', label: 'Evaluation' },
          { key: 'experiment', label: 'Experiment' },
          { key: 'project', label: 'Project' },
          { key: 'owner', label: 'User' },
          { key: 'type', label: 'Type' },
          { key: 'system', label: 'System' },
          { key: 'status', label: 'Status' },
          { key: 'progress', label: 'Progress' },
        ];
  }
  visibleColumns(): { key: string; label: string }[] {
    return this.kind === 'jobs'
      ? this.columns().filter(
          (column) => !['description', 'experiment', 'project'].includes(column.key),
        )
      : this.columns();
  }
  openRow(event: MouseEvent, id: number): void {
    if (
      event.defaultPrevented ||
      event.button !== 0 ||
      event.ctrlKey ||
      event.metaKey ||
      event.shiftKey ||
      event.altKey
    )
      return;
    if (
      event.target instanceof Element &&
      event.target.closest('a,button,input,select,textarea,label,[role="button"]')
    )
      return;
    if (window.getSelection()?.toString()) return;
    void this.rowRouter?.navigate(['/', this.kind, id]);
  }
  sortState(key: string): string {
    return this.sort === key ? 'ascending' : this.sort === `-${key}` ? 'descending' : 'none';
  }
  sortMark(key: string): string {
    return this.sort === key ? '↑' : this.sort === `-${key}` ? '↓' : '';
  }
  progress(value: number | null): number {
    return Math.max(0, Math.min(100, value ?? 0));
  }
  sizes(): number[] {
    return [...new Set([5, 10, 25, 50, 100, this.pageSize])].sort((a, b) => a - b);
  }
  sizeChanged(): void {
    this.pageSizeChosen = true;
    this.filter();
  }
  filter(): void {
    this.page = 1;
    void this.load();
  }
  sortBy(key: string): void {
    this.sort = this.sort === key ? `-${key}` : key;
    this.page = 1;
    void this.load();
  }
  changePage(delta: number): void {
    this.page += delta;
    void this.load();
  }
  async load(): Promise<void> {
    const sequence = ++this.sequence;
    this.busy.set(true);
    this.error.set('');
    const kind = this.kind;
    const query: Record<string, string | number | boolean> = {
      all: this.all,
      search: this.search,
      sort: this.sort,
      page: this.page,
      ...(kind === 'jobs'
        ? { status: this.state }
        : { state: this.state === 'active' ? 'running' : 'all' }),
    };
    if (this.pageSizeChosen) query['pageSize'] = this.pageSize;
    try {
      this.subscription?.close();
      this.subscription = this.realtime.watch<PageData<Evaluation | Job>>(`/${kind}`, query, data => {
        if (sequence !== this.sequence) return;
        if (kind === 'evaluations') this.evaluations.set(data.items as Evaluation[]);
        else this.jobs.set(data.items as Job[]);
        this.total.set(data.total); this.page = data.page; this.pageSize = data.pageSize;
        this.error.set('');
      }, error => { if (sequence === this.sequence) this.error.set(errorMessage(error)); });
      await this.subscription.ready;
    } catch (error) {
      if (sequence === this.sequence) this.error.set(errorMessage(error));
    } finally {
      if (sequence === this.sequence) this.busy.set(false);
    }
  }
}
