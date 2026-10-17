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
  template: `<section class="card"><div class="card-header"><h2 class="card-title">Application maintenance</h2></div><div class="card-body">
    <app-feedback [busy]="loading()" [error]="error()" [success]="success()" />
    @if (status(); as item) {
      <p>Installed version <strong>{{ item.installedVersion }}</strong></p>
      <form #branchForm="ngForm" (ngSubmit)="saveBranch()"><fieldset [disabled]="busy()"><label class="form-label" for="application-branch">Application branch</label><div class="input-group mb-3"><input id="application-branch" class="form-control" name="branch" [(ngModel)]="branch" (ngModelChange)="dirty()" list="application-branches" required maxlength="200" autocomplete="off" /><datalist id="application-branches">@for (name of item.branches; track name) { <option [value]="name"></option> }</datalist><button class="btn btn-outline-primary" [disabled]="branchForm.invalid || branch === item.selectedBranch">Save branch</button></div></fieldset></form>
      <div class="d-flex flex-wrap gap-2">@for (action of item.actions; track action) { <button class="btn" [class.btn-outline-danger]="destructive(action)" [class.btn-outline-primary]="!destructive(action)" [disabled]="busy()" (click)="review(action)">{{ label(action) }}</button> }</div>
      @if (!item.executionAvailable) { <p class="form-text mt-3 mb-0">{{ item.executionMessage }}</p> }
    }
  </div></section>
  <dialog #operationDialog class="form-dialog" aria-labelledby="operation-title" (cancel)="cancel($event)">
    @if (plan(); as operation) { <div class="dialog-header"><h2 id="operation-title">{{ operation.title }}</h2><button class="btn-close" aria-label="Close operation review" [disabled]="busy()" (click)="close()"></button></div><div class="dialog-body">
      <app-feedback [error]="operationError()" />
      <p>{{ operation.impact }}</p>@if (operation.branch) { <p>Branch: <strong>{{ operation.branch }}</strong></p> }
      @if (operation.resources?.length) { <p class="mb-1 fw-semibold">Affected resources</p><ul>@for (resource of operation.resources; track $index) { <li class="text-break">{{ resource }}</li> }</ul> }
      @if (operation.unavailableReason) { <div class="alert alert-warning">{{ operation.unavailableReason }}</div> }
      <ol>@for (step of operation.steps; track $index) { <li class="mb-2">{{ step }}</li> }</ol>
      <p class="text-secondary">Plan expires {{ date(operation.expiresAt, true) }}.</p>
      @if (operation.state === 'planned') { <label class="form-label" for="operation-confirmation">Enter <strong>{{ operation.confirmation }}</strong> to confirm</label><input id="operation-confirmation" class="form-control" autocomplete="off" [(ngModel)]="confirmation" [disabled]="busy()" /> }
      @else { <div class="alert" [class.alert-info]="operation.state === 'simulated'" [class.alert-success]="operation.state === 'completed'" role="status"><strong>{{ operation.state === 'simulated' ? 'Simulation completed' : 'Operation completed' }}</strong><p class="mb-0 mt-2" style="white-space:pre-wrap">{{ operation.output }}</p></div> }
    </div><div class="dialog-footer"><button class="btn btn-outline-secondary" [disabled]="busy()" (click)="close()">{{ operation.state === 'planned' ? 'Cancel' : 'Close' }}</button>
      @if (operation.state === 'planned') {
        @if (operation.canSimulate) { <button class="btn btn-outline-primary" [disabled]="busy() || confirmation !== operation.confirmation" (click)="run('simulate')">Run disposable simulation</button> }
        <button class="btn btn-danger" [disabled]="busy() || !operation.canExecute || confirmation !== operation.confirmation" (click)="run('execute')">{{ busy() ? 'Running…' : 'Apply operation' }}</button>
      }
    </div> }
  </dialog>`,
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
