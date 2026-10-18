import { Component, effect, inject, signal, untracked } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { Api, errorMessage } from '../core/api';
import { DashboardData } from '../core/models';
import { Session } from '../core/session';
import { dateTime } from '../core/time';
import { Feedback } from '../shared/feedback';
import { Icon } from '../shared/icon';
import { ActivityList } from '../shared/activity';

@Component({
  imports: [FormsModule, RouterLink, Feedback, Icon, ActivityList],
  template: `<div class="page-heading"><div><span class="eyebrow">WORKSPACE OVERVIEW</span><h1>{{ all ? 'All evaluations, at a glance.' : 'Your evaluations, at a glance.' }}</h1><p class="text-secondary">{{ all ? 'Showing evaluation counts and activity across all users.' : 'Welcome back, ' + (session.user()?.firstName || session.user()?.username) + '.' }}</p></div><div class="d-flex align-items-end flex-wrap gap-3">@if (session.isAdmin()) { <div><label class="form-label" for="dashboard-scope">Overview scope</label><select id="dashboard-scope" class="form-select" [ngModel]="all" (ngModelChange)="scopeChanged($event)" [disabled]="busy()"><option [ngValue]="false">My workspace</option><option [ngValue]="true">All users</option></select></div> }<a class="btn btn-primary" routerLink="/projects"><app-icon name="projects" /> Open projects</a></div></div>
    <app-feedback [busy]="busy()" [error]="error()" />
    @if (data(); as dashboard) {
      <div class="stat-grid">@for (card of cards; track card.key) { <section class="card stat-card"><div class="card-body"><span class="stat-label">{{ card.label }}</span><strong>{{ dashboard.counts[card.key] }}</strong><span class="stat-description">{{ card.description }}</span></div></section> }</div>
      <app-activity [events]="dashboard.events" />
    }`,
})
export class Dashboard {
  private readonly api = inject(Api);
  readonly session = inject(Session);
  readonly data = signal<DashboardData | null>(null); readonly busy = signal(true); readonly error = signal('');
  all = false; private sequence = 0;
  readonly formatTime = dateTime;
  readonly cards: { key: keyof DashboardData['counts']; label: string; description: string }[] = [
    { key: 'projects', label: 'Projects', description: 'Evaluation campaigns' },
    { key: 'experiments', label: 'Experiments', description: 'Configured experiments' },
    { key: 'evaluationsRunning', label: 'Running evaluations', description: 'Scheduled and active work' },
    { key: 'evaluationsCompleted', label: 'Completed evaluations', description: 'Finished evaluations' },
    { key: 'jobs', label: 'Total jobs', description: 'Across the selected workspace' },
    { key: 'activeJobs', label: 'Active jobs', description: 'Scheduled, setup or running' },
    { key: 'failedJobs', label: 'Failed jobs', description: 'Jobs requiring attention' },
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
