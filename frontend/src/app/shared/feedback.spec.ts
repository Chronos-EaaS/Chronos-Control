import { TestBed } from '@angular/core/testing';
import { describe, expect, it } from 'vitest';
import { Feedback } from './feedback';

describe('Contextual loading feedback', () => {
  it('does not reserve a loading slot for static error and success feedback', () => {
    const fixture = TestBed.createComponent(Feedback);
    fixture.componentRef.setInput('error', 'Unable to save.');
    fixture.componentRef.setInput('success', 'Changes saved.');
    fixture.detectChanges();
    const page = fixture.nativeElement as HTMLElement;
    expect(page.querySelector('.loading-state')).toBeNull();
    expect(page.querySelector('[role="alert"]')?.textContent).toContain('Unable to save.');
    expect(page.querySelector('[role="status"]')?.textContent).toContain('Changes saved.');
  });

  it('retains the same contextual slot while exposing only active loading to assistive technology', () => {
    const fixture = TestBed.createComponent(Feedback);
    fixture.componentRef.setInput('busy', false);
    fixture.componentRef.setInput('label', 'Loading result data…');
    fixture.detectChanges();
    const page = fixture.nativeElement as HTMLElement;
    const slot = page.querySelector<HTMLElement>('.loading-state')!;
    expect(slot).not.toBeNull();
    expect(slot.textContent).toContain('Loading result data…');
    expect(slot.style.visibility).toBe('hidden');
    expect(slot.getAttribute('aria-hidden')).toBe('true');
    expect(slot.hasAttribute('role')).toBe(false);
    for (const busy of [true, false, true]) {
      fixture.componentRef.setInput('busy', busy); fixture.detectChanges();
      expect(page.querySelector('.loading-state')).toBe(slot);
      expect(slot.textContent).toContain('Loading result data…');
      expect(slot.style.visibility).toBe(busy ? 'visible' : 'hidden');
      expect(slot.getAttribute('role')).toBe(busy ? 'status' : null);
      expect(slot.getAttribute('aria-hidden')).toBe(busy ? null : 'true');
    }
  });
});
