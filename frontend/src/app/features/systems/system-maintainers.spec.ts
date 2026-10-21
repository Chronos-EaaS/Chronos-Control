import { ComponentFixture, TestBed } from '@angular/core/testing';
import { afterEach, beforeEach, describe, expect, it, vi, type Mock } from 'vitest';
import { Api, ApiError } from '../../core/api';
import { LiveQuery, LiveSubscription, Realtime } from '../../core/realtime';
import { SystemMaintainer, SystemMaintainers, SystemMaintainersAccessLost, SystemMaintainersChange, SystemMaintainersSnapshot } from './system-maintainers';

const member = (id: number, username: string, eligible = true): SystemMaintainer => ({
  id, username, firstName: `First ${id}`, lastName: `Last ${id}`, email: `${username}@example.test`, enabled: eligible, activated: true, eligible,
});
const owner = member(3, 'owner'), colleague = member(4, 'colleague'), candidate = member(5, 'candidate'), disabled = member(6, 'disabled', false);
const roster = (manage = true): SystemMaintainersSnapshot => ({ items: [owner, colleague, disabled], ownerId: owner.id, permissions: { canManageMaintainers: manage, canChangeOwner: manage } });
const options = (): SystemMaintainersSnapshot => ({ ...roster(), items: [candidate] });
type Write = (path: string, body?: unknown) => Promise<unknown>;
type ApiMock = { get: Mock<(path: string, query?: LiveQuery) => Promise<SystemMaintainersSnapshot>>; post: Mock<Write>; patch: Mock<Write>; delete: Mock<Write> };
interface Listener {
  path: string; query: LiveQuery; closed: boolean; apply: (value: SystemMaintainersSnapshot) => void; failed?: (error: ApiError) => void;
  refresh: Mock<() => Promise<SystemMaintainersSnapshot>>;
}

/** Deliberately permits late frames from closed subscriptions to exercise the component's own guards. */
class PushTransport {
  readonly listeners: Listener[] = [];
  constructor(private readonly api: ApiMock) {}
  watch(path: string, query: LiveQuery, apply: (value: SystemMaintainersSnapshot) => void, failed?: (error: ApiError) => void): LiveSubscription<SystemMaintainersSnapshot> {
    const listener: Listener = { path, query, apply, failed, closed: false, refresh: vi.fn<() => Promise<SystemMaintainersSnapshot>>() };
    this.listeners.push(listener);
    const load = async () => {
      try { const value = await this.api.get(path, query) as SystemMaintainersSnapshot; if (!listener.closed) apply(value); return value; }
      catch (error) { if (!listener.closed) failed?.(error as ApiError); throw error; }
    };
    listener.refresh.mockImplementation(load);
    return { ready: load(), refresh: listener.refresh, update: async () => load(), checkpoint: () => {}, close: () => { listener.closed = true; } };
  }
  push(data: SystemMaintainersSnapshot, candidates = false, path = '/systems/1/maintainers'): void {
    for (const listener of this.listeners) if (!listener.closed && listener.path === path && !!listener.query['candidates'] === candidates) listener.apply(data);
  }
  fail(error: ApiError, candidates = false): void {
    for (const listener of this.listeners) if (!listener.closed && !!listener.query['candidates'] === candidates) listener.failed?.(error);
  }
}

async function ready(fixture: ComponentFixture<SystemMaintainers>): Promise<void> {
  fixture.detectChanges(); await fixture.whenStable(); fixture.detectChanges();
}
function button(root: HTMLElement, name: string): HTMLButtonElement | undefined {
  return [...root.querySelectorAll<HTMLButtonElement>('button')].find(node => node.getAttribute('aria-label') === name || node.textContent?.replace(/\s+/g, ' ').trim() === name);
}
function deferred<T>() {
  let resolve!: (value: T) => void, reject!: (error: unknown) => void;
  const promise = new Promise<T>((done, fail) => { resolve = done; reject = fail; });
  return { promise, resolve, reject };
}

