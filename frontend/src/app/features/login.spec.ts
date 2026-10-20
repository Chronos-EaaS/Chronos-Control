import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { ActivatedRoute, Router, convertToParamMap } from '@angular/router';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { signal } from '@angular/core';
import { Session } from '../core/session';
import { ApplicationMetadata } from '../core/application-metadata';
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
        { provide: ApplicationMetadata, useValue: { version: signal(null) } },
        { provide: ActivatedRoute, useValue: { snapshot: { queryParamMap: convertToParamMap({ returnUrl: '/projects' }) } } },
      ],
    });
    const fixture = TestBed.createComponent(Login); fixture.detectChanges();
    TestBed.inject(HttpTestingController).expectNone('/api/ui/meta');
    await fixture.whenStable(); fixture.detectChanges();
    expect(fixture.nativeElement.querySelector('.login-brand').textContent.trim()).toBe('');
    expect(fixture.nativeElement.querySelector('.login-logo').getAttribute('alt')).toBe('Chronos');
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
