import { DestroyRef } from '@angular/core';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { UnsavedChanges } from './unsaved';

class EditorLifetime extends DestroyRef {
  override destroyed = false;
  private callbacks = new Set<() => void>();
  override onDestroy(callback: () => void): () => void { this.callbacks.add(callback); return () => this.callbacks.delete(callback); }
  destroy(): void { this.destroyed = true; for (const callback of this.callbacks) callback(); }
}

describe('editor-owned unsaved changes', () => {
  afterEach(() => vi.restoreAllMocks());
  it('saving or releasing one editor leaves another live draft protected', () => {
    const changes = new UnsavedChanges(), firstLifetime = new EditorLifetime();
    const first = changes.scope(firstLifetime), second = changes.scope(new EditorLifetime());
    first.setDirty(true); second.setDirty(true); first.setDirty(false);
    expect(changes.dirty()).toBe(true); expect(second.dirty()).toBe(true);
    firstLifetime.destroy(); first.setDirty(false); first.setDirty(true);
    expect(changes.dirty()).toBe(true); expect(first.dirty()).toBe(false);
    second.setDirty(false); expect(changes.dirty()).toBe(false);
  });
  it('resource renewal rejects the old operation while preserving the new draft and discard prompts', () => {
    const changes = new UnsavedChanges(), draft = changes.scope(new EditorLifetime());
    draft.setDirty(true); const oldOperation = draft.checkpoint(); draft.reset();
    expect(oldOperation()).toBe(false); expect(changes.dirty()).toBe(false);
    draft.setDirty(true); const currentOperation = draft.checkpoint();
    vi.spyOn(window, 'confirm').mockReturnValue(false);
    expect(draft.mayDiscard()).toBe(false); expect(changes.mayDiscard()).toBe(false);
    expect(currentOperation()).toBe(true); expect(draft.dirty()).toBe(true);
  });
  it('explicit global discard clears all owners and invalidates their pending operations', () => {
    const changes = new UnsavedChanges(), draft = changes.scope(new EditorLifetime());
    draft.setDirty(true); changes.setDirty(true); const oldOperation = draft.checkpoint();
    changes.clear();
    expect(changes.dirty()).toBe(false); expect(draft.dirty()).toBe(false); expect(oldOperation()).toBe(false);
    draft.setDirty(true); expect(changes.dirty()).toBe(true);
  });
});
