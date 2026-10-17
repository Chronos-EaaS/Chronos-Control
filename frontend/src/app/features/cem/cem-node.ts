import { Component, DestroyRef, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { Api, errorMessage } from '../../core/api';
import { CemNodeDetail, formatBytes, formatPercent } from '../../core/admin-models';
import { dateTime } from '../../core/time';
import { Feedback } from '../../shared/feedback';

@Component({
  imports: [RouterLink, Feedback],
  template: `<div class="page-heading"><div><span class="eyebrow">CEM NODE</span><h1 class="text-break">{{ detail()?.node?.hostname ?? 'Node details' }}</h1><p class="text-secondary">Live status and execution history.</p></div><div class="d-flex flex-wrap gap-2"><a routerLink="/cem" class="btn btn-outline-secondary">All nodes</a><button class="btn btn-outline-primary" (click)="load()" [disabled]="loading()">Refresh status</button></div></div>
    <app-feedback [busy]="loading()" [error]="error()" />
    @if (detail(); as data) { <div class="row g-4"><div class="col-xl-7"><section class="card"><div class="card-header"><h2 class="card-title">Node information</h2></div><div class="card-body"><dl class="row mb-0">
      <dt class="col-sm-4">Hostname</dt><dd class="col-sm-8 text-break">{{ data.node.hostname }}</dd><dt class="col-sm-4">Node ID</dt><dd class="col-sm-8 text-break"><code>{{ data.node.id }}</code></dd><dt class="col-sm-4">Environment</dt><dd class="col-sm-8">{{ data.node.environment }}</dd><dt class="col-sm-4">Agent version</dt><dd class="col-sm-8">{{ data.node.version || 'Unknown' }}</dd><dt class="col-sm-4">Operating system</dt><dd class="col-sm-8 text-break">{{ data.node.os || 'Unknown' }}</dd><dt class="col-sm-4">IP address</dt><dd class="col-sm-8 text-break">{{ data.node.ip || 'Unknown' }}</dd><dt class="col-sm-4">Last status update</dt><dd class="col-sm-8"><time [attr.datetime]="data.node.lastSeenAt">{{ date(data.node.lastSeenAt, true) }}</time><span class="text-secondary"> · {{ age(data.node.lastSeenAt) }}</span></dd><dt class="col-sm-4">Execution state</dt><dd class="col-sm-8">@if (data.node.missing) { <span class="badge text-bg-danger">Missing</span> } @else if (data.node.currentJobId !== null) { <a [routerLink]="['/jobs', data.node.currentJobId]">Running job #{{ data.node.currentJobId }}</a> } @else { <span class="badge text-bg-success">Idle</span> }</dd>
    </dl></div></section></div><div class="col-xl-5"><section class="card"><div class="card-header"><h2 class="card-title">Resource utilization</h2></div><div class="card-body"><div class="d-flex justify-content-between mb-2"><strong>CPU</strong><span>{{ percent(data.node.cpuPercent) }}</span></div>
      @if (data.node.cpuPercent !== null) { <div class="progress mb-4" role="progressbar" aria-label="CPU utilization" [attr.aria-valuenow]="data.node.cpuPercent" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar" [class.bg-success]="data.node.cpuPercent <= 35" [class.bg-warning]="data.node.cpuPercent > 35 && data.node.cpuPercent <= 50" [class.bg-danger]="data.node.cpuPercent > 50" [style.width.%]="data.node.cpuPercent"></div></div> }
      <div class="d-flex justify-content-between mb-2"><strong>Memory</strong><span>{{ percent(data.node.memoryPercent) }}</span></div>
      @if (data.node.memoryPercent !== null) { <div class="progress mb-2" role="progressbar" aria-label="Memory utilization" [attr.aria-valuenow]="data.node.memoryPercent" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar" [class.bg-success]="data.node.memoryPercent <= 60" [class.bg-warning]="data.node.memoryPercent > 60 && data.node.memoryPercent <= 85" [class.bg-danger]="data.node.memoryPercent > 85" [style.width.%]="data.node.memoryPercent"></div></div> }
      <p class="form-text mb-0">{{ bytes(data.node.memoryUsedBytes) }} used / {{ bytes(data.node.memoryTotalBytes) }} total</p></div></section><section class="card mt-4"><div class="card-header"><h2 class="card-title">Health status</h2></div><div class="card-body">@if (data.node.warning) { <div class="alert alert-warning mb-0" style="white-space:pre-wrap;overflow-wrap:anywhere">{{ data.node.healthStatus }}</div> } @else { <p class="text-success mb-0">OK — no health warnings reported.</p> }</div></section></div>
      <div class="col-12"><section class="card activity-card"><div class="card-header"><h2 class="card-title">Node event history</h2></div><div class="card-body">@for (event of data.events; track event.id) { <article class="activity-entry"><span class="activity-dot" aria-hidden="true"></span><div><h3 class="h6 mb-1">{{ event.title }}</h3><p class="mb-1" style="white-space:pre-wrap">{{ event.text }}</p><div class="activity-meta"><time [attr.datetime]="event.at">{{ date(event.at, true) }}</time>@if (event.actorName) { <span> · {{ event.actorName }}</span> }@if (event.resourceType === 'node') { <span> · <a [routerLink]="['/cem/nodes', data.node.id]">Node {{ data.node.hostname }}</a></span> }@if (event.jobId) { <span> · <a [routerLink]="['/jobs', event.jobId]">Job #{{ event.jobId }}</a></span> }</div></div></article> } @empty { <p class="text-secondary mb-0">No events recorded for this node.</p> }</div></section></div>
    </div> }`,
})
export class CemNode {
  private readonly api = inject(Api); readonly detail = signal<CemNodeDetail | null>(null); readonly loading = signal(true); readonly error = signal(''); readonly date = dateTime; readonly bytes = formatBytes; readonly percent = formatPercent;
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
