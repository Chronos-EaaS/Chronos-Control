import { Component, DestroyRef, ElementRef, ViewChild, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { Api, errorMessage } from '../../core/api';
import { MaintenanceAction, MaintenanceStatus, OperationPlan } from '../../core/admin-models';
import { AdminDrafts } from './admin-drafts';
import { dateTime } from '../../core/time';
import { Feedback } from '../../shared/feedback';
import { Session } from '../../core/session';

@Component({
  selector: 'app-admin-maintenance', imports: [FormsModule, Feedback],
  templateUrl: './maintenance.html',
})
export class AdminMaintenance {
  private readonly api = inject(Api); private readonly drafts = inject(AdminDrafts); private readonly session = inject(Session); private readonly router = inject(Router);
  @ViewChild('operationDialog') private dialog?: ElementRef<HTMLDialogElement>;
  readonly status = signal<MaintenanceStatus | null>(null); readonly plan = signal<OperationPlan | null>(null);
  readonly loading = signal(true); readonly busy = signal(false); readonly error = signal(''); readonly operationError = signal(''); readonly success = signal('');
  branch = ''; confirmation = ''; readonly date = dateTime;
  constructor() { inject(DestroyRef).onDestroy(() => this.drafts.set('maintenance', false)); void this.load(); }
  private async load(): Promise<void> {
    try { const status = await this.api.get<MaintenanceStatus>('/admin/maintenance'); this.status.set(status); this.branch = status.selectedBranch; }
    catch (error) { this.error.set(errorMessage(error)); } finally { this.loading.set(false); }
  }
  dirty(): void { this.drafts.set('maintenance', this.branch !== this.status()?.selectedBranch); }
  label(action: MaintenanceAction): string { return ({ update: 'Review update', rollback: 'Review rollback', mount: 'Mount data storage', 'reset-demo': 'Reset demo data', 'reset-local-systems': 'Reset local systems' })[action]; }
  destructive(action: MaintenanceAction): boolean { return action.startsWith('reset-'); }
  async saveBranch(): Promise<void> {
    if (this.busy()) return; this.busy.set(true); this.error.set(''); this.success.set('');
    try { this.status.set(await this.api.patch<MaintenanceStatus>('/admin/maintenance', { branch: this.branch })); this.dirty(); this.success.set('The application branch was saved.'); }
    catch (error) { this.error.set(errorMessage(error)); } finally { this.busy.set(false); }
  }
  async review(action: MaintenanceAction): Promise<void> {
    if (this.busy()) return; this.busy.set(true); this.error.set(''); this.operationError.set(''); this.confirmation = '';
    try {
      const plan = await this.api.post<OperationPlan>('/admin/operations', action === 'update' ? { action, branch: this.branch } : { action });
      this.plan.set(plan); this.dialog?.nativeElement.showModal();
    } catch (error) { this.error.set(errorMessage(error)); } finally { this.busy.set(false); }
  }
  cancel(event: Event): void { event.preventDefault(); this.close(); }
  close(): void { if (!this.busy()) { this.dialog?.nativeElement.close(); this.confirmation = ''; this.operationError.set(''); } }
  async run(mode: 'simulate' | 'execute'): Promise<void> {
    const plan = this.plan(); if (!plan || this.busy() || this.confirmation !== plan.confirmation) return;
    this.busy.set(true); this.operationError.set('');
    try {
      const result = await this.api.post<OperationPlan>(`/admin/operations/${plan.id}/${mode}`, { confirmation: this.confirmation }, mode === 'execute' ? 310_000 : 20_000); this.plan.set(result); this.confirmation = '';
      if (result.signedOut) { await this.session.refresh(); this.dialog?.nativeElement.close(); await this.router.navigate(['/login']); }
    }
    catch (error) { this.operationError.set(errorMessage(error)); } finally { this.busy.set(false); }
  }
}
