import { HttpClient, HttpErrorResponse, HttpHeaders, HttpParams } from '@angular/common/http';
import { Injectable, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
import { firstValueFrom, timeout, TimeoutError } from 'rxjs';

export class ApiError extends Error {
  constructor(message: string, readonly status: number, readonly code: string, readonly fields: Record<string, string> = {}) {
    super(message);
  }
}

@Injectable({ providedIn: 'root' })
export class Api {
  private readonly http = inject(HttpClient);
  private readonly router = inject(Router);
  readonly csrfToken = signal('');
  readonly sessionExpired = signal(0);

  async request<T>(method: string, path: string, body?: unknown, query?: Record<string, string | number | boolean>, timeoutMs = 20_000): Promise<T> {
    if (!path.startsWith('/') || path.includes('://')) throw new Error('An API resource path is required.');
    let headers = new HttpHeaders({ Accept: 'application/json' });
    if (!['GET', 'HEAD'].includes(method)) headers = headers.set('X-CSRF-Token', this.csrfToken());
    let params = new HttpParams();
    for (const [key, value] of Object.entries(query ?? {})) params = params.set(key, String(value));
    try {
      const response = await firstValueFrom(this.http.request<{ data: T }>(method, `/api/v3${path}`, {
        body, headers, params, withCredentials: true,
      }).pipe(timeout(timeoutMs)));
      return response.data;
    } catch (error) {
      if (error instanceof HttpErrorResponse) {
        if (error.status === 401 && path !== '/session') {
          this.csrfToken.set(''); this.sessionExpired.update(value => value + 1);
          void this.router.navigate(['/login'], { queryParams: { returnUrl: this.router.url } });
        }
        const detail = error.error?.error;
        throw new ApiError(detail?.message ?? (error.status === 0
          ? 'Chronos could not be reached. Check your connection and try again.'
          : 'The request could not be completed. Please try again.'), error.status, detail?.code ?? 'request_failed', detail?.fields ?? {});
      }
      if (error instanceof TimeoutError) throw new ApiError('Chronos did not respond in time. Please try again.', 0, 'timeout');
      throw error;
    }
  }
  get<T>(path: string, query?: Record<string, string | number | boolean>): Promise<T> { return this.request<T>('GET', path, undefined, query); }
  post<T>(path: string, body?: unknown, timeoutMs = 20_000): Promise<T> { return this.request<T>('POST', path, body, undefined, timeoutMs); }
  put<T>(path: string, body: unknown): Promise<T> { return this.request<T>('PUT', path, body); }
  patch<T>(path: string, body: unknown): Promise<T> { return this.request<T>('PATCH', path, body); }
  delete<T>(path: string): Promise<T> { return this.request<T>('DELETE', path); }
}

export function errorMessage(error: unknown): string {
  if (error instanceof ApiError && Object.keys(error.fields).length) return [...new Set([error.message, ...Object.values(error.fields)])].join(' ');
  return error instanceof Error ? error.message : 'The request could not be completed.';
}
