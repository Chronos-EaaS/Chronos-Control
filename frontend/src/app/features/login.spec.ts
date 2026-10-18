import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { ActivatedRoute, Router, convertToParamMap } from '@angular/router';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { Session } from '../core/session';
import { Login } from './login';

describe('Login preferences when version metadata is unavailable', () => {
  afterEach(() => { TestBed.inject(HttpTestingController).verify(); TestBed.resetTestingModule(); });

  it.each([true, false])('can sign in with remember=%s after a metadata failure', async remember => {
    const session = { login: vi.fn().mockResolvedValue(undefined) };
    const router = { navigateByUrl: vi.fn().mockResolvedValue(true) };
    TestBed.configureTestingModule({
      imports: [Login],
      providers: [provideHttpClient(), provideHttpClientTesting(),
        { provide: Session, useValue: session }, { provide: Router, useValue: router },
        { provide: ActivatedRoute, useValue: { snapshot: { queryParamMap: convertToParamMap({ returnUrl: '/projects' }) } } },
      ],
    });
    const fixture = TestBed.createComponent(Login); fixture.detectChanges();
    TestBed.inject(HttpTestingController).expectOne('/api/ui/meta').flush(
      { error: { message: 'Unavailable' } }, { status: 503, statusText: 'Service Unavailable' },
    );
    await fixture.whenStable(); fixture.detectChanges();
    const checkbox = fixture.nativeElement.querySelector('#remember') as HTMLInputElement;
    expect(checkbox.checked).toBe(true);
    if (!remember) { checkbox.click(); await fixture.whenStable(); }
    fixture.componentInstance.username = ' owner ';
    fixture.componentInstance.password = 'Owner-password-2026!';
    await fixture.componentInstance.signIn();
    expect(session.login).toHaveBeenCalledExactlyOnceWith('owner', 'Owner-password-2026!', remember);
    expect(router.navigateByUrl).toHaveBeenCalledExactlyOnceWith('/projects');
    expect(fixture.componentInstance.error()).toBe('');
    expect(fixture.componentInstance.busy()).toBe(false);
    fixture.destroy();
  });
});
