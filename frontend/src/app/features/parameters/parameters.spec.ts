import { TestBed } from '@angular/core/testing';
import { beforeEach, describe, expect, it } from 'vitest';
import { ParameterGroup, ParameterValues } from '../../core/parameter-models';
import { ExperimentParameters } from './experiment-parameters';
import { ParameterSchemaEditor } from './parameter-schema-editor';
import { allBuiltinGroups } from './parameter-fixtures';

const ready = async (fixture: ReturnType<typeof TestBed.createComponent>) => { fixture.detectChanges(); await fixture.whenStable(); fixture.detectChanges(); };

describe('reusable parameter components', () => {
  beforeEach(async () => { await TestBed.configureTestingModule({ imports: [ExperimentParameters, ParameterSchemaEditor] }).compileComponents(); });

  it('renders twelve types with unique IDs, linked labels, safe text and independent version controls', async () => {
    const fixture = TestBed.createComponent(ExperimentParameters);
    const groups = allBuiltinGroups();
    groups[0].elements.push({ id: 'second-version', name: 'Server revision', type: 'software-versions-list', parameter: 'server' });
    fixture.componentRef.setInput('groups', groups);
    fixture.componentRef.setInput('values', { 'revision-version-0': 'main', 'server-version-0': 'v2' });
    await ready(fixture);
    const root: HTMLElement = fixture.nativeElement;
    const ids = Array.from(root.querySelectorAll('[id]')).map(node => node.id);
    expect(new Set(ids).size).toBe(ids.length);
    for (const label of Array.from(root.querySelectorAll('label[for]'))) expect(root.querySelector(`[id="${label.getAttribute('for')}"]`)).not.toBeNull();
    expect(root.querySelectorAll('app-parameter-inputs')).toHaveLength(13);
    expect(root.querySelector('input[name="revision-version-0"]')).not.toBeNull();
    expect(root.querySelector('input[name="server-version-0"]')).not.toBeNull();
    expect((root.querySelector('input[name="note"]') as HTMLInputElement).value).toBe('<not markup>');
    expect(root.querySelector('not')).toBeNull();
  });

  it('emits updated draft and derived percentage values from a real input event', async () => {
    const fixture = TestBed.createComponent(ExperimentParameters);
    fixture.componentRef.setInput('groups', allBuiltinGroups());
    const changes: ParameterValues[] = [];
    fixture.componentInstance.values.subscribe(value => changes.push(value));
    await ready(fixture);
    const input = fixture.nativeElement.querySelector('input[name="total"]') as HTMLInputElement;
    input.value = '0'; input.dispatchEvent(new Event('input', { bubbles: true }));
    await ready(fixture);
    expect(changes.at(-1)?.['total']).toBe('0');
    expect(changes.at(-1)?.['share']).toBe(0);
    expect(fixture.componentInstance.submissionValues()['total']).toBe('0');
    expect(fixture.componentInstance.submissionValues()).not.toHaveProperty('undefined');
  });

  it('removes inactive fields from rendered inputs and payload but restores copied zero later', async () => {
    const groups: ParameterGroup[] = [{ id: 'base', title: 'Base', depends: '', dependsValue: '', elements: [{ id: 'enabled', name: 'Enabled', type: 'boolean', parameter: 'enabled', default: false }] },
      { id: 'dependent', title: 'Dependent', depends: 'enabled', dependsValue: 'true', elements: [{ id: 'size', name: 'Size', type: 'integer', parameter: 'size', default: 9 }] }];
    const fixture = TestBed.createComponent(ExperimentParameters);
    fixture.componentRef.setInput('groups', groups); fixture.componentRef.setInput('values', { enabled: false, size: 0 });
    await ready(fixture);
    expect(fixture.nativeElement.querySelector('input[name="size"]')).toBeNull();
    expect(fixture.componentInstance.submissionValues()).not.toHaveProperty('size');
    fixture.componentInstance.update({ key: 'enabled', value: 'true' }); await ready(fixture);
    expect((fixture.nativeElement.querySelector('input[name="size"]') as HTMLInputElement).value).toBe('0');
    expect(fixture.componentInstance.submissionValues()['size']).toBe(0);
  });

  it('renders unknown types as a blocking error and never runs supplied markup', async () => {
    const fixture = TestBed.createComponent(ExperimentParameters);
    fixture.componentRef.setInput('groups', [{ id: 'main', title: 'Custom', depends: '', dependsValue: '', elements: [{ id: 'custom', name: '<script>unsafe</script>', type: 'custom-renderer', parameter: 'custom' }] }]);
    await ready(fixture);
    expect(fixture.componentInstance.validationState().valid).toBe(false);
    expect(fixture.nativeElement.textContent).toContain('Unsupported parameter type');
    expect(fixture.nativeElement.querySelector('script')).toBeNull();
  });

  it('supports immutable schema editing, Unicode titles, keyboard buttons, reorder and undo', async () => {
    const fixture = TestBed.createComponent(ParameterSchemaEditor);
    const groups = allBuiltinGroups(), original = JSON.stringify(groups);
    fixture.componentRef.setInput('groups', groups); await ready(fixture);
    fixture.componentInstance.updateGroup(0, 'title', 'Query plans with spaces & café');
    expect(fixture.componentInstance.groups()[0].title).toBe('Query plans with spaces & café');
    expect(fixture.componentInstance.groups()[0].id).toBe('group-main');
    fixture.componentInstance.moveElement(0, 0, 1);
    expect(fixture.componentInstance.groups()[0].elements[1].parameter).toBe('enabled');
    fixture.componentInstance.undo();
    expect(fixture.componentInstance.groups()[0].elements[0].parameter).toBe('enabled');
    expect(JSON.stringify(groups)).toBe(original);
    await ready(fixture);
    const buttons = Array.from(fixture.nativeElement.querySelectorAll('button')) as HTMLButtonElement[];
    expect(buttons.filter(button => button.textContent?.includes('Move up')).every(button => button.type === 'button')).toBe(true);
  });
  it('keeps a disabled experiment read-only even when an update is attempted directly', async () => {
    const fixture = TestBed.createComponent(ExperimentParameters);
    fixture.componentRef.setInput('groups', allBuiltinGroups());
    fixture.componentRef.setInput('values', { total: 0 });
    fixture.componentRef.setInput('disabled', true);
    await ready(fixture);
    fixture.componentInstance.update({ key: 'total', value: '99' });
    expect(fixture.componentInstance.values()['total']).toBe(0);
    const fieldsets = Array.from(fixture.nativeElement.querySelectorAll('app-parameter-inputs fieldset')) as HTMLFieldSetElement[];
    expect(fieldsets.every(fieldset => fieldset.disabled)).toBe(true);
  });

  it('clears undo history when a parent loads another system schema', async () => {
    const fixture = TestBed.createComponent(ParameterSchemaEditor);
    fixture.componentRef.setInput('groups', allBuiltinGroups()); await ready(fixture);
    fixture.componentInstance.updateGroup(0, 'title', 'Edited system'); await ready(fixture);
    expect(fixture.componentInstance.previous()).not.toBeNull();
    const anotherSystem = [{ id: 'other', title: 'Another system', depends: '', dependsValue: '', elements: [] }];
    fixture.componentRef.setInput('groups', anotherSystem); await ready(fixture);
    expect(fixture.componentInstance.previous()).toBeNull();
    fixture.componentInstance.undo();
    expect(fixture.componentInstance.groups()).toBe(anotherSystem);
  });

});
