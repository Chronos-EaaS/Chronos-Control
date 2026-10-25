import { Component, input } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { PlotDefinition, PlotTypeAliases, ResultConfiguration, ResultConfigurationDocument } from '../../core/results-models';
import { ResultConfigurationEditor } from './result-configuration-editor';
import { ResultConfigurationPreview } from './result-configuration-preview';

@Component({ selector: 'app-result-configuration-preview', template: '{{ configuration()?.name }}' })
class PreviewStub {
  readonly configuration = input<ResultConfiguration | null | undefined>(null);
  readonly aliases = input<PlotTypeAliases>({});
}

const plot = (id: string, name: string, type = 'bar-plot'): PlotDefinition => ({
  id, name, type, parameter: ['box-plot', 'line-plot-multi', 'custom-box'].includes(type) ? 'workers' : 'latency',
  plotting: 'latency', orientation: 'horizontal', metadata: { zero: 0, flags: [false, '  café 🧪  '] },
});
const schema = (): ResultConfigurationDocument => ({
  version: '1.0', metadata: { retained: [false, 0, '  keep  '] },
  elements: {
    'system-main': { name: 'Main', metadata: { retained: 'Zürich & <literal>' }, all: [plot('alpha', 'Alpha'), plot('beta', 'Beta', 'line-plot')], job: [plot('gamma', 'Gamma', 'custom-box')] },
    'experiment-8-other': { name: 'Other', all: [plot('delta', 'Delta', 'pie-plot')], job: [plot('epsilon', 'Epsilon', 'line-plot-multi')] },
  },
});
const ready = async (fixture: ComponentFixture<ResultConfigurationEditor>) => { fixture.detectChanges(); await fixture.whenStable(); fixture.detectChanges(); };
async function mount(value = schema()) {
  const fixture = TestBed.createComponent(ResultConfigurationEditor);
  fixture.componentRef.setInput('document', value); fixture.componentRef.setInput('aliases', { 'custom-box': 'box-plot' });
  await ready(fixture);
  const root = fixture.nativeElement as HTMLElement, dialog = root.querySelector<HTMLDialogElement>('dialog')!;
  Object.defineProperties(dialog, {
    showModal: { configurable: true, value: vi.fn(() => dialog.setAttribute('open', '')) },
    close: { configurable: true, value: vi.fn(() => { dialog.removeAttribute('open'); dialog.dispatchEvent(new Event('close')); }) },
  });
  return { fixture, editor: fixture.componentInstance, root, dialog };
}
function findButton(root: HTMLElement, name: string): HTMLButtonElement | undefined {
  return [...root.querySelectorAll<HTMLButtonElement>('button')].find(node => node.getAttribute('aria-label') === name || node.textContent?.replace(/\s+/g, ' ').trim() === name);
}
function button(root: HTMLElement, name: string): HTMLButtonElement {
  const node = findButton(root, name); expect(node, `Button ${name}`).toBeDefined(); return node!;
}
function field(root: HTMLElement, name: string): HTMLInputElement | HTMLSelectElement {
  const label = [...root.querySelectorAll<HTMLLabelElement>('label[for]')].find(node => node.textContent?.replace(/\s+/g, ' ').trim() === name);
  expect(label, `Label ${name}`).toBeDefined(); return root.querySelector<HTMLInputElement | HTMLSelectElement>(`[id="${label!.htmlFor}"]`)!;
}

