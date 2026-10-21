import { ComponentFixture, TestBed } from '@angular/core/testing';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { ParameterIssue } from '../../core/parameter-models';
import { ParameterOutlineEntry, ParameterSchemaOutline } from './parameter-schema-outline';

function entries(): ParameterOutlineEntry[] {
  const group = {
    id: 'group-seven', title: 'Group Zürich', depends: '', dependsValue: '',
    elements: [
      { id: 'hidden', name: 'Hidden parameter', type: 'string', parameter: 'hidden' },
      { id: 'visible', name: '<b>Literal flag</b>', type: 'custom-boolean', parameter: 'flag' },
    ],
  };
  return [{ group, index: 7, elements: [{ element: group.elements[1], index: 1 }] }];
}

async function ready(fixture: ComponentFixture<ParameterSchemaOutline>): Promise<void> {
  fixture.detectChanges(); await fixture.whenStable(); fixture.detectChanges();
}

describe('ParameterSchemaOutline', () => {
  beforeEach(async () => {
    await TestBed.configureTestingModule({ imports: [ParameterSchemaOutline] }).compileComponents();
  });
  afterEach(() => TestBed.resetTestingModule());

  it('preserves original indexes, accessible selection, raw types and safe labels in a filtered outline', async () => {
    const fixture = TestBed.createComponent(ParameterSchemaOutline), original = entries();
    const before = JSON.stringify(original), groups: number[] = [], parameters: { group: number; element: number }[] = [];
    fixture.componentRef.setInput('entries', original);
    fixture.componentRef.setInput('selectedGroup', 7); fixture.componentRef.setInput('selectedElement', 1);
    fixture.componentRef.setInput('kinds', [{ type: 'custom-boolean', label: 'Custom flag (Boolean)' }]);
    fixture.componentInstance.groupSelect.subscribe(value => groups.push(value));
    fixture.componentInstance.parameterSelect.subscribe(value => parameters.push(value));
    await ready(fixture);
    const host = fixture.nativeElement as HTMLElement;
    expect(host.querySelector('nav')?.getAttribute('aria-label')).toBe('Parameter structure');
    const group = host.querySelector('.outline-group-button') as HTMLButtonElement;
    const parameter = host.querySelector('.outline-parameter') as HTMLButtonElement;
    expect(group.getAttribute('aria-label')).toBe('Edit group Group Zürich');
    expect(group.getAttribute('aria-current')).toBeNull();
    expect(group.querySelector('.outline-count')?.textContent?.trim()).toBe('2');
    expect(parameter.getAttribute('aria-label')).toBe('Edit parameter <b>Literal flag</b>');
    expect(parameter.getAttribute('aria-current')).toBe('true');
    expect(parameter.getAttribute('data-parameter-type')).toBe('custom-boolean');
    expect(parameter.querySelector('.type-badge')?.textContent?.trim()).toBe('Custom flag (Boolean)');
    expect(parameter.textContent).toContain('<b>Literal flag</b>'); expect(host.querySelector('b')).toBeNull();
    group.click(); parameter.click();
    expect(groups).toEqual([7]); expect(parameters).toEqual([{ group: 7, element: 1 }]);
    expect(JSON.stringify(original)).toBe(before);
    fixture.componentRef.setInput('kinds', []); await ready(fixture);
    expect(parameter.querySelector('.type-badge')?.textContent?.trim()).toBe('custom-boolean');
  });

  it('emits the search draft without mutating entries and gives simultaneous search controls distinct linked IDs', async () => {
    const first = TestBed.createComponent(ParameterSchemaOutline), second = TestBed.createComponent(ParameterSchemaOutline);
    const original = entries(), before = JSON.stringify(original), queries: string[] = [];
    first.componentRef.setInput('entries', original); second.componentRef.setInput('entries', original);
    first.componentInstance.query.subscribe((value: string) => queries.push(value));
    await ready(first); await ready(second);
    const input = first.nativeElement.querySelector('input[type="search"]') as HTMLInputElement;
    const other = second.nativeElement.querySelector('input[type="search"]') as HTMLInputElement;
    expect(input.id).not.toBe(other.id);
    expect(first.nativeElement.querySelector('label').htmlFor).toBe(input.id);
    expect(second.nativeElement.querySelector('label').htmlFor).toBe(other.id);
    input.value = 'Zürich'; input.dispatchEvent(new Event('input', { bubbles: true })); await ready(first);
    expect(queries).toEqual(['Zürich']); expect(first.componentInstance.query()).toBe('Zürich');
    expect(second.componentInstance.query()).toBe(''); expect(JSON.stringify(original)).toBe(before);
    first.componentRef.setInput('entries', []); await ready(first);
    expect(first.nativeElement.textContent).toContain('No matching parameters or groups.');
    first.componentRef.setInput('query', ''); await ready(first);
    expect(first.nativeElement.textContent).toContain('Add a group to get started.');
  });

  it('counts group and original element issues independently of the filtered list and marks a selected group', async () => {
    const fixture = TestBed.createComponent(ParameterSchemaOutline);
    const issues: ParameterIssue[] = [
      { code: 'group', message: 'Group issue', severity: 'error', groupId: 'group-seven', field: 'title' },
      { code: 'visible', message: 'Visible issue', severity: 'error', groupId: 'group-seven', elementId: 'visible' },
      { code: 'hidden', message: 'Hidden issue', severity: 'error', groupId: 'group-seven', elementId: 'hidden' },
      { code: 'other', message: 'Another group', severity: 'error', groupId: 'other', elementId: 'visible' },
    ];
    fixture.componentRef.setInput('entries', entries()); fixture.componentRef.setInput('issues', issues);
    fixture.componentRef.setInput('selectedGroup', 7); fixture.componentRef.setInput('selectedElement', null);
    await ready(fixture);
    expect(fixture.componentInstance.issueCount(7)).toBe(3);
    expect(fixture.componentInstance.issueCount(7, 1)).toBe(1);
    expect(fixture.componentInstance.issueCount(7, 0)).toBe(1);
    expect(fixture.componentInstance.issueCount(7, 99)).toBe(0);
    expect(fixture.componentInstance.issueCount(0)).toBe(0);
    const host = fixture.nativeElement as HTMLElement;
    const group = host.querySelector('.outline-group-button') as HTMLButtonElement;
    const parameter = host.querySelector('.outline-parameter') as HTMLButtonElement;
    expect(group.getAttribute('aria-current')).toBe('true');
    expect(parameter.getAttribute('aria-current')).toBeNull();
    expect(group.getAttribute('aria-label')).toBe('Edit group Group Zürich');
    expect(parameter.getAttribute('aria-label')).toBe('Edit parameter <b>Literal flag</b>');
    const groupDescription = group.getAttribute('aria-describedby');
    const parameterDescription = parameter.getAttribute('aria-describedby');
    expect(groupDescription).toBeTruthy(); expect(parameterDescription).toBeTruthy();
    expect(groupDescription).not.toBe(parameterDescription);
    expect(host.querySelector(`[id="${groupDescription}"]`)?.textContent?.trim()).toBe('3 validation issues in this group');
    expect(host.querySelector(`[id="${parameterDescription}"]`)?.textContent?.trim()).toBe('1 validation issue for this parameter');
    expect(group.querySelector('.issue-dot')?.getAttribute('aria-hidden')).toBe('true');
    expect(parameter.querySelector('.issue-dot')?.getAttribute('aria-hidden')).toBe('true');

    fixture.componentRef.setInput('issues', []); await ready(fixture);
    expect(group.hasAttribute('aria-describedby')).toBe(false);
    expect(parameter.hasAttribute('aria-describedby')).toBe(false);
    expect(host.querySelector(`[id="${groupDescription}"]`)).toBeNull();
    expect(host.querySelector(`[id="${parameterDescription}"]`)).toBeNull();
    expect(host.querySelector('.issue-dot')).toBeNull();
  });
});
