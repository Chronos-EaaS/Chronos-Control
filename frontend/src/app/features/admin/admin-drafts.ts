import { DestroyRef, Injectable, inject } from '@angular/core';
import { UnsavedChanges } from '../../core/unsaved';

/** Aggregate independently saved forms on the administration page. */
@Injectable()
export class AdminDrafts {
  private readonly changes = inject(UnsavedChanges);
  private readonly dirtyForms = new Set<string>();
  private destroyed = false;
  constructor() { inject(DestroyRef).onDestroy(() => { this.destroyed = true; this.changes.dirty.set(false); }); }
  set(form: string, dirty: boolean): void {
    if (this.destroyed) return;
    if (dirty) this.dirtyForms.add(form); else this.dirtyForms.delete(form);
    this.changes.dirty.set(this.dirtyForms.size > 0);
  }
}
