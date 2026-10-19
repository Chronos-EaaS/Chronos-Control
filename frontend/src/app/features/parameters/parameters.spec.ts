import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ActivatedRoute, Router, convertToParamMap, provideRouter } from '@angular/router';
import { of } from 'rxjs';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { Api } from '../../core/api';
import { ParameterGroup, ParameterValues } from '../../core/parameter-models';
import { Session } from '../../core/session';
import { UnsavedChanges } from '../../core/unsaved';
import { SystemDetail } from '../systems/system-detail';
import { ExperimentParameters } from './experiment-parameters';
import { ParameterSchemaEditor } from './parameter-schema-editor';
import { allBuiltinGroups } from './parameter-fixtures';

const ready = async (fixture: ReturnType<typeof TestBed.createComponent>) => { fixture.detectChanges(); await fixture.whenStable(); fixture.detectChanges(); };

describe('reusable parameter components', () => {
  afterEach(() => vi.restoreAllMocks());
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

  it('restores percentage input prefixes while retaining linked labels, editable values and calculated output', async () => {
    const fixture = TestBed.createComponent(ExperimentParameters);
    fixture.componentRef.setInput('groups', allBuiltinGroups());
    await ready(fixture);
    const root = fixture.nativeElement as HTMLElement;
    const fields = [...root.querySelectorAll<HTMLInputElement>('input[name*="-percentage"]')];
    expect(fields).toHaveLength(5);
    for (const input of fields) {
      expect(input.closest('.input-group')?.querySelector('.input-group-text')?.textContent).toContain('%');
      expect(input.closest('.input-group')?.querySelector('.input-group-text')?.getAttribute('aria-hidden')).toBe('true');
      expect(root.querySelector(`label[for="${input.id}"]`)?.textContent).toMatch(/percentage/i);
      expect(input.readOnly).toBe(false);
    }
    const share = root.querySelector<HTMLInputElement>('input[name="share-percentage"]')!;
    share.value = '40'; share.dispatchEvent(new Event('input', { bubbles: true })); await ready(fixture);
    expect(fixture.componentInstance.submissionValues()['share-percentage']).toBe('40');
    expect(fixture.componentInstance.submissionValues()['share']).toBe(40);
    const calculated = root.querySelector<HTMLInputElement>('input[name="share"]')!;
    expect(calculated.value).toBe('40');
    expect(calculated.readOnly).toBe(true);
    expect(calculated.closest('.input-group')).toBeNull();
  });

  it('renders independent actual values and calculated interval previews that share the same spelling', async () => {
    const groups: ParameterGroup[] = [{ id: 'base', title: 'Base', depends: '', dependsValue: '', elements: [
      { id: 'total', name: 'Total', type: 'integer', parameter: 'total', default: 100 },
      { id: 'actual', name: 'Actual start', type: 'integer', parameter: 'share-start', default: 7 },
      { id: 'share', name: 'Share', type: 'percentage-interval', parameter: 'share', dependency: 'total', 'default-start': 50, 'default-end': 100, 'default-step': 50 },
    ] }];
    const fixture = TestBed.createComponent(ExperimentParameters); fixture.componentRef.setInput('groups', groups); await ready(fixture);
    const actual = fixture.nativeElement.querySelector('input[name="share-start"]:not([readonly])') as HTMLInputElement;
    const preview = fixture.nativeElement.querySelector('input[name="share-start"][readonly]') as HTMLInputElement;
    expect(actual.value).toBe('7'); expect(preview.value).toBe('50');
    actual.value = '1000'; actual.dispatchEvent(new Event('input', { bubbles: true })); await ready(fixture);
    expect(actual.value).toBe('1000'); expect(preview.value).toBe('50'); expect(fixture.componentInstance.draft()['share-start']).toBe('1000');
    expect(fixture.componentInstance.validationState().valid).toBe(true);
    const percentage = fixture.nativeElement.querySelector('input[name="share-percentage-start"]') as HTMLInputElement;
    percentage.value = '60'; percentage.dispatchEvent(new Event('input', { bubbles: true })); await ready(fixture);
    expect(preview.value).toBe('60'); expect(actual.value).toBe('1000'); expect(fixture.componentInstance.submissionValues()['share-start']).toBe('1000');
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
    expect((fixture.nativeElement.querySelector('select[name="enabled"]') as HTMLSelectElement).value).toBe('true');
  });
  it('uses declared parameter values ahead of same-named parent execution controls', async () => {
    const groups: ParameterGroup[] = [
      { id: 'base', title: 'System parameters', depends: '', dependsValue: '', elements: [
        { id: 'runs-field', name: 'System runs', type: 'integer', parameter: 'runs', default: 3 },
        { id: 'distribution-field', name: 'System distribution', type: 'dropdown', parameter: 'run-distribution', options: 'alter,order', default: 'order' },
        { id: 'warm-field', name: 'System warm-up', type: 'boolean', parameter: 'phase_warmUp', default: false },
      ] },
      ...[['runs-detail', 'runs', '3'], ['order-detail', 'run-distribution', 'order'], ['warm-detail', 'phase_warmUp', 'false']].map(([id, depends, dependsValue]) => ({ id, title: id, depends, dependsValue, elements: [{ id: id + '-field', name: id, type: 'integer', parameter: id, default: 0 }] })),
    ];
    const context = { runs: 2, 'run-distribution': 'alter', phase_warmUp: '1' };
    const fixture = TestBed.createComponent(ExperimentParameters); fixture.componentRef.setInput('groups', groups); fixture.componentRef.setInput('visibilityContext', context); await ready(fixture);
    expect(fixture.componentInstance.visible()).toEqual([true, true, true, true]);
    expect(fixture.componentInstance.submissionValues()).toMatchObject({ runs: 3, 'run-distribution': 'order', phase_warmUp: false });
    const input = fixture.nativeElement.querySelector('input[name="runs"]') as HTMLInputElement; input.value = '2'; input.dispatchEvent(new Event('input', { bubbles: true })); await ready(fixture);
    expect(fixture.componentInstance.visible()).toEqual([true, false, true, true]);
    expect(fixture.componentInstance.submissionValues()).not.toHaveProperty('runs-detail');
    expect(context).toEqual({ runs: 2, 'run-distribution': 'alter', phase_warmUp: '1' });
  });
  it('validates and submits parent-dependent fields only while their execution condition is active', async () => {
    const groups: ParameterGroup[] = [{ id: 'versions', title: 'Repeated versions', depends: 'runs', dependsValue: '2', elements: [{ id: 'revision', name: 'Revision', type: 'software-versions-list', parameter: 'revision' }] }];
    const fixture = TestBed.createComponent(ExperimentParameters); fixture.componentRef.setInput('groups', groups); fixture.componentRef.setInput('visibilityContext', { runs: 1 }); await ready(fixture);
    expect(fixture.componentInstance.validationState().valid).toBe(true); expect(fixture.componentInstance.submissionValues()['elements']).toEqual([]);
    fixture.componentRef.setInput('visibilityContext', { runs: 2 }); await ready(fixture);
    expect(fixture.componentInstance.validationState().valid).toBe(false); expect(fixture.nativeElement.textContent).toContain('Enter version 1.');
    const input = fixture.nativeElement.querySelector('input[name="revision-version-0"]') as HTMLInputElement; input.value = 'fixture-stable'; input.dispatchEvent(new Event('input', { bubbles: true })); await ready(fixture);
    expect(fixture.componentInstance.validationState().valid).toBe(true);
    fixture.componentRef.setInput('visibilityContext', { runs: 1 }); await ready(fixture); expect(fixture.componentInstance.submissionValues()['elements']).toEqual([]);
    fixture.componentRef.setInput('visibilityContext', { runs: 2 }); await ready(fixture); expect((fixture.nativeElement.querySelector('input[name="revision-version-0"]') as HTMLInputElement).value).toBe('fixture-stable');
    expect(fixture.componentInstance.draft()).not.toHaveProperty('runs');
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
    expect(Array.from((fixture.nativeElement as HTMLElement).querySelectorAll<HTMLSelectElement>('select[id$="-type"]:not([id$="-parameter-type"])')).map(select => select.value)).toEqual(groups.flatMap(group => group.elements.map(element => element.type)));
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
  it('keeps an XML-incompatible parameter-name edit visible until corrected without altering valid Unicode data', async () => {
    const groups: ParameterGroup[] = [{ id: 'main', title: 'Metadata \u{10400}', depends: '', dependsValue: '', elements: [
      { id: 'text-input', name: 'Label 🧪', type: 'string', parameter: 'label', default: '🧪 \u{1FFFE}' },
    ] }];
    const original = JSON.stringify(groups), fixture = TestBed.createComponent(ParameterSchemaEditor);
    fixture.componentRef.setInput('groups', groups); await ready(fixture);
    const validity: boolean[] = []; fixture.componentInstance.validity.subscribe(value => validity.push(value));
    const input = fixture.nativeElement.querySelector('input[id$="-parameter"]') as HTMLInputElement;
    for (const name of ['\u{10400}', 'ª', 'µ', 'º']) {
      input.value = name; input.dispatchEvent(new Event('input', { bubbles: true })); await ready(fixture);
      expect(fixture.componentInstance.validationState().valid).toBe(false); expect(validity.at(-1)).toBe(false);
      expect(fixture.componentInstance.validationState().issues.some(issue => issue.code === 'parameter-name')).toBe(true);
      expect(input.value).toBe(name); expect(fixture.componentInstance.groups()[0].elements[0].default).toBe('🧪 \u{1FFFE}');
    }
    expect(JSON.stringify(groups)).toBe(original);
    input.value = 'Größe'; input.dispatchEvent(new Event('input', { bubbles: true })); await ready(fixture);
    expect(fixture.componentInstance.validationState()).toEqual({ valid: true, issues: [] }); expect(validity.at(-1)).toBe(true);
    expect(fixture.componentInstance.groups()[0]).toMatchObject({ title: 'Metadata \u{10400}', elements: [{ parameter: 'Größe', name: 'Label 🧪', default: '🧪 \u{1FFFE}' }] });
  });
  it('confirms parameter/group draft deletions, preserves cancellation and supports Undo', async () => {
    const fixture = TestBed.createComponent(ParameterSchemaEditor), original = allBuiltinGroups(); fixture.componentRef.setInput('groups', original); await ready(fixture);
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false); const before = JSON.stringify(original);
    const button = (name: string) => [...fixture.nativeElement.querySelectorAll('button')].find((item: any) => item.textContent?.trim() === name) as HTMLButtonElement;
    button('Delete parameter').click(); await ready(fixture); expect(JSON.stringify(fixture.componentInstance.groups())).toBe(before); expect(fixture.componentInstance.previous()).toBeNull();
    confirm.mockReturnValue(true); button('Delete parameter').click(); await ready(fixture); expect(fixture.componentInstance.groups()[0].elements).toHaveLength(original[0].elements.length - 1); fixture.componentInstance.undo(); await ready(fixture); expect(JSON.stringify(fixture.componentInstance.groups())).toBe(before);
    confirm.mockReturnValue(false); button('Delete group').click(); await ready(fixture); expect(JSON.stringify(fixture.componentInstance.groups())).toBe(before);
    confirm.mockReturnValue(true); button('Delete group').click(); await ready(fixture); expect(fixture.componentInstance.groups()).toHaveLength(original.length - 1); fixture.componentInstance.undo(); await ready(fixture); expect(JSON.stringify(fixture.componentInstance.groups())).toBe(before); expect(JSON.stringify(original)).toBe(before);
  });
  it('renders registered aliases with native controls and preserves custom schema types and copied false/zero', async () => {
    const groups: ParameterGroup[] = [{ id: 'custom-group', title: 'Custom inputs', depends: '', dependsValue: '', elements: [
      { id: 'custom-flag', type: 'custom-boolean', name: 'Custom flag', parameter: 'flag', default: true, privateMetadata: 0 },
      { id: 'custom-count', type: 'custom-integer', name: 'Custom count', parameter: 'count', default: 10 },
    ] }];
    const before = JSON.stringify(groups), aliases = { 'custom-boolean': 'boolean', 'custom-integer': 'integer' };
    const fixture = TestBed.createComponent(ExperimentParameters); fixture.componentRef.setInput('groups', groups); fixture.componentRef.setInput('aliases', aliases); fixture.componentRef.setInput('values', { flag: false, count: 0 }); await ready(fixture);
    expect(fixture.componentInstance.validationState().valid).toBe(true); expect(fixture.componentInstance.draft()['flag']).toBe(false); expect(fixture.componentInstance.draft()['count']).toBe(0);
    expect(fixture.nativeElement.querySelector('input[type="number"][name="count"]')).not.toBeNull(); expect(fixture.nativeElement.textContent).not.toContain('Unsupported type');
    const editor = TestBed.createComponent(ParameterSchemaEditor); editor.componentRef.setInput('groups', groups); editor.componentRef.setInput('aliases', aliases); await ready(editor);
    expect(editor.componentInstance.validationState().valid).toBe(true); editor.componentInstance.updateElement(0, 0, 'name', 'Renamed custom flag'); await ready(editor);
    expect(editor.componentInstance.groups()[0].elements[0]).toMatchObject({ type: 'custom-boolean', privateMetadata: 0 }); expect(JSON.stringify(groups)).toBe(before);
    fixture.componentRef.setInput('aliases', { 'custom-boolean': 'unregistered-intermediate' }); await ready(fixture); expect(fixture.componentInstance.validationState().valid).toBe(false);
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

describe('authoritative parameter-schema HTTP validation', () => {
  afterEach(() => { vi.restoreAllMocks(); TestBed.resetTestingModule(); });
  it('shows a native XML-name rejection and retains the actual editor draft without success or navigation', async () => {
    await TestBed.configureTestingModule({ imports: [SystemDetail], providers: [
      provideHttpClient(), provideHttpClientTesting(), provideRouter([]),
      { provide: Session, useValue: { isAdmin: () => false } },
      { provide: ActivatedRoute, useValue: { paramMap: of(convertToParamMap({ id: '1' })), queryParamMap: of(convertToParamMap({ tab: 'parameters' })), snapshot: { queryParamMap: convertToParamMap({ tab: 'parameters' }) } } },
    ] }).compileComponents();
    const http = TestBed.inject(HttpTestingController); TestBed.inject(Api).csrfToken.set('synthetic-schema-token');
    const navigate = vi.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
    const fixture = TestBed.createComponent(SystemDetail); fixture.detectChanges();
    http.expectOne('/api/ui/systems/1').flush({ data: {
      id: 1, name: 'Synthetic system', description: '', ownerId: 2, archived: false, cem: false, identifier: 'fixture-system', permissions: { canEdit: true }, logoUrl: '/api/ui/systems/1/logo', createdAt: null, updatedAt: null, hasLegacyResults: false,
      settings: { general: {}, setup: {}, defaultValues: {} }, environments: [], repository: { url: '', type: 'git', branch: 'master', username: '', hasPassword: false, canEdit: false, remoteEnabled: false },
    } });
    await vi.waitFor(() => { expect(fixture.componentInstance.error()).toBe(''); http.expectOne('/api/ui/systems/1/parameters').flush({ data: { groups: [{ id: 'main', title: 'Raw values 🧪', depends: '', dependsValue: '', elements: [{ id: 'text', name: 'Label', type: 'string', parameter: 'label', default: '🧪 \u{1FFFE}' }] }] } }); });
    await vi.waitFor(() => http.expectOne('/api/ui/systems/1/result-configurations').flush({ data: { version: '1.0', elements: {} } }));
    TestBed.tick();
    const extensions = http.match('/api/ui/systems/1/extensions');
    expect(extensions).toHaveLength(2); // The schema aliases and visible extension catalog each load this resource.
    for (const request of extensions) request.flush({ data: { aliases: { parameters: {}, plots: {} }, pages: [], diagnostics: [] } });
    await ready(fixture);
    await vi.waitFor(() => { fixture.detectChanges(); expect(fixture.componentInstance.loading()).toBe(false); });
    const root = fixture.nativeElement as HTMLElement;
    const input = root.querySelector('input[id$="-parameter"]') as HTMLInputElement;
    input.value = 'Ĳ'; input.dispatchEvent(new Event('input', { bubbles: true })); await ready(fixture);
    const save = [...root.querySelectorAll('button')].find(button => button.textContent?.trim() === 'Save parameters')!;
    expect(save.matches(':disabled')).toBe(false); save.click();
    const request = http.expectOne('/api/ui/systems/1/parameters');
    expect(request.request.method).toBe('PUT'); expect(request.request.headers.get('X-CSRF-Token')).toBe('synthetic-schema-token');
    expect(request.request.body.groups[0].elements[0]).toMatchObject({ parameter: 'Ĳ', default: '🧪 \u{1FFFE}' });
    request.flush({ error: { code: 'validation_failed', message: 'Review the parameter definition.', fields: { 'groups.0.elements.0.parameter': 'Choose a parameter name supported by agent XML.' } } }, { status: 422, statusText: 'Unprocessable Entity' });
    await ready(fixture);
    await vi.waitFor(() => { fixture.detectChanges(); expect(fixture.componentInstance.saving()).toBe(false); expect(root.textContent).toContain('Choose a parameter name supported by agent XML.'); });
    expect(root.textContent).toContain('Choose a parameter name supported by agent XML.');
    expect(input.value).toBe('Ĳ'); expect(fixture.componentInstance.groups()[0].elements[0]).toMatchObject({ parameter: 'Ĳ', default: '🧪 \u{1FFFE}' });
    expect(fixture.componentInstance.dirty().has('parameters')).toBe(true); expect(TestBed.inject(UnsavedChanges).dirty()).toBe(true);
    expect(fixture.componentInstance.success()).toBe(''); expect(fixture.componentInstance.saving()).toBe(false);
    expect(save.disabled).toBe(false); expect(navigate).not.toHaveBeenCalled(); http.verify();
  });
});
