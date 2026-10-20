import { Component, DestroyRef, computed, effect, inject, signal, untracked } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { errorMessage } from '../core/api';
import { LiveSubscription, Realtime } from '../core/realtime';
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
  private readonly realtime = inject(Realtime);
  readonly session = inject(Session);
  readonly data = signal<DashboardData | null>(null); readonly busy = signal(true); readonly error = signal('');
  all = false; private sequence = 0;
  private subscription?: LiveSubscription<DashboardData>;
  private readonly actor = computed(() => {
    const user = this.session.user();
    return user ? `${user.id}:${user.role}` : '';
  });
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
      const actor = this.actor();
      // Display-name and other profile updates keep the administrator's chosen
      // scope. A changed account or role replaces the authorized subscription.
      untracked(() => {
        ++this.sequence; this.subscription?.close(); this.subscription = undefined;
        this.all = false; this.data.set(null);
        if (actor) void this.load(); else this.busy.set(false);
      });
    });
    inject(DestroyRef).onDestroy(() => { ++this.sequence; this.subscription?.close(); });
  }
  scopeChanged(value: boolean): void { this.all = this.session.isAdmin() && value; this.data.set(null); void this.load(); }
  async load(): Promise<void> {
    const sequence = ++this.sequence; this.busy.set(true); this.error.set('');
    const all = this.session.isAdmin() && this.all;
    try {
      this.subscription?.close();
      this.subscription = this.realtime.watch<DashboardData>('/dashboard', { all }, data => {
        if (sequence !== this.sequence) return;
        this.data.set(data); this.error.set('');
      }, error => {
        if (sequence === this.sequence) { this.error.set(errorMessage(error)); this.busy.set(false); }
      });
      await this.subscription.ready;
    }
    catch (error) { if (sequence === this.sequence) this.error.set(errorMessage(error)); }
    finally { if (sequence === this.sequence) this.busy.set(false); }
  }
}
