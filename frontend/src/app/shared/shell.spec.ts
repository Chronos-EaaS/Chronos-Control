import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { signal } from '@angular/core';
import { provideRouter } from '@angular/router';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { Session } from '../core/session';
import { Shell } from './shell';

describe('Shell application version', () => {
  const administrator = signal(false);
  beforeEach(() => {
    administrator.set(false);
    vi.stubGlobal('matchMedia', vi.fn(() => ({ matches: false, addEventListener: vi.fn(), removeEventListener: vi.fn() })));
    TestBed.configureTestingModule({
      imports: [Shell],
      providers: [provideHttpClient(), provideHttpClientTesting(), provideRouter([]), {
        provide: Session, useValue: {
          displayName: () => 'Owner Fixture', user: signal({ username: 'owner', roleName: 'User' }),
          isAdmin: administrator, impersonation: signal({ active: false, originalUser: null }),
        },
      }],
    });
  });
  afterEach(() => { TestBed.inject(HttpTestingController).verify(); TestBed.resetTestingModule(); vi.unstubAllGlobals(); });

  it('renders the actual deployed application version from metadata with its label and attribution', async () => {
    const fixture = TestBed.createComponent(Shell); fixture.detectChanges();
    TestBed.inject(HttpTestingController).expectOne('/api/ui/meta').flush({ data: { applicationVersion: '3.0.0-rc.2+fixture', uiBuildId: 'different-build-identity' } });
    expect(fixture.nativeElement.querySelector('.app-wrapper')?.classList.contains('reference-workspace')).toBe(true);
    expect(fixture.nativeElement.querySelector('.brand-symbol')?.getAttribute('src')).toBe('/chronos-symbol.svg');
    expect(fixture.nativeElement.querySelector('.brand-link')?.getAttribute('aria-label')).toBe('Chronos Control home');
    expect(fixture.nativeElement.querySelector('.brand-text').textContent.trim()).toBe('');
    expect(fixture.nativeElement.querySelector('.navigation-section')).toBeNull();
    const footer = fixture.nativeElement.querySelector('.app-footer') as HTMLElement;
    await vi.waitFor(() => { fixture.detectChanges(); expect(footer.textContent).toContain('Version 3.0.0-rc.2+fixture'); });
    const project = footer.querySelector('a');
    expect(project?.textContent).toBe('The Chronos Project');
    expect(project?.getAttribute('href')).toBe('https://chronos-eaas.org');
    expect(footer.textContent).not.toContain('different-build-identity');
    fixture.destroy();
  });

  it('keeps navigation available when version metadata cannot be loaded', async () => {
    const fixture = TestBed.createComponent(Shell); fixture.detectChanges();
    TestBed.inject(HttpTestingController).expectOne('/api/ui/meta').flush({ error: { message: 'Unavailable' } }, { status: 503, statusText: 'Service Unavailable' });
    await fixture.whenStable(); fixture.detectChanges();
    expect(fixture.nativeElement.querySelector('.app-footer').textContent).toContain('Version unavailable');
    expect(fixture.nativeElement.querySelector('nav[aria-label="Main navigation"] a[href="/projects"]')?.textContent).toContain('Projects');
    fixture.destroy();
  });

  it('offers administration through a top-menu gear only for administrators and keeps CEM distinct from Systems', async () => {
    const fixture = TestBed.createComponent(Shell);
    fixture.detectChanges();
    TestBed.inject(HttpTestingController)
      .expectOne('/api/ui/meta')
      .flush({ data: { applicationVersion: '3.0-dev' } });
    await fixture.whenStable();
    fixture.detectChanges();
    const navigation = fixture.nativeElement.querySelector(
      'nav[aria-label="Main navigation"]',
    ) as HTMLElement;
    const header = fixture.nativeElement.querySelector('.app-header') as HTMLElement;
    expect(fixture.nativeElement.querySelector('a[href="/admin"]')).toBeNull();
    expect(navigation.querySelector('a[href="/cem"]')).toBeNull();

    administrator.set(true);
    fixture.detectChanges();
    const settings = header.querySelector('a[href="/admin"]');
    expect(settings?.getAttribute('aria-label')).toBe('Administration');
    expect(settings?.getAttribute('title')).toBe('Administration');
    expect(settings?.querySelector('app-icon')?.getAttribute('name')).toBe('settings');
    expect(navigation.querySelector('a[href="/admin"]')).toBeNull();
    expect(navigation.querySelector('a[href="/systems"] app-icon')?.getAttribute('name')).toBe(
      'systems',
    );
    expect(navigation.querySelector('a[href="/cem"] app-icon')?.getAttribute('name')).toBe('cem');

    administrator.set(false);
    fixture.detectChanges();
    expect(header.querySelector('a[href="/admin"]')).toBeNull();
    expect(navigation.querySelector('a[href="/cem"]')).toBeNull();
    fixture.destroy();
  });
});
