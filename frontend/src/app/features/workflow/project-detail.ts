import { Component, DestroyRef, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { Api, errorMessage } from '../../core/api';
import { LiveSubscription, Realtime } from '../../core/realtime';
import { Activity, PageData, Project } from '../../core/models';
import { Evaluation, Experiment, Member } from '../../core/workflow-models';
import { UnsavedChanges } from '../../core/unsaved';
import { Icon } from '../../shared/icon';
import { Feedback } from '../../shared/feedback';
import { ActivityList } from '../../shared/activity';
import { DataColumn, DataGrid, localGridRows } from '../../shared/data-grid';
import { SystemExtensions } from '../extensions/system-extensions';

@Component({
  imports: [FormsModule, RouterLink, Feedback, Icon, ActivityList, DataGrid, SystemExtensions],
  templateUrl: './project-detail.html',
  styleUrl: './project-detail.scss',
})
export class ProjectDetail {
  private readonly api = inject(Api); private readonly route = inject(ActivatedRoute); private readonly realtime = inject(Realtime);
  readonly changes = inject(UnsavedChanges).scope(inject(DestroyRef));
  readonly project = signal<Project | null>(null); readonly error = signal(''); readonly busy = signal(true); readonly saving = signal(false); readonly success = signal('');
  readonly experiments = signal<Experiment[]>([]); readonly evaluations = signal<Evaluation[]>([]); readonly members = signal<Member[]>([]); readonly candidates = signal<Member[]>([]); readonly events = signal<Activity[]>([]);
  readonly activityError = signal('');
  readonly runningEvaluations = computed(() => this.evaluations().filter(row => (row.counts?.active ?? 0) + (row.counts?.failed ?? 0) > 0));
  readonly starredEvaluations = computed(() => this.evaluations().filter(row => row.starred));
  readonly experimentLink = (row: Experiment): string[] => ['/experiments', String(row.id)];
  readonly evaluationLink = (row: Evaluation): string[] => ['/evaluations', String(row.id)];
  readonly experimentPageSize = signal(25); readonly evaluationPageSize = signal(25);
  readonly experimentColumns: DataColumn<Experiment>[] = [
    { key: 'id', label: 'ID', value: row => row.internalId }, { key: 'name', label: 'Name', value: row => row.name },
    { key: 'description', label: 'Description', value: row => row.description, className: 'description' },
    { key: 'actions', label: 'Actions', value: () => '', sortable: false, searchable: false },
  ];
  readonly evaluationColumns: DataColumn<Evaluation>[] = [
    { key: 'id', label: 'ID', value: row => row.experimentInternalId ? `${row.experimentInternalId}.${row.internalId}` : String(row.internalId) },
    { key: 'name', label: 'Name', value: row => row.name },
    { key: 'description', label: 'Description', value: row => row.description, className: 'description' },
  ];
  readonly evaluationSortColumns: DataColumn<Evaluation>[] = [
    { key: 'experiment', label: 'Experiment', value: row => row.experimentName },
    { key: 'starred', label: 'Starred', value: row => row.starred ? 'Starred' : 'Not starred' },
  ];
  name = ''; description = ''; archived = 'false'; memberId: number | null = null;
  private id = 0; private sequence = 0; private experimentsSequence = 0;
  private activitySubscription?: LiveSubscription<PageData<Activity>>;
  private activityId = 0; private activitySequence = 0;
  constructor() {
    inject(DestroyRef).onDestroy(() => { ++this.sequence; ++this.activitySequence; this.activitySubscription?.close(); });
    this.route.paramMap.pipe(takeUntilDestroyed()).subscribe(params => { this.id = Number(params.get('id')); this.changes.reset(); this.saving.set(false); this.project.set(null); void this.load(); });
  }
  async load(): Promise<void> {
    const sequence = ++this.sequence, experimentsSequence = ++this.experimentsSequence, archived = this.archived; this.busy.set(true); this.error.set('');
    this.watchActivity();
    try {
      const project = await this.api.get<Project>(`/projects/${this.id}`);
      if (sequence !== this.sequence) return;
      this.project.set(project); if (!this.changes.dirty()) { this.name = project.name; this.description = project.description; }
      const tasks = await Promise.allSettled([
        localGridRows<Experiment>(this.api, `/projects/${this.id}/experiments`, { archived }),
        localGridRows<Evaluation>(this.api, `/projects/${this.id}/evaluations`, { state: 'running-or-starred', archived: 'all' }),
        this.api.get<{ items: Member[] }>(`/projects/${this.id}/members`),
        project.permissions.canManageMembers ? this.api.get<{ items: Member[] }>(`/projects/${this.id}/members`, { candidates: true }) : Promise.resolve({ items: [] as Member[] }),
      ]);
      if (sequence !== this.sequence) return;
      const [experiments, evaluations, members, candidates] = tasks;
      if (experiments.status === 'fulfilled' && experimentsSequence === this.experimentsSequence && archived === this.archived) { this.experiments.set(experiments.value.rows); this.experimentPageSize.set(experiments.value.pageSize); }
      if (evaluations.status === 'fulfilled') { this.evaluations.set(evaluations.value.rows); this.evaluationPageSize.set(evaluations.value.pageSize); }
      if (members.status === 'fulfilled') this.members.set(members.value.items);
      if (candidates.status === 'fulfilled') this.candidates.set(candidates.value.items);
      const failed = tasks.filter((task, index) => task.status === 'rejected' && (index !== 0 || experimentsSequence === this.experimentsSequence && archived === this.archived));
      if (failed.length) this.error.set(failed.map(task => errorMessage((task as PromiseRejectedResult).reason)).join(' '));
    } catch (error) { if (sequence === this.sequence) this.error.set(errorMessage(error)); }
    finally { if (sequence === this.sequence) this.busy.set(false); }
  }
  private watchActivity(): void {
    if (this.activitySubscription && this.activityId === this.id) return;
    this.activitySubscription?.close();
    const sequence = ++this.activitySequence;
    this.activityId = this.id; this.events.set([]); this.activityError.set('');
    this.activitySubscription = this.realtime.watch<PageData<Activity>>(`/projects/${this.id}/events`, {}, data => {
      if (sequence === this.activitySequence) { this.events.set(data.items); this.activityError.set(''); }
    }, error => { if (sequence === this.activitySequence) this.activityError.set(errorMessage(error)); });
    // Activity is independent of the editor and other cards, including its first snapshot.
    void this.activitySubscription.ready.catch(() => {});
  }
  async save(): Promise<void> {
    await this.mutate(async current => { const project = await this.api.patch<Project>(`/projects/${this.id}`, { name: this.name, description: this.description }); if (!current()) return; this.project.set(project); this.changes.setDirty(false); }, 'Project saved.');
  }
  async toggleArchive(): Promise<void> {
    const project = this.project(); if (!project || !this.changes.mayDiscard() || !window.confirm(`${project.archived ? 'Restore' : 'Archive'} this project?`)) return;
    await this.mutate(async current => { await this.api.patch(`/projects/${this.id}`, { archived: !project.archived }); if (!current()) return; this.changes.setDirty(false); await this.load(); }, project.archived ? 'Project restored.' : 'Project archived.');
  }
  async toggleExperiment(experiment: Experiment): Promise<void> {
    if (!window.confirm(`${experiment.archived ? 'Restore' : 'Archive'} ${experiment.name}?`)) return;
    await this.mutate(async current => { await this.api.patch(`/experiments/${experiment.id}`, { archived: !experiment.archived }); if (current()) await this.reloadExperiments(); }, experiment.archived ? 'Experiment restored.' : 'Experiment archived.');
  }
  async reloadExperiments(): Promise<void> { const current = this.changes.checkpoint(), sequence = ++this.experimentsSequence, archived = this.archived; try { const data = await localGridRows<Experiment>(this.api, `/projects/${this.id}/experiments`, { archived }); if (!current() || sequence !== this.experimentsSequence || archived !== this.archived) return; this.experiments.set(data.rows); this.experimentPageSize.set(data.pageSize); } catch (error) { if (current() && sequence === this.experimentsSequence && archived === this.archived) this.error.set(errorMessage(error)); } }
  async addMember(): Promise<void> {
    if (this.memberId === null) return;
    await this.mutate(async current => { await this.api.post(`/projects/${this.id}/members`, { userId: this.memberId }); if (!current()) return; this.memberId = null; await this.load(); }, 'Member added.');
  }
  async removeMember(member: Member): Promise<void> {
    if (!window.confirm(`Remove ${member.firstName} ${member.lastName} from this project?`)) return;
    await this.mutate(async current => { await this.api.delete(`/projects/${this.id}/members/${member.id}`); if (current()) await this.load(); }, 'Member removed.');
  }
  private async mutate(operation: (current: () => boolean) => Promise<void>, message: string): Promise<void> {
    if (this.saving()) return; const current = this.changes.checkpoint(); this.saving.set(true); this.error.set(''); this.success.set('');
    try { await operation(current); if (current()) this.success.set(message); } catch (error) { if (current()) this.error.set(errorMessage(error)); } finally { if (current()) this.saving.set(false); }
  }
}