describe('system maintainers panel', () => {
  let api: ApiMock, live: PushTransport;
  beforeEach(async () => {
    api = { get: vi.fn(async (_path: string, query?: LiveQuery) => query?.['candidates'] ? options() : roster()), post: vi.fn<Write>().mockResolvedValue({}), patch: vi.fn<Write>().mockResolvedValue({}), delete: vi.fn<Write>().mockResolvedValue({}) };
    live = new PushTransport(api);
    await TestBed.configureTestingModule({ imports: [SystemMaintainers], providers: [{ provide: Api, useValue: api }, { provide: Realtime, useValue: live }] }).compileComponents();
  });
  afterEach(() => { TestBed.resetTestingModule(); vi.restoreAllMocks(); });
  async function mount() {
    const fixture = TestBed.createComponent(SystemMaintainers), changes: SystemMaintainersChange[] = [], lost: SystemMaintainersAccessLost[] = [];
    fixture.componentInstance.changed.subscribe(value => changes.push(value)); fixture.componentInstance.accessLost.subscribe(value => lost.push(value));
    fixture.componentRef.setInput('systemId', 1); await ready(fixture);
    return { fixture, editor: fixture.componentInstance, root: fixture.nativeElement as HTMLElement, changes, lost };
  }

  it('shows a read-only roster, owner and disabled state without requesting candidates or emitting on identical pushes', async () => {
    api.get.mockResolvedValue(roster(false));
    const { fixture, root, editor, changes } = await mount();
    expect(root.querySelectorAll('tbody tr')).toHaveLength(3); expect(root.textContent).toContain('Owner'); expect(root.textContent).toContain('Disabled');
    expect(root.querySelector('tbody .btn')).toBeNull(); expect(root.querySelector('form')).toBeNull();
    expect(api.get).toHaveBeenCalledExactlyOnceWith('/systems/1/maintainers', {});
    expect(changes).toEqual([{ systemId: 1, ownerId: 3, ownerName: 'First 3 Last 3', permissions: roster(false).permissions }]);
    live.push(roster(false)); await ready(fixture); expect(changes).toHaveLength(1);
    await editor.add(); await editor.remove(colleague); await editor.makeOwner(colleague);
    expect(api.post).not.toHaveBeenCalled(); expect(api.delete).not.toHaveBeenCalled(); expect(api.patch).not.toHaveBeenCalled();
  });

  it('preserves an eligible selected user across unrelated pushes and adds only the selected ID without reloading the system', async () => {
    const { fixture, root, editor } = await mount();
    await ready(fixture);
    const select = root.querySelector<HTMLSelectElement>('select')!;
    expect(select.options).toHaveLength(2);
    select.selectedIndex = 1; select.dispatchEvent(new Event('change', { bubbles: true })); await ready(fixture);
    expect(editor.selectedId()).toBe(5);
    live.push({ ...roster(), items: [owner, { ...colleague, firstName: 'Updated name' }, disabled] }); live.push(options(), true); await ready(fixture);
    expect(editor.selectedId()).toBe(5); expect(api.get).toHaveBeenCalledTimes(2);
    await editor.add(); await ready(fixture);
    expect(api.post).toHaveBeenCalledExactlyOnceWith('/systems/1/maintainers', { userId: 5 });
    expect(editor.selectedId()).toBeNull(); expect(editor.success()).toBe('Maintainer added.');
    expect(api.get).toHaveBeenCalledTimes(2);
    live.push({ ...options(), items: [] }, true); live.push({ ...roster(), items: [...roster().items, candidate] }); await ready(fixture);
    expect(root.querySelectorAll('tbody tr')).toHaveLength(4);
  });

  it('protects the designated owner and ineligible transfers while confirming non-owner removal', async () => {
    const { fixture, root, editor } = await mount();
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false);
    expect(button(root, 'Remove owner')).toBeUndefined(); expect(button(root, 'Make owner owner')).toBeUndefined();
    expect(button(root, 'Make disabled owner')?.disabled).toBe(true);
    await editor.remove(owner); await editor.makeOwner(disabled); await editor.makeOwner(owner);
    expect(confirm).not.toHaveBeenCalled(); expect(api.patch).not.toHaveBeenCalled(); expect(api.delete).not.toHaveBeenCalled();
    await editor.remove(colleague); expect(confirm).toHaveBeenCalledWith(expect.stringContaining('colleague')); expect(api.delete).not.toHaveBeenCalled();
    confirm.mockReturnValue(true); await editor.remove(colleague); await ready(fixture);
    expect(api.delete).toHaveBeenCalledExactlyOnceWith('/systems/1/maintainers/4');
    expect(editor.success()).toBe('Maintainer removed.'); expect(api.get).toHaveBeenCalledTimes(2);
  });

  it('transfers ownership only to a current eligible maintainer and accepts live loss of management without losing readable data', async () => {
    const { fixture, root, editor, changes, lost } = await mount();
    vi.spyOn(window, 'confirm').mockReturnValue(true);
    await editor.makeOwner(candidate); expect(api.patch).not.toHaveBeenCalled();
    await editor.makeOwner(colleague); expect(api.patch).toHaveBeenCalledExactlyOnceWith('/systems/1', { ownerId: 4 });
    expect(changes.at(-1)?.ownerId).toBe(3);
    live.push({ ...roster(false), ownerId: 4 }); await ready(fixture);
    expect(editor.snapshot()?.items.some(item => item.id === 3)).toBe(true); expect(lost).toEqual([]);
    expect(changes.at(-1)).toEqual({ systemId: 1, ownerId: 4, ownerName: 'First 4 Last 4', permissions: roster(false).permissions });
    expect(root.querySelector('form')).toBeNull(); expect(root.querySelector('tbody .btn')).toBeNull();
    expect(live.listeners.find(listener => listener.query['candidates'])?.closed).toBe(true);
  });

  it('starts candidates once per management grant and clears a selection when it is no longer an eligible candidate', async () => {
    api.get.mockImplementation(async (_path: string, query?: LiveQuery) => query?.['candidates'] ? options() : roster(false));
    const { fixture, editor } = await mount();
    live.push(roster()); await ready(fixture); editor.selectedId.set(5);
    live.push(roster()); live.push(roster()); await ready(fixture); expect(api.get).toHaveBeenCalledTimes(2); expect(editor.selectedId()).toBe(5);
    live.push({ ...options(), items: [{ ...candidate, eligible: false }] }, true); await ready(fixture); expect(editor.selectedId()).toBeNull();
    live.push(roster(false)); await ready(fixture); expect(editor.candidates()).toEqual([]);
    live.push(roster()); await ready(fixture); expect(api.get).toHaveBeenCalledTimes(3);
  });

  it('ignores delayed mutation results and callbacks from a previous system and closes both subscriptions on destruction', async () => {
    const { fixture, editor, changes, lost } = await mount();
    editor.selectedId.set(5); const write = deferred<object>(); api.post.mockReturnValueOnce(write.promise); const adding = editor.add();
    const previous = [...live.listeners];
    fixture.componentRef.setInput('systemId', 2); await ready(fixture);
    expect(previous.every(listener => listener.closed)).toBe(true); expect(changes.at(-1)?.systemId).toBe(2);
    write.reject(new ApiError('Old request failed.', 403, 'forbidden')); await adding;
    for (const listener of previous) { listener.apply({ ...roster(), ownerId: 99 }); listener.failed?.(new ApiError('Old system disappeared.', 404, 'not_found')); }
    await ready(fixture);
    expect(editor.snapshot()?.ownerId).toBe(3); expect(editor.error()).toBe(''); expect(editor.saving()).toBe(false); expect(lost).toEqual([]);
    expect(changes.some(change => change.ownerId === 99)).toBe(false);
    fixture.destroy(); expect(live.listeners.every(listener => listener.closed)).toBe(true);
  });

  it.each([401, 403, 404])('clears protected roster state and emits scoped access loss on a live %s response', async status => {
    const { fixture, editor, lost, changes } = await mount(); editor.selectedId.set(5);
    const listener = live.listeners[0], failure = new ApiError('Access no longer available.', status, 'forbidden');
    live.fail(failure); listener.apply({ ...roster(), ownerId: 99 }); await ready(fixture);
    expect(editor.snapshot()).toBeNull(); expect(editor.candidates()).toEqual([]); expect(editor.selectedId()).toBeNull();
    expect(lost).toEqual([{ systemId: 1, error: failure }]); expect(changes.some(change => change.ownerId === 99)).toBe(false);
    expect(live.listeners.every(item => item.closed)).toBe(true);
    await editor.remove(colleague); await editor.makeOwner(colleague); expect(api.delete).not.toHaveBeenCalled(); expect(api.patch).not.toHaveBeenCalled();
  });

  it('offers retry after an initial roster failure and clears recovered read errors while preserving later mutation errors', async () => {
    api.get.mockRejectedValueOnce(new ApiError('Roster temporarily unavailable.', 500, 'internal_error'));
    const { fixture, root, editor } = await mount();
    expect(editor.snapshot()).toBeNull(); expect(button(root, 'Retry maintainer list')).toBeDefined();
    button(root, 'Retry maintainer list')!.click(); await ready(fixture);
    expect(editor.snapshot()?.ownerId).toBe(3); expect(editor.error()).toBe('');
    editor.selectedId.set(5); api.post.mockRejectedValueOnce(new ApiError('Keep this write error.', 422, 'invalid_input'));
    await editor.add(); live.push(roster()); await ready(fixture);
    expect(editor.error()).toBe('Keep this write error.'); expect(editor.selectedId()).toBe(5); expect(editor.saving()).toBe(false);
    live.fail(new ApiError('Temporary live failure.', 500, 'internal_error')); expect(editor.error()).toBe('Temporary live failure.');
    live.push(roster()); await ready(fixture); expect(editor.error()).toBe('');
  });

  it('rechecks candidate-only forbidden access once without mistaking a transferred owner for a removed maintainer', async () => {
    const { fixture, editor, lost } = await mount(); editor.selectedId.set(5);
    api.get.mockResolvedValueOnce({ ...roster(false), ownerId: 4 });
    live.fail(new ApiError('Owner-only candidates.', 403, 'forbidden'), true); await ready(fixture);
    expect(editor.snapshot()?.ownerId).toBe(4); expect(editor.snapshot()?.permissions.canManageMaintainers).toBe(false);
    expect(editor.candidates()).toEqual([]); expect(editor.selectedId()).toBeNull(); expect(lost).toEqual([]); expect(api.get).toHaveBeenCalledTimes(3);
    live.push({ ...roster(false), ownerId: 4 }); await ready(fixture); expect(api.get).toHaveBeenCalledTimes(3);
  });

  it('retains a candidate draft on a transient failure and retries a forbidden candidate stream explicitly without loops', async () => {
    const { fixture, editor } = await mount(); editor.selectedId.set(5);
    live.fail(new ApiError('Temporary candidate failure.', 500, 'internal_error'), true); await ready(fixture);
    expect(editor.selectedId()).toBe(5); expect(editor.candidatesError()).toBe('Temporary candidate failure.');
    live.push(options(), true); await ready(fixture); expect(editor.candidatesError()).toBe(''); expect(editor.selectedId()).toBe(5);
    live.fail(new ApiError('Recheck permissions.', 403, 'forbidden'), true); await ready(fixture);
    expect(editor.candidates()).toEqual([]); expect(api.get).toHaveBeenCalledTimes(3);
    live.push(roster()); live.push(roster()); await ready(fixture); expect(api.get).toHaveBeenCalledTimes(3);
    editor.retryCandidates(); await ready(fixture); expect(api.get).toHaveBeenCalledTimes(4); expect(editor.candidates()).toEqual([candidate]);
    expect(editor.selectedId()).toBe(5); expect(editor.candidatesError()).toBe('');
  });
});