describe('focused result configuration builder', () => {
  beforeEach(async () => {
    await TestBed.configureTestingModule({ imports: [ResultConfigurationEditor] })
      .overrideComponent(ResultConfigurationEditor, { remove: { imports: [ResultConfigurationPreview] }, add: { imports: [PreviewStub] } })
      .compileComponents();
  });
  afterEach(() => vi.restoreAllMocks());

  it('navigates one chart at a time and filters only the outline without changing a draft or concealing validation', async () => {
    const value = schema(); value.elements['system-main'].all[1].parameter = '';
    const { fixture, editor, root } = await mount(value), changes: ResultConfigurationDocument[] = [];
    editor.document.subscribe(document => changes.push(document));
    expect(editor.active()?.plot?.id).toBe('alpha'); expect(root.querySelectorAll('select[id$="-type"]')).toHaveLength(1);
    editor.selectPlot('job', 0); await ready(fixture);
    expect(editor.active()?.plot?.id).toBe('gamma'); expect(field(root, 'Chart type').value).toBe('custom-box');
    expect(field(root, 'Orientation').value).toBe('horizontal');
    editor.search.set('Alpha'); await ready(fixture);
    const outline = root.querySelector<HTMLElement>('nav[aria-label="Result structure"]')!;
    expect(findButton(outline, 'Edit chart Alpha')).toBeDefined(); expect(findButton(outline, 'Edit chart Beta')).toBeUndefined();
    expect(editor.issues().join(' ')).toContain('Beta'); expect(editor.active()?.plot?.id).toBe('gamma');
    expect(editor.document()).toBe(value); expect(editor.previous()).toBeNull(); expect(changes).toEqual([]);
  });

  it('offers typed creation only in the selected scope and focuses the newly created chart', async () => {
    const { fixture, editor, root } = await mount();
    expect(findButton(root, 'Add chart')).toBeUndefined();
    editor.selectScope('job'); await ready(fixture);
    expect(editor.active()?.plot ?? null).toBeNull(); expect(button(root, 'Add chart').matches(':disabled')).toBe(false);
    editor.newType.set('custom-box'); button(root, 'Add chart').click(); await ready(fixture);
    expect(editor.active()?.scope).toBe('job'); expect(editor.active()?.plot).toMatchObject({ type: 'custom-box', name: 'New chart' });
    expect(editor.active()?.plot?.id).toMatch(/^plot-/); expect(editor.selected().job).toHaveLength(2);
    expect(findButton(root, 'Add chart')).toBeUndefined(); expect(document.activeElement).toBe(field(root, 'Title'));
    editor.selectScope('all'); await ready(fixture); editor.newType.set('not-registered'); editor.addPlot('all'); await ready(fixture);
    expect(editor.active()?.plot?.type).toBe('bar-plot'); expect(editor.selected().all).toHaveLength(3);
  });

  it('keeps chart selection attached when it or its neighbour moves and restores selection through Undo', async () => {
    const { fixture, editor } = await mount();
    editor.selectPlot('all', 1); await ready(fixture); editor.move('all', 1, -1); await ready(fixture);
    expect(editor.selected().all.map(item => item.id)).toEqual(['beta', 'alpha']); expect(editor.active()?.plot?.id).toBe('beta'); expect(editor.active()?.index).toBe(0);
    editor.move('all', 1, -1); await ready(fixture);
    expect(editor.selected().all.map(item => item.id)).toEqual(['alpha', 'beta']); expect(editor.active()?.plot?.id).toBe('beta'); expect(editor.active()?.index).toBe(1);
    editor.undo(); await ready(fixture);
    expect(editor.selected().all.map(item => item.id)).toEqual(['beta', 'alpha']); expect(editor.active()?.plot?.id).toBe('beta'); expect(editor.active()?.index).toBe(0);
  });

  it('retains raw alias types, hidden settings and document metadata while editing immutable drafts', async () => {
    const original = schema(), snapshot = structuredClone(original), { fixture, editor, root } = await mount(original);
    editor.selectPlot('job', 0); await ready(fixture);
    editor.update('job', 0, 'type', 'bar-plot'); editor.update('job', 0, 'name', 'Renamed café'); await ready(fixture);
    expect(root.querySelector('select[id$="-orientation"]')).toBeNull();
    expect(editor.selected().job[0]).toEqual({ ...snapshot.elements['system-main'].job[0], type: 'bar-plot', name: 'Renamed café' });
    editor.update('job', 0, 'type', 'custom-box'); await ready(fixture);
    expect(field(root, 'Orientation').value).toBe('horizontal'); expect(field(root, 'Result metric to plot').value).toBe('latency');
    expect(editor.document()['metadata']).toEqual(snapshot['metadata']); expect(editor.selected()['metadata']).toEqual(snapshot.elements['system-main']['metadata']);
    expect(original).toEqual(snapshot); expect(editor.document()).not.toBe(original);
  });

  it('confirms plot deletion and restores the deleted chart and selection with Undo', async () => {
    const { fixture, editor } = await mount(), confirm = vi.spyOn(window, 'confirm').mockReturnValue(false);
    editor.selectPlot('all', 1); await ready(fixture); const original = editor.document();
    editor.deletePlot('all', 1); await ready(fixture);
    expect(confirm).toHaveBeenCalledWith(expect.stringContaining('Beta')); expect(editor.document()).toBe(original); expect(editor.previous()).toBeNull();
    confirm.mockReturnValue(true); editor.deletePlot('all', 1); await ready(fixture);
    expect(editor.selected().all.map(item => item.id)).toEqual(['alpha']); expect(editor.active()?.plot?.id).toBe('alpha');
    editor.undo(); await ready(fixture); expect(editor.document()).toBe(original); expect(editor.active()?.plot?.id).toBe('beta');
  });

  it('keeps same-tick configuration and chart selection through copy, removal and Undo without losing metadata', async () => {
    const value = schema(), snapshot = structuredClone(value), { fixture, editor } = await mount(value);
    editor.selectConfiguration('experiment-8-other'); editor.selectPlot('job', 0); await ready(fixture);
    expect(editor.active()?.plot?.id).toBe('epsilon');
    editor.copy(); await ready(fixture);
    const copiedId = editor.selectedId(); expect(copiedId).not.toBe('experiment-8-other'); expect(copiedId.length).toBeLessThanOrEqual(50);
    expect(editor.selected()).toEqual({ ...snapshot.elements['experiment-8-other'], name: 'Other (copy)' }); expect(value).toEqual(snapshot);
    editor.undo(); await ready(fixture); expect(editor.document()).toBe(value); expect(editor.selectedId()).toBe('experiment-8-other'); expect(editor.active()?.plot?.id).toBe('epsilon');
    editor.pendingDelete.set(editor.selectedId()); editor.remove(); await ready(fixture);
    expect(Object.hasOwn(editor.document().elements, 'experiment-8-other')).toBe(false); expect(editor.pendingDelete()).toBe('');
    editor.undo(); await ready(fixture); expect(editor.document()).toBe(value); expect(editor.selectedId()).toBe('experiment-8-other');
  });

  it('blocks every public mutation and confirmation while disabled, including typed creation', async () => {
    const { fixture, editor, root } = await mount(); editor.update('all', 0, 'name', 'Allowed draft'); await ready(fixture);
    fixture.componentRef.setInput('disabled', true); await ready(fixture);
    const original = editor.document(), previous = editor.previous(), id = editor.selectedId(), selection = editor.selection(), active = editor.active(), changes: ResultConfigurationDocument[] = [];
    editor.document.subscribe(document => changes.push(document)); const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true);
    editor.add(); editor.copy(); editor.rename('Blocked'); editor.remove(); editor.addPlot('all'); editor.update('all', 0, 'name', 'Blocked'); editor.move('all', 0, 1); editor.deletePlot('all', 0); editor.undo(); await ready(fixture);
    expect(editor.document()).toBe(original); expect(editor.previous()).toBe(previous); expect(editor.selectedId()).toBe(id); expect(editor.selection()).toBe(selection); expect(editor.active()).toEqual(active); expect(changes).toEqual([]); expect(confirm).not.toHaveBeenCalled();
    expect(field(root, 'Title').matches(':disabled')).toBe(true); expect(button(root, 'New configuration').matches(':disabled')).toBe(true);
    editor.selectScope('all'); await ready(fixture); expect(button(root, 'Add chart').matches(':disabled')).toBe(true); expect(field(root, 'New chart type').matches(':disabled')).toBe(true);
  });

  it('ignores invalid plot indexes and mutation requests when no configuration exists', async () => {
    const { fixture, editor } = await mount(); const original = editor.document(), confirm = vi.spyOn(window, 'confirm').mockReturnValue(true);
    expect(() => { editor.update('all', 20, 'name', 'Invalid'); editor.move('all', -1, 1); editor.move('all', 0, -1); editor.deletePlot('job', 30); }).not.toThrow();
    await ready(fixture); expect(editor.document()).toBe(original); expect(editor.previous()).toBeNull(); expect(confirm).not.toHaveBeenCalled();
    const empty: ResultConfigurationDocument = { version: '1.0', elements: {} }; fixture.componentRef.setInput('document', empty); await ready(fixture);
    expect(() => { editor.copy(); editor.rename('Invalid'); editor.remove(); editor.addPlot('all'); editor.update('all', 0, 'name', 'Invalid'); editor.move('all', 0, 1); editor.deletePlot('all', 0); }).not.toThrow();
    await ready(fixture); expect(editor.document()).toBe(empty); expect(editor.previous()).toBeNull(); expect(confirm).not.toHaveBeenCalled();
  });

  it('follows explicit configuration and scope inputs without replacing an unsaved document', async () => {
    const { fixture, editor } = await mount(); editor.update('all', 0, 'name', 'Keep local draft'); await ready(fixture);
    const value = editor.document(), previous = editor.previous(), changes: ResultConfigurationDocument[] = []; editor.document.subscribe(document => changes.push(document));
    fixture.componentRef.setInput('configurationId', 'experiment-8-other'); fixture.componentRef.setInput('initialScope', 'job'); await ready(fixture);
    expect(editor.selectedId()).toBe('experiment-8-other'); expect(editor.scopes()[0].key).toBe('job'); expect(editor.active()?.scope).toBe('job'); expect(editor.active()?.plot?.id).toBe('epsilon');
    fixture.componentRef.setInput('initialScope', 'all'); await ready(fixture);
    expect(editor.active()?.scope).toBe('all'); expect(editor.active()?.plot?.id).toBe('delta');
    expect(editor.document()).toBe(value); expect(editor.previous()).toBe(previous); expect(editor.document().elements['system-main'].all[0].name).toBe('Keep local draft'); expect(changes).toEqual([]);
  });

  it('jumps to the precise filtered validation target across configurations and scopes even when chart names match', async () => {
    const value = schema();
    for (const configuration of Object.values(value.elements)) for (const scope of ['all', 'job'] as const) for (const chart of configuration[scope]) chart.name = 'Shared';
    value.elements['system-main'].all[1].parameter = '';
    value.elements['experiment-8-other'].job = [plot('safe-job', 'Shared'), { ...plot('target-job', 'Shared', 'line-plot-multi'), parameter: '' }];
    const snapshot = structuredClone(value), { fixture, editor, root } = await mount(value), changes: ResultConfigurationDocument[] = [];
    editor.document.subscribe(document => changes.push(document)); editor.search.set('Alpha'); await ready(fixture);
    const outline = root.querySelector<HTMLElement>('nav[aria-label="Result structure"]')!;
    expect(outline.querySelectorAll('.outline-chart')).toHaveLength(1);
    expect(editor.selectedId()).toBe('system-main'); expect(editor.active()?.plot?.id).toBe('alpha');
    const issue = editor.issueDetails().find(item => item.configurationId === 'experiment-8-other' && item.scope === 'job' && item.index === 1 && item.field === 'parameter');
    expect(issue).toBeDefined(); expect(editor.issueContext(issue!)).toBe('Other / Individual configurations / Chart 2 · Shared');
    const row = [...root.querySelectorAll<HTMLElement>('.alert[role="status"] li')].find(item => item.querySelector('.issue-context')?.textContent?.replace(/\s+/g, ' ').trim() === editor.issueContext(issue!));
    expect(row).toBeDefined(); row!.querySelector<HTMLButtonElement>('button')!.click(); await ready(fixture);
    expect(editor.selectedId()).toBe('experiment-8-other'); expect(editor.active()?.scope).toBe('job'); expect(editor.active()?.index).toBe(1); expect(editor.active()?.plot?.id).toBe('target-job'); expect(editor.search()).toBe('');
    const input = field(root, 'Configuration parameters to iterate over (comma-separated)');
    expect(input.value).toBe(''); expect(document.activeElement).toBe(input); expect(input.getAttribute('aria-invalid')).toBe('true');
    const errors = input.getAttribute('aria-describedby'); expect(errors).toBe(editor.id('job', 1, 'errors'));
    expect(root.querySelector(`[id="${errors}"]`)?.textContent).toContain('needs metric or configuration parameter names');
    expect(editor.fieldError('parameter')).toBe(issue!.message); expect(editor.fieldError('plotting')).toBe('');
    const target = outline.querySelector<HTMLButtonElement>('button[aria-current="true"]')!;
    expect(target.getAttribute('aria-describedby')).toBe(editor.id('job', 1, 'outline-errors'));
    expect(target.querySelector('.issue-count')?.textContent).toContain('1');
    expect(root.querySelector(`[id="${target.getAttribute('aria-describedby')}"]`)?.textContent).toContain('1 validation issues');
    expect(editor.document()).toBe(value); expect(changes).toEqual([]); expect(value).toEqual(snapshot);
    input.value = 'workers'; input.dispatchEvent(new Event('input', { bubbles: true })); await ready(fixture);
    expect(input.getAttribute('aria-invalid')).toBeNull(); expect(input.getAttribute('aria-describedby')).toBeNull();
    expect(editor.configurationIssues('experiment-8-other')).toEqual([]); expect(editor.issues()).toHaveLength(1);
    expect(outline.querySelector('button[aria-current="true"]')?.getAttribute('aria-describedby')).toBeNull();
    expect(outline.querySelector('button[aria-current="true"] .issue-count')).toBeNull();
    expect(editor.document().elements['experiment-8-other'].job[1].parameter).toBe('workers'); expect(value).toEqual(snapshot); expect(changes).toHaveLength(1);
  });

  it('closes and unmounts the native preview and resets stale editor state when the parent replaces the document', async () => {
    const { fixture, editor, root, dialog } = await mount(); editor.update('all', 0, 'name', 'Draft'); editor.search.set('Alpha'); editor.pendingDelete.set('system-main'); await ready(fixture);
    const opener = button(root, 'Preview charts'); expect(opener.getAttribute('aria-haspopup')).toBe('dialog'); expect(opener.getAttribute('aria-controls')).toBe(dialog.id);
    opener.click(); await ready(fixture); expect(dialog.open).toBe(true); expect(editor.preview()).toBe(true); expect(root.querySelector('app-result-configuration-preview')).not.toBeNull();
    const replacement: ResultConfigurationDocument = { version: '1.0', elements: { fresh: { name: 'Fresh', all: [plot('fresh-chart', 'Fresh chart')], job: [] } } };
    fixture.componentRef.setInput('document', replacement); await ready(fixture);
    expect(dialog.open).toBe(false); expect(editor.preview()).toBe(false); expect(root.querySelector('app-result-configuration-preview')).toBeNull();
    expect(editor.previous()).toBeNull(); expect(editor.search()).toBe(''); expect(editor.pendingDelete()).toBe(''); expect(editor.selectedId()).toBe('fresh'); expect(editor.active()?.plot?.id).toBe('fresh-chart');
  });

  it('handles modal close, Escape and backdrop without emitting a schema or accepting stale close events', async () => {
    const value = schema(), { fixture, editor, root, dialog } = await mount(value), opener = button(root, 'Preview charts'), changes: ResultConfigurationDocument[] = [];
    editor.document.subscribe(document => changes.push(document));
    opener.click(); await ready(fixture); expect(dialog.showModal).toHaveBeenCalledOnce(); expect(opener.getAttribute('aria-expanded')).toBe('true');
    expect(root.querySelector(`[id="${dialog.getAttribute('aria-labelledby')}"]`)?.textContent).toBe('Result configuration preview');
    dialog.dispatchEvent(new Event('close')); await ready(fixture); expect(dialog.open).toBe(true); expect(editor.preview()).toBe(true);
    button(root, 'Close preview').click(); await ready(fixture); expect(dialog.open).toBe(false); expect(editor.preview()).toBe(false); expect(opener.getAttribute('aria-expanded')).toBe('false');
    opener.click(); await ready(fixture); const cancel = new Event('cancel', { cancelable: true }); dialog.dispatchEvent(cancel); await ready(fixture);
    expect(cancel.defaultPrevented).toBe(true); expect(dialog.open).toBe(false); expect(editor.preview()).toBe(false);
    opener.click(); await ready(fixture); vi.spyOn(dialog, 'getBoundingClientRect').mockReturnValue({ left: 20, right: 620, top: 30, bottom: 430 } as DOMRect);
    dialog.dispatchEvent(new MouseEvent('click', { bubbles: true, clientX: 100, clientY: 100 })); await ready(fixture); expect(dialog.open).toBe(true);
    dialog.querySelector('.dialog-body')!.dispatchEvent(new MouseEvent('click', { bubbles: true, clientX: 0, clientY: 0 })); await ready(fixture); expect(dialog.open).toBe(true);
    dialog.dispatchEvent(new MouseEvent('click', { bubbles: true, clientX: 5, clientY: 100 })); await ready(fixture); expect(dialog.open).toBe(false); expect(editor.preview()).toBe(false);
    expect(root.querySelector('app-result-configuration-preview')).toBeNull(); expect(editor.document()).toBe(value); expect(editor.previous()).toBeNull(); expect(changes).toEqual([]);
  });
});
