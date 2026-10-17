import { Component, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { Api, errorMessage } from '../core/api';
import { DashboardData } from '../core/models';
import { Session } from '../core/session';
import { dateTime } from '../core/time';
import { Feedback } from '../shared/feedback';
import { Icon } from '../shared/icon';
import { ActivityList } from '../shared/activity';

@Component({
  imports: [RouterLink, Feedback, Icon, ActivityList],
  template: `<div class="page-heading"><div><span class="eyebrow">WORKSPACE OVERVIEW</span><h1>Your evaluations, at a glance.</h1><p class="text-secondary">Welcome back, {{ session.user()?.firstName || session.user()?.username }}.</p></div><a class="btn btn-primary" routerLink="/projects"><app-icon name="projects" /> Open projects</a></div>
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
  readonly formatTime = dateTime;
  readonly cards: { key: keyof DashboardData['counts']; label: string; description: string }[] = [
    { key: 'projects', label: 'Projects', description: 'Evaluation campaigns' },
    { key: 'experiments', label: 'Experiments', description: 'Configured experiments' },
    { key: 'evaluationsRunning', label: 'Running evaluations', description: 'Scheduled and active work' },
    { key: 'evaluationsCompleted', label: 'Completed evaluations', description: 'Finished evaluations' },
    { key: 'jobs', label: 'Total jobs', description: 'Across your evaluations' },
    { key: 'activeJobs', label: 'Active jobs', description: 'Scheduled, setup or running' },
    { key: 'failedJobs', label: 'Failed jobs', description: 'Jobs requiring attention' },
  ];
  constructor() { void this.load(); }
  async load(): Promise<void> { try { this.data.set(await this.api.get<DashboardData>('/dashboard')); } catch (error) { this.error.set(errorMessage(error)); } finally { this.busy.set(false); } }
}
