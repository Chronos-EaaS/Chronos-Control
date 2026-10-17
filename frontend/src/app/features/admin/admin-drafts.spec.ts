import { TestBed } from '@angular/core/testing';
import { beforeEach, describe, expect, it } from 'vitest';
import { UnsavedChanges } from '../../core/unsaved';
import { formatBytes, formatPercent } from '../../core/admin-models';
import { AdminDrafts } from './admin-drafts';

describe('ADMIN-02/05/08 independent unsaved administration forms', () => {
  beforeEach(() => TestBed.configureTestingModule({ providers: [AdminDrafts, UnsavedChanges] }));
  it('keeps the navigation warning when one form saves while another is dirty', () => {
    const drafts = TestBed.inject(AdminDrafts), changes = TestBed.inject(UnsavedChanges);
    drafts.set('settings', true); drafts.set('maintenance', true); drafts.set('settings', false);
    expect(changes.dirty()).toBe(true);
    drafts.set('maintenance', false); expect(changes.dirty()).toBe(false);
  });
  it('does not let closing a clean dialog discard changes in another form', () => {
    const drafts = TestBed.inject(AdminDrafts), changes = TestBed.inject(UnsavedChanges);
    drafts.set('settings', true); drafts.set('create-user', false);
    expect(changes.dirty()).toBe(true);
  });
  it('clears page state on destruction and ignores late child cleanup', () => {
    const drafts = TestBed.inject(AdminDrafts), changes = TestBed.inject(UnsavedChanges);
    drafts.set('settings', true); TestBed.resetTestingModule();
    expect(changes.dirty()).toBe(false);
    drafts.set('maintenance', true); expect(changes.dirty()).toBe(false);
  });
});

describe('CEM-04 / ADMIN-07 metric display boundaries', () => {
  it('keeps measured zero distinct from unknown measurements', () => {
    expect(formatBytes(0)).toBe('0 B'); expect(formatPercent(0)).toBe('0 %');
    expect(formatBytes(null)).toBe('—'); expect(formatPercent(null)).toBe('Unknown');
    expect(formatBytes(-1)).toBe('—'); expect(formatBytes(Number.NaN)).toBe('—');
  });
  it('uses German number formatting and binary units', () => {
    expect(formatBytes(1536)).toBe('1,5 KiB'); expect(formatPercent(35.5)).toBe('35,5 %');
  });
});
