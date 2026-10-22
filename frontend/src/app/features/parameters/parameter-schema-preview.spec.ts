import { reflectComponentType } from '@angular/core';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { ParameterGroup } from '../../core/parameter-models';
import { allBuiltinGroups, element, group } from './parameter-fixtures';
import { ParameterSchemaPreview } from './parameter-schema-preview';

const ready = async (fixture: ReturnType<typeof TestBed.createComponent>) => {
  fixture.detectChanges(); await fixture.whenStable(); fixture.detectChanges();
};
function freeze<T>(value: T): T {
  if (value !== null && typeof value === 'object') {
    for (const item of Object.values(value)) freeze(item);
    Object.freeze(value);
  }
  return value;
}

describe('Parameter schema preview uses the real experiment form without saving sample values', () => {
  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [ParameterSchemaPreview],
      providers: [provideHttpClient(), provideHttpClientTesting()],
    }).compileComponents();
  });
  afterEach(() => TestBed.inject(HttpTestingController).verify());

  async function setup(groups: ParameterGroup[], aliases = {}) {
    const fixture = TestBed.createComponent(ParameterSchemaPreview);
    fixture.componentRef.setInput('groups', groups);
    fixture.componentRef.setInput('aliases', aliases);
    await ready(fixture);
    return fixture;
  }
  async function edit(fixture: ReturnType<typeof TestBed.createComponent>, selector: string, value: string) {
    const input = fixture.nativeElement.querySelector(selector) as HTMLInputElement;
    expect(input, selector).not.toBeNull();
    input.value = value; input.dispatchEvent(new Event('input', { bubbles: true }));
    await ready(fixture);
  }
  function reset(fixture: ReturnType<typeof TestBed.createComponent>) {
    const button = [...(fixture.nativeElement as HTMLElement).querySelectorAll('button')].find(item => item.textContent?.trim() === 'Reset preview')!;
    button.click();
  }

  it('renders all twelve bundled types, calculated values and safe text without network requests or schema outputs', async () => {
    const groups = freeze(allBuiltinGroups()), before = JSON.stringify(groups);
    const fixture = await setup(groups);
    const root = fixture.nativeElement as HTMLElement;
    expect(root.querySelectorAll('app-parameter-inputs')).toHaveLength(12);
    expect(root.querySelector<HTMLInputElement>('input[name="note"]')!.value).toBe('<not markup>');
    expect(root.querySelector('not')).toBeNull();
    expect(root.querySelector<HTMLInputElement>('input[name="share"]')!.value).toBe('25');
    await edit(fixture, 'input[name="total"]', '200');
    expect(root.querySelector<HTMLInputElement>('input[name="share"]')!.value).toBe('50');
    expect(fixture.componentInstance.sampleValues()['total']).toBe('200');
    expect(JSON.stringify(groups)).toBe(before);
    expect(fixture.componentInstance.groups()).toBe(groups);
    expect(reflectComponentType(ParameterSchemaPreview)!.outputs).toEqual([]);
    TestBed.inject(HttpTestingController).expectNone(() => true);
  });

  it('preserves false, zero and empty defaults and resolves aliases without rewriting their raw type or metadata', async () => {
    const groups = freeze([group([
      element('boolean', 'flag', { default: false }),
      element('string', 'empty', { default: '' }),
      element('dropdown', 'choice', { options: ['0','1'], names: 'Zero,One', default: '0' }),
      element('custom-count', 'count', { default: 0, privateMetadata: { retain: true } }),
    ])]);
    const before = JSON.stringify(groups), fixture = await setup(groups, { 'custom-count': 'integer' });
    const root = fixture.nativeElement as HTMLElement;
    expect(root.querySelector<HTMLSelectElement>('select[name="flag"]')!.value).toBe('false');
    expect(root.querySelector<HTMLSelectElement>('select[name="choice"]')!.value).toBe('0');
    expect(root.querySelector<HTMLInputElement>('input[name="count"]')!.value).toBe('0');
    expect(root.querySelector<HTMLInputElement>('input[name="empty"]')!.value).toBe('');
    await edit(fixture, 'input[name="count"]', '42');
    expect(fixture.componentInstance.groups()[0].elements[3].type).toBe('custom-count');
    expect(JSON.stringify(groups)).toBe(before);
    reset(fixture); await ready(fixture);
    expect(root.querySelector<HTMLInputElement>('input[name="count"]')!.value).toBe('0');
    expect(root.querySelector<HTMLSelectElement>('select[name="flag"]')!.value).toBe('false');
  });

  it('exposes free-text external condition values while real defaults and generated fields retain ownership', async () => {
    const base = group([
      element('boolean', 'enabled', { default: false }),
      element('interval', 'size', { 'default-min': 1, 'default-max': 3, 'default-step': 1 }),
      element('custom-count', 'count', { default: 0 }),
    ]);
    const conditional = (id: string, depends: string, dependsValue: string | number | boolean): ParameterGroup => group(
      [element('string', id, { default: id })], { id: 'group-' + id, title: id, depends, dependsValue },
    );
    const groups = freeze([base,
      conditional('boolean-child', 'enabled', false), conditional('interval-child', 'size-min', 1), conditional('alias-child', 'count', 0),
      conditional('runs-child', 'runs', 2), conditional('distribution-child', 'run-distribution', 'order'),
      conditional('warmup-child', 'phase_warmUp', '0'), conditional('tenant-child', 'tenant.mode', 'blue'),
    ]);
    const before = JSON.stringify(groups), fixture = await setup(groups, { 'custom-count': 'integer' });
    const root = fixture.nativeElement as HTMLElement;
    expect(fixture.componentInstance.contextKeys()).toEqual(['runs','run-distribution','phase_warmUp','tenant.mode']);
    for (const field of ['boolean-child','interval-child','alias-child']) expect(root.querySelector('input[name="' + field + '"]')).not.toBeNull();
    for (const field of ['runs-child','distribution-child','warmup-child','tenant-child']) expect(root.querySelector('input[name="' + field + '"]')).toBeNull();
    expect(root.querySelector('.preview-hidden')!.textContent).toContain('runs-child');
    for (const [key,value] of [['runs','2'],['run-distribution','order'],['phase_warmUp','0'],['tenant.mode','blue']]) {
      const input = root.querySelector<HTMLInputElement>('[data-condition-key="' + key + '"]')!;
      expect(input.type).toBe('text');
      expect(root.querySelector('label[for="' + input.id + '"]')!.textContent).toBe(key);
      await edit(fixture, '[data-condition-key="' + key + '"]', value);
    }
    for (const field of ['runs-child','distribution-child','warmup-child','tenant-child']) expect(root.querySelector('input[name="' + field + '"]')).not.toBeNull();
    expect(root.querySelector('.preview-hidden')).toBeNull();
    expect(JSON.stringify(groups)).toBe(before);
    reset(fixture); await ready(fixture);
    expect(fixture.componentInstance.parentValues()).toEqual({});
    expect(fixture.componentInstance.sampleValues()).toEqual({});
    expect(root.querySelector('input[name="runs-child"]')).toBeNull();
  });

  it('keeps generated version conditions in the real form rather than overriding them with parent samples', async () => {
    const groups = freeze([group([element('software-versions-list','revision')]),
      group([element('string','version-note')], { id:'version-group',title:'Second version',depends:'revision-version-1',dependsValue:'v2' }),
    ]);
    const fixture = await setup(groups);
    expect(fixture.componentInstance.contextKeys()).toEqual([]);
    await edit(fixture, 'input[name="revision-numberOfVersions"]', '2');
    // Version counts intentionally commit on change in the actual renderer.
    const count = fixture.nativeElement.querySelector('input[name="revision-numberOfVersions"]') as HTMLInputElement;
    count.dispatchEvent(new Event('change', { bubbles:true })); await ready(fixture);
    await edit(fixture, 'input[name="revision-version-1"]', 'v2');
    expect(fixture.nativeElement.querySelector('input[name="version-note"]')).not.toBeNull();
  });

  it('treats a visibly empty parent sample as an explicit empty condition value', async () => {
    const fixture = await setup([group([element('string','note')], { depends:'parent-mode',dependsValue:'' })]);
    expect(fixture.nativeElement.querySelector('input[name="note"]')).not.toBeNull();
    await edit(fixture, '[data-condition-key="parent-mode"]', 'active');
    expect(fixture.nativeElement.querySelector('input[name="note"]')).toBeNull();
    reset(fixture); await ready(fixture);
    expect(fixture.nativeElement.querySelector('input[name="note"]')).not.toBeNull();
  });

  it('resets to the latest schema defaults rather than persisting previously tried values', async () => {
    const fixture = await setup([group([element('integer','count',{ default: 0 })])]);
    await edit(fixture, 'input[name="count"]', '55');
    fixture.componentRef.setInput('groups', freeze([group([element('integer','count',{ default: 11 })])]));
    await ready(fixture);
    reset(fixture); await ready(fixture);
    expect((fixture.nativeElement.querySelector('input[name="count"]') as HTMLInputElement).value).toBe('11');
    expect(fixture.componentInstance.sampleValues()).toEqual({});
  });

  it('keeps prototype-like external keys local and does not mutate the schema or object prototypes', async () => {
    const groups = freeze([group([element('string','note')], { depends:'__proto__',dependsValue:'allowed' })]);
    const before = JSON.stringify(groups), fixture = await setup(groups);
    expect(fixture.nativeElement.querySelector('input[name="note"]')).toBeNull();
    await edit(fixture, '[data-condition-key="__proto__"]', 'allowed');
    expect(fixture.nativeElement.querySelector('input[name="note"]')).not.toBeNull();
    expect(Object.getPrototypeOf(fixture.componentInstance.parentValues())).toBe(Object.prototype);
    expect(Object.hasOwn(fixture.componentInstance.parentValues(),'__proto__')).toBe(true);
    expect(JSON.stringify(groups)).toBe(before);
  });

  it('disables all preview interactions while the parent is disabled', async () => {
    const fixture = await setup([group([element('integer','count',{default: 1})]),
      group([element('string','note')],{id:'conditional',depends:'runs',dependsValue:'2'}),
    ]);
    fixture.componentRef.setInput('disabled',true); await ready(fixture);
    const root = fixture.nativeElement as HTMLElement;
    expect([...root.querySelectorAll('input')].every(input => input.matches(':disabled'))).toBe(true);
    expect([...root.querySelectorAll('button')].every(button => button.disabled)).toBe(true);
    fixture.componentInstance.updateCondition('runs','2'); fixture.componentInstance.reset();
    expect(fixture.componentInstance.parentValues()).toEqual({});
    expect(fixture.componentInstance.sampleValues()).toEqual({});
  });
});
