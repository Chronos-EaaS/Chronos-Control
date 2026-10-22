import { Component, DestroyRef, computed, effect, inject, input, output, signal, untracked } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Api, ApiError, errorMessage } from '../../core/api';
import { User } from '../../core/models';
import { LiveSubscription, Realtime } from '../../core/realtime';
import { Feedback } from '../../shared/feedback';
import { Icon } from '../../shared/icon';

export interface SystemMaintainer extends Pick<User, 'id' | 'username' | 'firstName' | 'lastName' | 'email' | 'enabled' | 'activated'> {
  eligible: boolean;
}
export interface SystemMaintainersPermissions { canManageMaintainers: boolean; canChangeOwner: boolean; }
export interface SystemMaintainersSnapshot {
  items: SystemMaintainer[];
  ownerId: number;
  permissions: SystemMaintainersPermissions;
}
export interface SystemMaintainersChange {
  systemId: number;
  ownerId: number;
  ownerName?: string;
  permissions: SystemMaintainersPermissions;
}
export interface SystemMaintainersAccessLost { systemId: number; error: ApiError; }

let sequence = 0;

@Component({
  selector: 'app-system-maintainers',
  imports: [FormsModule, Feedback, Icon],
  templateUrl: './system-maintainers.html',
  styleUrl: './system-maintainers.scss',
})
export class SystemMaintainers {
  private readonly api = inject(Api);
  private readonly realtime = inject(Realtime);
  readonly systemId = input.required<number>();
  readonly changed = output<SystemMaintainersChange>();
  readonly accessLost = output<SystemMaintainersAccessLost>();
  readonly namespace = `system-maintainers-${++sequence}`;
  readonly snapshot = signal<SystemMaintainersSnapshot | null>(null);
  readonly candidates = signal<SystemMaintainer[]>([]);
  readonly selectedId = signal<number | null>(null);
  readonly loading = signal(true);
  readonly saving = signal(false);
  readonly candidatesLoading = signal(false);
  readonly error = signal('');
  readonly candidatesError = signal('');
  readonly success = signal('');
  readonly selectedCandidate = computed(() => this.candidates().find(item => item.id === this.selectedId() && item.eligible));
  private subscription?: LiveSubscription<SystemMaintainersSnapshot>;
  private candidateSubscription?: LiveSubscription<SystemMaintainersSnapshot>;
  private generation = 0;
  private candidateGeneration = 0;
  private mutationGeneration = 0;
  private boundId = 0;
  private lostAccess = false;
  private candidatesBlocked = false;
  private rechecking = false;
  private published = '';
  private readError: string | null = null;

  constructor() {
    effect(() => { const id = this.systemId(); untracked(() => this.bind(id)); });
    inject(DestroyRef).onDestroy(() => { ++this.generation; ++this.mutationGeneration; this.subscription?.close(); this.stopCandidates(); });
  }

  name(member: SystemMaintainer): string {
    return [member.firstName, member.lastName].filter(Boolean).join(' ').trim() || member.username;
  }

  private bind(id: number): void {
    const generation = ++this.generation;
    ++this.mutationGeneration;
    this.subscription?.close(); this.subscription = undefined; this.stopCandidates();
    this.boundId = id; this.lostAccess = false; this.candidatesBlocked = false; this.rechecking = false; this.published = ''; this.readError = null;
    this.snapshot.set(null); this.selectedId.set(null); this.loading.set(true); this.saving.set(false); this.error.set(''); this.success.set('');
    if (!Number.isSafeInteger(id) || id < 1) { this.loading.set(false); this.error.set('The system ID is invalid.'); return; }
    this.subscription = this.realtime.watch<SystemMaintainersSnapshot>(`/systems/${id}/maintainers`, {},
      data => { if (this.current(generation)) this.apply(data, generation); },
      error => { if (this.current(generation)) this.failed(error, generation); });
    void this.subscription.ready.catch(() => {});
  }

  private current(generation: number): boolean { return generation === this.generation && this.systemId() === this.boundId && !this.lostAccess; }

  private apply(data: SystemMaintainersSnapshot, generation: number): void {
    const managed = this.snapshot()?.permissions.canManageMaintainers;
    this.snapshot.set(data); this.loading.set(false);
    if (this.readError !== null && this.error() === this.readError) this.error.set('');
    this.readError = null;
    if (!managed && data.permissions.canManageMaintainers) this.candidatesBlocked = false;
    if (data.permissions.canManageMaintainers) this.watchCandidates(generation);
    else { this.stopCandidates(); this.selectedId.set(null); }
    const owner = data.items.find(item => item.id === data.ownerId);
    const change: SystemMaintainersChange = { systemId: this.boundId, ownerId: data.ownerId, ...(owner ? { ownerName: this.name(owner) } : {}), permissions: { ...data.permissions } };
    const key = JSON.stringify(change);
    if (key !== this.published) { this.published = key; this.changed.emit(change); }
  }

