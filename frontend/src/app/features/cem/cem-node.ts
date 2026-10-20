import { Component, DestroyRef, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { errorMessage } from '../../core/api';
import { LiveSubscription, Realtime } from '../../core/realtime';
import { CemNodeDetail, formatBytes, formatPercent } from '../../core/admin-models';
import { dateTime } from '../../core/time';
import { Feedback } from '../../shared/feedback';
import { ActivityList } from '../../shared/activity';
import { Activity } from '../../core/models';

@Component({
  imports: [RouterLink, Feedback, ActivityList],
  templateUrl: './cem-node.html',
  styleUrl: './cem-node.scss',
})
export class CemNode {
  private readonly realtime = inject(Realtime); readonly detail = signal<CemNodeDetail | null>(null); readonly loading = signal(true); readonly error = signal(''); readonly date = dateTime; readonly bytes = formatBytes; readonly percent = formatPercent;
  events(data: CemNodeDetail): Activity[] {
    return data.events.map(event => {
      const resources = [...(event.resources ?? [])];
      if (!resources.some(resource => resource.type === 'node' && resource.id === data.node.id)) resources.push({ type: 'node', id: data.node.id, label: `Node ${data.node.hostname}` });
      if (event.jobId && !resources.some(resource => resource.type === 'job' && resource.id === event.jobId)) resources.push({ type: 'job', id: event.jobId, label: `Job #${event.jobId}` });
      return { ...event, resources: resources.map(resource => resource.type === 'node' && resource.id === data.node.id ? { ...resource, label: `Node ${data.node.hostname}` } : resource) };
    });
  }
  age(value: string | null | undefined): string {
    if (!value) return 'Unknown'; const seconds = (new Date(value).getTime() - Date.now()) / 1000; if (!Number.isFinite(seconds)) return 'Unknown';
    const unit: Intl.RelativeTimeFormatUnit = Math.abs(seconds) < 60 ? 'second' : Math.abs(seconds) < 3600 ? 'minute' : Math.abs(seconds) < 86400 ? 'hour' : 'day';
    const divisor = { second: 1, minute: 60, hour: 3600, day: 86400 }[unit as 'second' | 'minute' | 'hour' | 'day'];
    return new Intl.RelativeTimeFormat('en', { numeric: 'auto' }).format(Math.round(seconds / divisor), unit);
  }
  private id = ''; private loadId = 0; private subscription?: LiveSubscription<CemNodeDetail>;
  constructor() {
    inject(ActivatedRoute).paramMap.pipe(takeUntilDestroyed()).subscribe(params => { this.id = params.get('id') ?? ''; this.detail.set(null); void this.load(); });
    inject(DestroyRef).onDestroy(() => { this.subscription?.close(); ++this.loadId; });
  }
  async load(): Promise<void> {
    const request = ++this.loadId; this.loading.set(true); this.error.set('');
    this.subscription?.close(); this.subscription = undefined;
    if (!this.id) { this.error.set('Choose a valid node.'); this.loading.set(false); return; }
    try {
      this.subscription = this.realtime.watch<CemNodeDetail>(`/cem/nodes/${encodeURIComponent(this.id)}`, {}, data => {
        if (request === this.loadId) { this.detail.set(data); this.error.set(''); }
      }, error => { if (request === this.loadId) this.error.set(errorMessage(error)); });
      await this.subscription.ready;
    }
    catch (error) { if (request === this.loadId) this.error.set(errorMessage(error)); } finally { if (request === this.loadId) this.loading.set(false); }
  }
}
