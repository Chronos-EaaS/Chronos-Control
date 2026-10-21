import { ComponentFixture, TestBed } from '@angular/core/testing';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ParameterGroup, ParameterTypeAliases } from '../../core/parameter-models';
import { ParameterSchemaEditor } from './parameter-schema-editor';

const schema = (): ParameterGroup[] => [
  { id: 'main', title: 'Main', depends: '', dependsValue: false, metadata: { flags: [false, 0, '  keep  '] }, elements: [
    { id: 'alpha', type: 'integer', name: 'Alpha', parameter: 'alpha', default: 0, 'default-step': 0, options: ' one, two ', metadata: { retained: '🧪 café' } },
    { id: 'beta', type: 'string', name: 'Beta', parameter: 'beta', default: '  café 🧪  ', repository: ' hidden repository ' },
  ] },
  { id: 'other', title: 'Other', depends: 'alpha', dependsValue: 0, elements: [
    { id: 'gamma', type: 'boolean', name: 'Gamma', parameter: 'gamma', default: false },
  ] },
];
const ready = async (fixture: ComponentFixture<ParameterSchemaEditor>) => {
  fixture.detectChanges(); await fixture.whenStable(); fixture.detectChanges();
};
async function mount(groups = schema(), aliases: ParameterTypeAliases = {}) {
  const fixture = TestBed.createComponent(ParameterSchemaEditor);
  fixture.componentRef.setInput('groups', groups); fixture.componentRef.setInput('aliases', aliases);
  await ready(fixture);
  return { fixture, editor: fixture.componentInstance, root: fixture.nativeElement as HTMLElement };
}
function button(root: HTMLElement, name: string): HTMLButtonElement {
  const node = [...root.querySelectorAll<HTMLButtonElement>('button')].find(item => item.getAttribute('aria-label') === name || item.textContent?.replace(/\s+/g, ' ').trim() === name);
  expect(node, `Button ${name}`).toBeDefined(); return node!;
}
function field(root: HTMLElement, name: string): HTMLInputElement | HTMLSelectElement {
  const label = [...root.querySelectorAll<HTMLLabelElement>('label[for]')].find(item => item.textContent?.replace(/\s+/g, ' ').trim() === name);
  expect(label, `Label ${name}`).toBeDefined();
  return root.querySelector<HTMLInputElement | HTMLSelectElement>(`[id="${label!.htmlFor}"]`)!;
}
function enter(root: HTMLElement, name: string, value: string, event = 'input'): void {
  const control = field(root, name); control.value = value; control.dispatchEvent(new Event(event, { bubbles: true }));
}

