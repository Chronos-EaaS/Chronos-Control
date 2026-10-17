import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { provideRouter } from '@angular/router';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { Api, ApiError } from './api';

describe('same-origin v3 API boundary', () => {
  let api: Api; let http: HttpTestingController;
  beforeEach(() => {
    TestBed.configureTestingModule({ providers: [provideHttpClient(), provideHttpClientTesting(), provideRouter([])] });
    api = TestBed.inject(Api); http = TestBed.inject(HttpTestingController);
  });
  afterEach(() => { http.verify(); vi.useRealTimers(); });
  it('unwraps data and encodes search values as parameters', async () => {
    const result = api.get('/projects', { search: 'Zürich & XML', all: false });
    const request = http.expectOne(req => req.url === '/api/v3/projects');
    expect(request.request.params.get('search')).toBe('Zürich & XML');
    expect(request.request.params.get('all')).toBe('false');
    expect(request.request.headers.has('X-CSRF-Token')).toBe(false);
    request.flush({ data: { items: [] } });
    expect(await result).toEqual({ items: [] });
  });
  it('sends JSON mutations with the current CSRF token', async () => {
    api.csrfToken.set('session-token');
    const result = api.patch('/projects/2', { description: 'A&B + Zürich' });
    const request = http.expectOne('/api/v3/projects/2');
    expect(request.request.headers.get('X-CSRF-Token')).toBe('session-token');
    expect(request.request.body).toEqual({ description: 'A&B + Zürich' });
    expect(request.request.withCredentials).toBe(true);
    request.flush({ data: { id: 2 } });
    expect(await result).toEqual({ id: 2 });
  });
  it('preserves meaningful validation errors and field details', async () => {
    const result = api.post('/projects', { name: '' });
    const assertion = expect(result).rejects.toMatchObject({ status: 422, code: 'validation_failed', fields: { name: 'Required' } });
    http.expectOne('/api/v3/projects').flush({ error: { code: 'validation_failed', message: 'Check the project name.', fields: { name: 'Required' } } }, { status: 422, statusText: 'Unprocessable Content' });
    await assertion;
  });
  it('allows bounded repository synchronization to exceed an ordinary request deadline', async () => {
    vi.useFakeTimers();
    const result = api.put('/systems/2/parameters', { groups: [] });
    const request = http.expectOne('/api/v3/systems/2/parameters');
    await vi.advanceTimersByTimeAsync(20_001);
    expect(request.cancelled).toBe(false);
    request.flush({ data: { saved: true } });
    expect(await result).toEqual({ saved: true });
  });
  it('bounds ordinary requests and accepts a separate maintenance execution deadline', async () => {
    vi.useFakeTimers();
    const result = api.get('/projects');
    const assertion = expect(result).rejects.toMatchObject({ code: 'timeout' });
    const request = http.expectOne('/api/v3/projects');
    await vi.advanceTimersByTimeAsync(20_001); await assertion;
    expect(request.cancelled).toBe(true);
    const maintenance = api.post('/admin/maintenance/execute', {}, 310_000);
    const execution = http.expectOne('/api/v3/admin/maintenance/execute');
    await vi.advanceTimersByTimeAsync(180_001);
    expect(execution.cancelled).toBe(false);
    execution.flush({ data: { complete: true } });
    expect(await maintenance).toEqual({ complete: true });
  });
  it('rejects remote resource URLs before sending a request', async () => {
    await expect(api.get('https://example.test/data')).rejects.toThrow('An API resource path is required.');
  });
  it('reports offline failures as user-facing API errors', async () => {
    const result = api.get('/projects');
    const assertion = expect(result).rejects.toBeInstanceOf(ApiError);
    http.expectOne('/api/v3/projects').error(new ProgressEvent('network error'));
    await assertion;
  });
});
