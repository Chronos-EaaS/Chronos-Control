import { DestroyRef, Injectable, Signal, computed, signal } from '@angular/core';

export interface UnsavedDraft {
  readonly dirty: Signal<boolean>;
  setDirty(value: boolean): void;
  /** Begin editing another resource in a reused component. */
  reset(): void;
  /** A pending operation may publish only while this editor/resource is current. */
  checkpoint(): () => boolean;
  mayDiscard(): boolean;
}

interface DraftState { dirty: boolean; generation: number; }
const confirmDiscard = (dirty: boolean): boolean => !dirty || window.confirm('You have unsaved changes. Discard them and continue?');

@Injectable({ providedIn: 'root' })
export class UnsavedChanges {
  private readonly drafts = signal(new Map<symbol, DraftState>());
  private readonly unscoped = signal(false);
  readonly dirty = computed(() => this.unscoped() || [...this.drafts().values()].some(draft => draft.dirty));
  setDirty(value: boolean): void { this.unscoped.set(value); }
  mayDiscard(): boolean { return confirmDiscard(this.dirty()); }
  /** Used only after an explicit global discard, before sign-out or document reload. */
  clear(): void {
    this.unscoped.set(false);
    this.drafts.update(current => new Map([...current].map(([id, state]) => [id, { dirty: false, generation: state.generation + 1 }])));
  }
  scope(lifetime: DestroyRef): UnsavedDraft {
    const id = Symbol('editor-draft');
    this.drafts.update(current => new Map(current).set(id, { dirty: false, generation: 0 }));
    const update = (change: (state: DraftState) => DraftState): void => {
      const state = this.drafts().get(id);
      if (state) this.drafts.update(current => new Map(current).set(id, change(state)));
    };
    const dirty = computed(() => this.drafts().get(id)?.dirty ?? false);
    lifetime.onDestroy(() => this.drafts.update(current => { const next = new Map(current); next.delete(id); return next; }));
    return {
      dirty,
      setDirty: value => update(state => ({ ...state, dirty: value })),
      reset: () => update(state => ({ dirty: false, generation: state.generation + 1 })),
      checkpoint: () => { const generation = this.drafts().get(id)?.generation; return () => generation !== undefined && this.drafts().get(id)?.generation === generation; },
      mayDiscard: () => confirmDiscard(dirty()),
    };
  }
}
