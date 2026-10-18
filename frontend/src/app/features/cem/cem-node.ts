import { Component, DestroyRef, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { Api, errorMessage } from '../../core/api';
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
  private readonly api = inject(Api); readonly detail = signal<CemNodeDetail | null>(null); readonly loading = signal(true); readonly error = signal(''); readonly date = dateTime; readonly bytes = formatBytes; readonly percent = formatPercent;
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
  private id = ''; private loadId = 0;
  constructor() {
    inject(ActivatedRoute).paramMap.pipe(takeUntilDestroyed()).subscribe(params => { this.id = params.get('id') ?? ''; this.detail.set(null); void this.load(); });
    const timer = window.setInterval(() => { if (document.visibilityState === 'visible' && !this.loading()) void this.load(); }, 30_000);
    inject(DestroyRef).onDestroy(() => { clearInterval(timer); ++this.loadId; });
  }
  async load(): Promise<void> {
    const request = ++this.loadId; this.loading.set(true); this.error.set('');
    if (!this.id) { this.error.set('Choose a valid node.'); this.loading.set(false); return; }
    try { const data = await this.api.get<CemNodeDetail>(`/cem/nodes/${encodeURIComponent(this.id)}`); if (request === this.loadId) this.detail.set(data); }
    catch (error) { if (request === this.loadId) this.error.set(errorMessage(error)); } finally { if (request === this.loadId) this.loading.set(false); }
  }
}
