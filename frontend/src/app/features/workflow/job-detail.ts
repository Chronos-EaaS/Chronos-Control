import { Component, DestroyRef, ElementRef, ViewChild, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { Api, errorMessage } from '../../core/api';
import { Activity, PageData } from '../../core/models';
import { Job } from '../../core/workflow-models';
import { UnsavedChanges } from '../../core/unsaved';
import { dateTime } from '../../core/time';
import { Feedback } from '../../shared/feedback';
import { ActivityList } from '../../shared/activity';
import { ResultsExplorer } from '../results/results-explorer';
import { ansiSpans } from '../../core/ansi';

interface LogChunk { text: string; offset: number; nextOffset: number; complete: boolean; }
@Component({ imports: [FormsModule, RouterLink, Feedback, ActivityList, ResultsExplorer], templateUrl: './job-detail.html' })
export class JobDetail {
  private readonly api = inject(Api); private readonly route = inject(ActivatedRoute);
  readonly changes = inject(UnsavedChanges).scope(inject(DestroyRef)); readonly formatTime = dateTime;
  @ViewChild('cdlDialog') private cdlDialog!: ElementRef<HTMLDialogElement>;
  @ViewChild('logViewer') private logViewer?: ElementRef<HTMLPreElement>;
  readonly job = signal<Job | null>(null); readonly events = signal<Activity[]>([]); readonly log = signal(''); readonly cdl = signal('');
  readonly logSpans = computed(() => ansiSpans(this.log() || 'No log output yet.'));
  readonly busy = signal(true); readonly saving = signal(false); readonly error = signal(''); readonly success = signal(''); readonly cdlError = signal(''); readonly cdlBusy = signal(false); readonly logError = signal('');
  readonly phaseNames = ['Prepare', 'Warm-up', 'Execute', 'Analyze', 'Clean'];
  description = ''; followLog = true; autoUpdateLog = true; private id = 0; private offset = 0; private refreshing = false; private generation = 0; private logGeneration = 0;
  constructor() {
    const timer = setInterval(() => { if (!document.hidden && !this.saving() && !this.refreshing) void this.refresh(); }, 3000);
    inject(DestroyRef).onDestroy(() => { clearInterval(timer); this.generation++; this.changes.setDirty(false); });
    this.route.paramMap.pipe(takeUntilDestroyed()).subscribe(params => { this.id = Number(params.get('id')); this.generation++; this.changes.reset(); this.saving.set(false); this.offset = 0; this.log.set(''); this.job.set(null); void this.refresh(true); });
  }
  async refresh(initial = false): Promise<void> {
    if (this.refreshing && !initial) return; this.refreshing = true; const generation = this.generation;
    if (initial) { this.busy.set(true); this.error.set(''); }
    try {
      const job = await this.api.get<Job>(`/jobs/${this.id}`); if (generation !== this.generation) return;
      this.job.set(job); if (!this.changes.dirty()) this.description = job.description;
      const events = await this.api.get<PageData<Activity>>(`/jobs/${this.id}/events`); if (generation !== this.generation) return; this.events.set(events.items);
      if (initial || this.autoUpdateLog) await this.loadLog(generation);
    } catch (error) { if (generation === this.generation) this.error.set(errorMessage(error)); }
    finally { if (generation === this.generation) { this.refreshing = false; this.busy.set(false); } }
  }
  async loadLog(generation = this.generation): Promise<void> {
    const offset = this.offset; const logGeneration = this.logGeneration;
    try {
      const chunk = await this.api.get<LogChunk>(`/jobs/${this.id}/log`, { offset }); if (generation !== this.generation || logGeneration !== this.logGeneration || offset !== this.offset) return;
      this.log.set((chunk.offset !== this.offset ? '' : this.log()) + chunk.text); this.offset = chunk.nextOffset; this.logError.set('');
      // Keep the visible tail bounded while the server retains the complete log.
      if (this.log().length > 500_000) this.log.set('… earlier lines are available in the full log download …\n' + this.log().slice(-400_000));
      if (this.followLog) setTimeout(() => { const viewer = this.logViewer?.nativeElement; if (viewer) viewer.scrollTop = viewer.scrollHeight; });
    } catch (error) { if (generation === this.generation && logGeneration === this.logGeneration) this.logError.set(errorMessage(error)); }
  }
  phaseIncluded(index: number): boolean { return !((this.job()?.phases ?? 0) & (1 << index)); }
  phaseProgress(index: number): number {
    const job = this.job(); if (!job || !this.phaseIncluded(index)) return 0;
    if (job.statusName === 'finished') return 100;
    const current = (job.currentPhase ?? 0) - 1;
    return index < current ? 100 : index === current ? Math.max(0, Math.min(100, job.progress ?? 0)) : 0;
  }
  async save(): Promise<void> { await this.mutate(async current => { const value = await this.api.patch<Job>(`/jobs/${this.id}`, { description: this.description }); if (!current()) return; this.job.set(value); this.changes.setDirty(false); }, 'Job description saved.'); }
  async setStatus(status: number): Promise<void> {
    if (!window.confirm(status === 0 ? 'Reschedule this job and reset its phase and progress?' : 'Abort this job?')) return;
    await this.mutate(async current => { await this.api.patch(`/jobs/${this.id}`, { status }); if (current()) await this.refresh(); }, status === 0 ? 'Job rescheduled.' : 'Job aborted.');
  }
  async clearLog(): Promise<void> {
    if (!window.confirm('Clear the log for this job? This cannot be undone.')) return;
    await this.mutate(async current => { await this.api.delete(`/jobs/${this.id}/log`); if (!current()) return; ++this.logGeneration; this.log.set(''); this.offset = 0; }, 'Job log cleared.');
  }
  async showCdl(): Promise<void> { this.cdl.set(''); this.cdlError.set(''); this.cdlBusy.set(true); this.cdlDialog.nativeElement.showModal(); try { this.cdl.set((await this.api.get<{ xml: string }>(`/jobs/${this.id}/cdl`)).xml); } catch (error) { this.cdlError.set(errorMessage(error)); } finally { this.cdlBusy.set(false); } }
  private async mutate(action: (current: () => boolean) => Promise<void>, message: string): Promise<void> { if (this.saving()) return; const current = this.changes.checkpoint(); this.saving.set(true); this.error.set(''); this.success.set(''); try { await action(current); if (current()) this.success.set(message); } catch (error) { if (current()) this.error.set(errorMessage(error)); } finally { if (current()) this.saving.set(false); } }
}