  private watchCandidates(generation: number): void {
    if (this.candidateSubscription || this.candidatesBlocked || !this.current(generation)) return;
    const candidateGeneration = ++this.candidateGeneration;
    this.candidatesLoading.set(true); this.candidatesError.set('');
    this.candidateSubscription = this.realtime.watch<SystemMaintainersSnapshot>(`/systems/${this.boundId}/maintainers`, { candidates: true }, data => {
      if (!this.current(generation) || candidateGeneration !== this.candidateGeneration || !this.snapshot()?.permissions.canManageMaintainers) return;
      this.candidates.set(data.items); this.candidatesLoading.set(false); this.candidatesError.set('');
      if (this.selectedId() !== null && !this.selectedCandidate()) this.selectedId.set(null);
    }, error => {
      if (!this.current(generation) || candidateGeneration !== this.candidateGeneration) return;
      this.candidatesLoading.set(false);
      if (error.status === 403) {
        // A transferred owner remains a maintainer: candidate access may be
        // revoked before the readable roster publishes its new permissions.
        this.candidatesBlocked = true; this.stopCandidates(); this.candidatesError.set(errorMessage(error)); this.recheck(generation);
      } else if ([401, 404].includes(error.status)) this.revoke(error, generation);
      else this.candidatesError.set(errorMessage(error));
    });
    void this.candidateSubscription.ready.catch(() => {});
  }

  private stopCandidates(): void {
    ++this.candidateGeneration; this.candidateSubscription?.close(); this.candidateSubscription = undefined;
    this.candidates.set([]); this.candidatesLoading.set(false); this.candidatesError.set('');
  }

  private failed(error: ApiError, generation: number): void {
    this.loading.set(false);
    if ([401, 403, 404].includes(error.status)) this.revoke(error, generation);
    else { this.readError = errorMessage(error); this.error.set(this.readError); }
  }

  private revoke(error: ApiError, generation: number): void {
    if (!this.current(generation)) return;
    this.lostAccess = true; ++this.mutationGeneration; this.subscription?.close(); this.stopCandidates();
    this.snapshot.set(null); this.selectedId.set(null); this.loading.set(false); this.saving.set(false); this.success.set(''); this.error.set(errorMessage(error));
    this.accessLost.emit({ systemId: this.boundId, error });
  }

  private recheck(generation: number): void {
    if (this.rechecking || !this.subscription || !this.current(generation)) return;
    this.rechecking = true;
    void this.subscription.refresh().catch(() => {}).finally(() => { if (this.current(generation)) this.rechecking = false; });
  }

  retry(): void { if (!this.current(this.generation)) return; this.error.set(''); this.loading.set(true); this.recheck(this.generation); }
  retryCandidates(): void {
    if (!this.snapshot()?.permissions.canManageMaintainers) return;
    this.stopCandidates(); this.candidatesBlocked = false; this.watchCandidates(this.generation);
  }

  async add(): Promise<void> {
    const member = this.selectedCandidate();
    if (!member || !this.snapshot()?.permissions.canManageMaintainers) return;
    await this.mutate(() => this.api.post(`/systems/${this.boundId}/maintainers`, { userId: member.id }), 'Maintainer added.', () => {
      if (this.selectedId() === member.id) this.selectedId.set(null);
    });
  }

  async remove(member: SystemMaintainer): Promise<void> {
    const data = this.snapshot();
    if (this.saving() || !data?.permissions.canManageMaintainers || member.id === data.ownerId || !data.items.some(item => item.id === member.id)) return;
    if (!window.confirm(`Remove ${this.name(member)} (${member.username}) from this system's maintainers?`)) return;
    await this.mutate(() => this.api.delete(`/systems/${this.boundId}/maintainers/${member.id}`), 'Maintainer removed.');
  }

  async makeOwner(member: SystemMaintainer): Promise<void> {
    const data = this.snapshot();
    if (this.saving() || !data?.permissions.canChangeOwner || !member.eligible || member.id === data.ownerId || !data.items.some(item => item.id === member.id && item.eligible)) return;
    if (!window.confirm(`Make ${this.name(member)} (${member.username}) the owner of this system? The current owner will remain a maintainer.`)) return;
    await this.mutate(() => this.api.patch(`/systems/${this.boundId}`, { ownerId: member.id }), 'System owner changed.');
  }

  private async mutate(operation: () => Promise<unknown>, message: string, completed?: () => void): Promise<void> {
    if (this.saving() || this.loading() || this.lostAccess) return;
    const generation = this.generation, mutation = ++this.mutationGeneration;
    const current = () => this.current(generation) && mutation === this.mutationGeneration;
    this.saving.set(true); this.readError = null; this.error.set(''); this.success.set('');
    try { await operation(); if (current()) { completed?.(); this.success.set(message); } }
    catch (error) {
      if (!current()) return;
      if (error instanceof ApiError && [401, 404].includes(error.status)) this.revoke(error, generation);
      else { this.error.set(errorMessage(error)); if (error instanceof ApiError && error.status === 403) this.recheck(generation); }
    } finally { if (current()) this.saving.set(false); }
  }
}
