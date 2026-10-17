import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import { describe, expect, it, vi } from 'vitest';
import { Api } from '../../core/api';
import { ExtensionPage } from './extension-page';

describe('EXTENSION-01 safe data-only page rendering', () => {
  it('renders text, zero and false literally without executing markup or losing table semantics', async () => {
    const payload = '<img src=x onerror="window.extensionExecuted=true"><script>window.extensionExecuted=true</script>';
    const get = vi.fn(async () => ({ id: 'fixture', title: 'Extension fixture', description: payload, blocks: [
      { type: 'text', title: 'Raw text', text: payload },
      { type: 'metrics', title: 'Metrics', items: [{ label: 'Zero', value: 0 }, { label: 'Disabled', value: false }, { label: 'Missing', value: null }] },
      { type: 'table', title: 'Fixture rows', columns: [{ key: 'label', label: 'Label' }, { key: 'count', label: 'Count' }, { key: 'enabled', label: 'Enabled' }], rows: [{ label: payload, count: 0, enabled: false }] },
    ] }));
    TestBed.configureTestingModule({ providers: [provideRouter([{ path: 'systems/:systemId/pages/:pageId', component: ExtensionPage }]), { provide: Api, useValue: { get } }] });
    const harness = await RouterTestingHarness.create('/systems/1/pages/fixture'); await harness.fixture.whenStable(); harness.detectChanges(); const element = harness.routeNativeElement!;
    expect(get).toHaveBeenCalledWith('/systems/1/pages/fixture'); expect(element.querySelector('script,img')).toBeNull(); expect(element.textContent).toContain(payload);
    const table = element.querySelector('table')!; expect(table.querySelector('caption')?.textContent).toBe('Fixture rows');
    expect([...table.querySelectorAll('th')].every(header => header.getAttribute('scope') === 'col')).toBe(true);
    expect([...table.querySelectorAll('tbody td')].map(cell => cell.textContent?.trim())).toEqual([payload, '0', 'false']);
    expect([...element.querySelectorAll('dd')].map(value => value.textContent?.trim())).toEqual(['0', 'false', '—']);
    expect((window as unknown as { extensionExecuted?: boolean }).extensionExecuted).toBeUndefined();
  });
});
