import { Injectable, signal } from '@angular/core';

@Injectable({ providedIn: 'root' })
export class UnsavedChanges {
  readonly dirty = signal(false);
  mayDiscard(): boolean { return !this.dirty() || window.confirm('You have unsaved changes. Discard them and continue?'); }
}