describe('parameter schema builder', () => {
  beforeEach(async () => { await TestBed.configureTestingModule({ imports: [ParameterSchemaEditor] }).compileComponents(); });
  afterEach(() => vi.restoreAllMocks());

  it('edits immutable drafts without normalizing metadata, false, zero, spaces or type-hidden fields', async () => {
    const original = schema(), snapshot = structuredClone(original);
    const { fixture, editor, root } = await mount(original);
    enter(root, 'Label', 'Renamed Alpha'); enter(root, 'Input type', 'string', 'change'); await ready(fixture);
    button(root, 'Edit group Main').click(); await ready(fixture);
    enter(root, 'Group title', '  Query plans – Zürich  '); await ready(fixture);
    expect(original).toEqual(snapshot); expect(editor.groups()).not.toBe(original);
    expect(editor.groups()[0]).toEqual({ ...snapshot[0], title: '  Query plans – Zürich  ', elements: [
      { ...snapshot[0].elements[0], name: 'Renamed Alpha', type: 'string' }, snapshot[0].elements[1],
    ] });
    expect(editor.groups()[1]).toEqual(snapshot[1]);
    button(root, 'Edit parameter Renamed Alpha').click(); await ready(fixture);
    enter(root, 'Input type', 'integer', 'change'); await ready(fixture);
    expect(editor.active()!.element).toMatchObject({ id: 'alpha', default: 0, 'default-step': 0, options: ' one, two ' });
  });

  it('keeps the selected parameter and group settings attached while groups reorder and undo', async () => {
    const { fixture, editor, root } = await mount();
    button(root, 'Edit parameter Gamma').click(); await ready(fixture);
    button(root, 'Move group Other up').click(); await ready(fixture);
    expect(editor.groups().map(group => group.id)).toEqual(['other', 'main']);
    expect(editor.selection()).toEqual({ group: 0, element: 0 }); expect(editor.active()!.element?.id).toBe('gamma');
    button(root, 'Undo last change').click(); await ready(fixture);
    expect(editor.selection()).toEqual({ group: 1, element: 0 }); expect(editor.active()!.element?.id).toBe('gamma');
    button(root, 'Edit group Main').click(); await ready(fixture);
    button(root, 'Move group Main down').click(); await ready(fixture);
    expect(editor.active()!.group.id).toBe('main'); expect(editor.active()!.element).toBeNull();
  });

  it('retains the selected parameter when either it or its neighbour changes order', async () => {
    const { fixture, editor, root } = await mount();
    button(root, 'Edit parameter Beta').click(); await ready(fixture);
    button(root, 'Move parameter Beta up').click(); await ready(fixture);
    expect(editor.groups()[0].elements.map(element => element.id)).toEqual(['beta', 'alpha']);
    expect(editor.selection()).toEqual({ group: 0, element: 0 });
    editor.moveElement(0, 1, -1); await ready(fixture);
    expect(editor.selection()).toEqual({ group: 0, element: 1 }); expect(editor.active()!.element?.id).toBe('beta');
    editor.undo(); await ready(fixture);
    expect(editor.active()!.element?.id).toBe('beta'); expect(editor.active()!.elementIndex).toBe(0);
  });

  it('moves the active parameter across groups without losing its ID or undo destination', async () => {
    const { fixture, editor, root } = await mount();
    button(root, 'Edit parameter Beta').click(); await ready(fixture);
    enter(root, 'Move Beta to another group', '1', 'change'); await ready(fixture);
    expect(editor.groups()[0].elements.map(element => element.id)).toEqual(['alpha']);
    expect(editor.groups()[1].elements.map(element => element.id)).toEqual(['gamma', 'beta']);
    expect(editor.selection()).toEqual({ group: 1, element: 1 }); expect(editor.active()!.element?.id).toBe('beta');
    button(root, 'Undo last change').click(); await ready(fixture);
    expect(editor.selection()).toEqual({ group: 0, element: 1 }); expect(editor.active()!.element?.id).toBe('beta');
    expect(editor.groups()).toEqual(schema());
  });

  it('requires deletion confirmation and restores the deleted parameter and selection on undo', async () => {
    const { fixture, editor, root } = await mount();
    button(root, 'Edit parameter Beta').click(); await ready(fixture);
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false), before = editor.groups();
    button(root, 'Delete parameter').click(); await ready(fixture);
    expect(confirm).toHaveBeenCalledWith(expect.stringContaining('Beta')); expect(editor.groups()).toBe(before);
    confirm.mockReturnValue(true); button(root, 'Delete parameter').click(); await ready(fixture);
    expect(editor.groups()[0].elements.map(element => element.id)).toEqual(['alpha']); expect(editor.active()!.element?.id).toBe('alpha');
    button(root, 'Undo last change').click(); await ready(fixture);
    expect(editor.groups()).toBe(before); expect(editor.active()!.element?.id).toBe('beta');
  });

  it('keeps another group selected on deletion and provides a valid fallback when its own group is removed', async () => {
    const { fixture, editor, root } = await mount(); vi.spyOn(window, 'confirm').mockReturnValue(true);
    button(root, 'Edit parameter Gamma').click(); await ready(fixture);
    editor.removeGroup(0); await ready(fixture);
    expect(editor.active()!.group.id).toBe('other'); expect(editor.active()!.element?.id).toBe('gamma');
    editor.undo(); await ready(fixture); expect(editor.active()!.element?.id).toBe('gamma');
    button(root, 'Delete group').click(); await ready(fixture);
    expect(editor.groups().map(group => group.id)).toEqual(['main']); expect(editor.active()!.group.id).toBe('main');
    expect(editor.active()!.element).toBeNull();
    editor.undo(); await ready(fixture); expect(editor.active()!.element?.id).toBe('gamma');
  });

  it('blocks every schema mutation and confirmation when disabled, including registered alias fields', async () => {
    const groups = schema(); groups[0].elements[0].type = 'counter';
    const { fixture, editor, root } = await mount(groups, { counter: 'integer' });
    editor.updateElement(0, 0, 'name', 'Allowed edit'); await ready(fixture);
    fixture.componentRef.setInput('disabled', true); await ready(fixture);
    const before = editor.groups(), previous = editor.previous(), selection = editor.selection(), changes: ParameterGroup[][] = [];
    editor.groups.subscribe(value => changes.push(value)); const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true);
    editor.addGroup(); editor.removeGroup(0); editor.updateGroup(0, 'title', 'Blocked'); editor.addElement(0);
    editor.removeElement(0, 0); editor.updateElement(0, 0, 'type', 'string'); editor.moveGroup(0, 1);
    editor.moveElement(0, 0, 1); editor.moveToGroup(0, 0, '1'); editor.undo(); await ready(fixture);
    expect(editor.groups()).toBe(before); expect(editor.previous()).toBe(previous); expect(editor.selection()).toEqual(selection);
    expect(changes).toEqual([]); expect(confirm).not.toHaveBeenCalled(); expect(editor.renderType('counter')).toBe('integer');
    expect(field(root, 'Label').closest('fieldset')!.disabled).toBe(true);
    expect(button(root, 'Add group').disabled).toBe(true); expect(field(root, 'New input type').disabled).toBe(true);
  });

  it('filters only the outline without emitting a schema or concealing invalid hidden parameters', async () => {
    const groups = schema(); groups[0].elements[1].parameter = 'bad name';
    const { fixture, editor, root } = await mount(groups), changes: ParameterGroup[][] = [];
    editor.groups.subscribe(value => changes.push(value)); enter(root, 'Search parameters', 'Alpha'); await ready(fixture);
    expect(root.querySelectorAll('.outline-parameter')).toHaveLength(1);
    expect(root.querySelector('.outline-parameter')?.getAttribute('aria-label')).toBe('Edit parameter Alpha');
    expect(editor.validationState().valid).toBe(false);
    expect(editor.validationState().issues).toContainEqual(expect.objectContaining({ elementId: 'beta', code: 'parameter-name' }));
    expect(changes).toEqual([]); expect(editor.groups()).toBe(groups);
  });

  it('reveals a filtered invalid field, links its errors and focuses it from the issue summary', async () => {
    const groups = schema(); groups[0].elements[1].parameter = 'bad name';
    const { fixture, editor, root } = await mount(groups);
    enter(root, 'Search parameters', 'Alpha'); await ready(fixture);
    root.querySelector<HTMLButtonElement>('.builder-validation button')!.click(); await ready(fixture);
    expect(editor.search()).toBe(''); expect(editor.active()!.element?.id).toBe('beta');
    const input = field(root, 'Parameter name');
    expect(document.activeElement).toBe(input); expect(input.getAttribute('aria-invalid')).toBe('true');
    expect(root.querySelector(`[id="${input.getAttribute('aria-describedby')}"]`)?.textContent).toContain('without spaces');
    const replacement = schema(); replacement[0].elements[1].id = 'bad id';
    fixture.componentRef.setInput('groups', replacement); await ready(fixture);
    root.querySelector<HTMLButtonElement>('.builder-validation button')!.click(); await ready(fixture);
    const stableId = field(root, 'Parameter ID') as HTMLInputElement;
    expect(document.activeElement).toBe(stableId); expect(stableId.readOnly).toBe(true); expect(stableId.closest('details')!.open).toBe(true);
  });

  it('clears undo, search and preview when the parent replaces the external schema', async () => {
    const { fixture, editor, root } = await mount();
    enter(root, 'Label', 'Local draft'); enter(root, 'Search parameters', 'Alpha');
    button(root, 'Preview form').click(); await ready(fixture);
    expect(editor.previous()).not.toBeNull(); expect(editor.preview()).toBe(true);
    const replacement = [schema()[1]]; fixture.componentRef.setInput('groups', replacement); await ready(fixture);
    expect(editor.groups()).toBe(replacement); expect(editor.previous()).toBeNull(); expect(editor.search()).toBe(''); expect(editor.preview()).toBe(false);
    expect(editor.active()!.element?.id).toBe('gamma'); expect(root.querySelector('app-parameter-schema-preview')).toBeNull();
    expect(button(root, 'Undo last change').disabled).toBe(true);
  });

  it('creates typed alias parameters with stable unique IDs and falls back safely for an unknown type', async () => {
    const { fixture, editor, root } = await mount([], { counter: 'integer' });
    button(root, 'Create first group').click(); await ready(fixture);
    expect(editor.active()!.group.id).toMatch(/^group-/); expect(editor.active()!.element).toBeNull();
    enter(root, 'New input type', 'counter', 'change'); button(root, 'Add parameter').click(); await ready(fixture);
    const first = editor.active()!.element!;
    expect(first).toMatchObject({ type: 'counter', name: 'New parameter', parameter: '', default: '' }); expect(first.id).toMatch(/^element-/);
    expect(editor.renderType(first.type)).toBe('integer'); expect(document.activeElement).toBe(field(root, 'Label'));
    editor.newType.set('unregistered'); editor.addElement(0); await ready(fixture);
    expect(editor.active()!.element!.type).toBe('string'); expect(editor.active()!.element!.id).not.toBe(first.id);
    expect(editor.validationState().issues.some(issue => issue.code === 'unsupported-type')).toBe(false);
  });
});
