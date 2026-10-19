import { Component, effect, inject, signal, untracked } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Api, errorMessage } from '../core/api';
import { DashboardData } from '../core/models';
import { Session } from '../core/session';
import { Feedback } from '../shared/feedback';
import { ActivityList } from '../shared/activity';

@Component({
  selector: 'app-dashboard',
  imports: [FormsModule, Feedback, ActivityList],
  templateUrl: './dashboard.html',
  styleUrl: './dashboard.scss',
})
export class Dashboard {
  private readonly api = inject(Api);
  readonly session = inject(Session);
  readonly data = signal<DashboardData | null>(null); readonly busy = signal(true); readonly error = signal('');
  all = false; private sequence = 0;
  readonly cards: { key: keyof DashboardData['counts']; label: string; tone: 'info' | 'success' | 'warning' | 'danger' }[] = [
    { key: 'projects', label: 'Projects', tone: 'info' },
    { key: 'experiments', label: 'Experiments', tone: 'info' },
    { key: 'evaluationsCompleted', label: 'Completed evaluations', tone: 'success' },
    { key: 'evaluationsRunning', label: 'Running evaluations', tone: 'warning' },
    { key: 'jobs', label: 'Total jobs', tone: 'info' },
    { key: 'activeJobs', label: 'Active jobs', tone: 'warning' },
    { key: 'failedJobs', label: 'Failed jobs', tone: 'danger' },
  ];
  constructor() {
    effect(() => {
      this.session.user(); this.session.isAdmin();
      // Returning from impersonation can reuse the same dashboard route. Reset
      // its scope and never render a delayed response for the previous account.
      untracked(() => { this.all = false; this.data.set(null); void this.load(); });
    });
  }
  scopeChanged(value: boolean): void { this.all = this.session.isAdmin() && value; this.data.set(null); void this.load(); }
  async load(): Promise<void> {
    const sequence = ++this.sequence; this.busy.set(true); this.error.set('');
    const all = this.session.isAdmin() && this.all;
    try { const data = await this.api.get<DashboardData>('/dashboard', { all }); if (sequence === this.sequence) this.data.set(data); }
    catch (error) { if (sequence === this.sequence) this.error.set(errorMessage(error)); }
    finally { if (sequence === this.sequence) this.busy.set(false); }
  }
}
