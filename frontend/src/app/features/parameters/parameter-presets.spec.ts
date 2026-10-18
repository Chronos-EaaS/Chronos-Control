import { Component, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { Api } from '../../core/api';
import { ParameterValues } from '../../core/parameter-models';
import { ParameterPresets } from './parameter-presets';

@Component({ imports: [ParameterPresets], template: '<app-parameter-presets [projectId]="1" [values]="values()" [valid]="true" (valuesChange)="values.set($event)" />' })
class Host { readonly values = signal<ParameterValues>({ quantity: 0, enabled: false, labels: ['Zürich'] }); }
const preset = { id: 'fixture-preset', name: 'Saved values', values: { quantity: 0, enabled: false, labels: ['original'] }, compatible: true, schemaHash: 'schema-current', revision: 'revision-1', updatedAt: '2026-01-15T12:00:00+01:00' };

describe('EXTENSION-01 named parameter presets through their actual host', () => {
  let api: { get: ReturnType<typeof vi.fn>; post: ReturnType<typeof vi.fn>; put: ReturnType<typeof vi.fn> };
  beforeEach(() => {
    api = { get: vi.fn(async () => ({ items: [structuredClone(preset)], schemaHash: 'schema-current' })), post: vi.fn(async (_path, body) => ({ ...preset, ...body, id: 'new-preset' })), put: vi.fn(async (_path, body) => ({ ...preset, ...body, revision: 'revision-2' })) };
    TestBed.configureTestingModule({ imports: [Host], providers: [{ provide: Api, useValue: api }] });
  });
  afterEach(() => vi.restoreAllMocks());
  async function setup() { const fixture = TestBed.createComponent(Host); await fixture.whenStable(); fixture.detectChanges(); return fixture; }
  async function select(fixture: Awaited<ReturnType<typeof setup>>) { const select = fixture.nativeElement.querySelector('#parameter-preset') as HTMLSelectElement; select.value = preset.id; select.dispatchEvent(new Event('change', { bubbles: true })); await fixture.whenStable(); fixture.detectChanges(); }
  function button(element: HTMLElement, text: string) { return [...element.querySelectorAll('button')].find(button => button.textContent?.trim() === text)!; }
  it('saves zero and false intact and includes the selected revision only when overwriting', async () => {
    const fixture = await setup(); await select(fixture); vi.spyOn(window, 'confirm').mockReturnValue(true);
    button(fixture.nativeElement, 'Update selected preset').click(); await fixture.whenStable(); fixture.detectChanges();
    expect(api.put).toHaveBeenCalledWith('/projects/1/parameter-presets/fixture-preset', { name: 'Saved values', values: { quantity: 0, enabled: false, labels: ['Zürich'] }, schemaHash: 'schema-current', revision: 'revision-1' });
    button(fixture.nativeElement, 'Save as new preset').click(); await fixture.whenStable();
    expect(api.post).toHaveBeenCalledWith('/projects/1/parameter-presets', { name: 'Saved values', values: { quantity: 0, enabled: false, labels: ['Zürich'] }, schemaHash: 'schema-current' });
  });
  it('loads a clone after explicit confirmation so later editing cannot mutate the shared preset', async () => {
    const fixture = await setup(); await select(fixture); const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false);
    button(fixture.nativeElement, 'Load preset').click(); expect(fixture.componentInstance.values()['labels']).toEqual(['Zürich']);
    confirm.mockReturnValue(true); button(fixture.nativeElement, 'Load preset').click(); fixture.detectChanges();
    const loaded = fixture.componentInstance.values(); expect(loaded['quantity']).toBe(0); expect(loaded['enabled']).toBe(false); (loaded['labels'] as string[]).push('edited');
    button(fixture.nativeElement, 'Load preset').click(); expect(fixture.componentInstance.values()['labels']).toEqual(['original']);
  });
  it('keeps entered values, selected revision and name available after a stale-write rejection', async () => {
    const fixture = await setup(); await select(fixture); vi.spyOn(window, 'confirm').mockReturnValue(true); api.put.mockRejectedValueOnce(new Error('This preset was changed by another project member. Reload it before saving.'));
    const name = fixture.nativeElement.querySelector('#preset-name') as HTMLInputElement; name.value = 'My unsaved name'; name.dispatchEvent(new Event('input', { bubbles: true })); await fixture.whenStable();
    button(fixture.nativeElement, 'Update selected preset').click(); await fixture.whenStable(); fixture.detectChanges();
    expect(name.value).toBe('My unsaved name'); expect(fixture.componentInstance.values()).toEqual({ quantity: 0, enabled: false, labels: ['Zürich'] });
    expect((fixture.nativeElement as HTMLElement).querySelector('[role="alert"]')?.textContent).toContain('another project member');
    button(fixture.nativeElement, 'Update selected preset').click(); await fixture.whenStable(); expect(api.put.mock.calls[1][1].revision).toBe('revision-1');
  });
});
