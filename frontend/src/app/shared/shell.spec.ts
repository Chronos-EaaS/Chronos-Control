import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { signal } from '@angular/core';
import { provideRouter } from '@angular/router';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { Session } from '../core/session';
import { Shell } from './shell';

describe('Shell application version', () => {
  beforeEach(() => {
    vi.stubGlobal('matchMedia', vi.fn(() => ({ matches: false, addEventListener: vi.fn(), removeEventListener: vi.fn() })));
    TestBed.configureTestingModule({
      imports: [Shell],
      providers: [provideHttpClient(), provideHttpClientTesting(), provideRouter([]), {
        provide: Session, useValue: {
          displayName: () => 'Owner Fixture', user: signal({ username: 'owner', roleName: 'User' }),
          isAdmin: () => false, impersonation: signal({ active: false, originalUser: null }),
        },
      }],
    });
  });
  afterEach(() => { TestBed.inject(HttpTestingController).verify(); TestBed.resetTestingModule(); vi.unstubAllGlobals(); });

  it('renders the actual deployed application version from metadata with its label and attribution', async () => {
    const fixture = TestBed.createComponent(Shell); fixture.detectChanges();
    TestBed.inject(HttpTestingController).expectOne('/api/v3/meta').flush({ data: { applicationVersion: '3.0.0-rc.2+fixture', uiBuildId: 'different-build-identity' } });
    const footer = fixture.nativeElement.querySelector('.app-footer') as HTMLElement;
    await vi.waitFor(() => { fixture.detectChanges(); expect(footer.textContent).toContain('Version 3.0.0-rc.2+fixture'); });
    expect(footer.textContent).toContain('Chronos Control · University of Basel');
    expect(footer.textContent).not.toContain('different-build-identity');
    fixture.destroy();
  });

  it('keeps navigation available when version metadata cannot be loaded', async () => {
    const fixture = TestBed.createComponent(Shell); fixture.detectChanges();
    TestBed.inject(HttpTestingController).expectOne('/api/v3/meta').flush({ error: { message: 'Unavailable' } }, { status: 503, statusText: 'Service Unavailable' });
    await fixture.whenStable(); fixture.detectChanges();
    expect(fixture.nativeElement.querySelector('.app-footer').textContent).toContain('Version unavailable');
    expect(fixture.nativeElement.querySelector('nav[aria-label="Main navigation"] a[href="/projects"]')?.textContent).toContain('Projects');
    fixture.destroy();
  });
});
