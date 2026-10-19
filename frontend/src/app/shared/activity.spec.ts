import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ActivityList } from './activity';

describe('Reference workspace activity timeline', () => {
  afterEach(() => vi.restoreAllMocks());
  beforeEach(() => TestBed.configureTestingModule({ imports: [ActivityList], providers: [provideRouter([])] }));
  function setup(timeline: boolean) {
    const fixture = TestBed.createComponent(ActivityList);
    if (timeline) fixture.componentRef.setInput('presentation', 'timeline');
    fixture.componentRef.setInput('events', [{ id: 1, title: 'Project <script> created', text: 'Literal <b>description</b>', at: '2026-01-15T12:00:00+01:00', actorName: 'Owner Fixture', resourceType: 'project', resourceId: 7, resources: [{ type: 'experiment', id: 9, label: 'Experiment 9' }] }]);
    fixture.detectChanges(); return fixture;
  }
  it('keeps real resource navigation, actor and German timestamps in the compact timeline', () => {
    vi.spyOn(Date, 'now').mockReturnValue(Date.parse('2026-01-17T12:00:00+01:00'));
    const element = setup(true).nativeElement as HTMLElement;
    expect(element.querySelector('.reference-timeline')).not.toBeNull();
    expect(element.querySelector('h3 a')?.getAttribute('href')).toBe('/projects/7');
    expect(element.querySelector('.activity-resources a')?.getAttribute('href')).toBe('/experiments/9');
    expect(element.textContent).toContain('Owner Fixture');
    expect(element.querySelectorAll('time')).toHaveLength(1);
    expect(element.querySelector('time')?.getAttribute('title')).toBe('15.01.2026 12:00:00');
    expect(element.querySelector('time')?.getAttribute('datetime')).toBe('2026-01-15T12:00:00+01:00');
    expect(element.querySelector('time')?.getAttribute('aria-label')).toContain('15.01.2026 12:00');
    expect(element.querySelector('.activity-header time')?.textContent).toContain('ago');
  });
  it('renders event content as text and exposes the semantic event type for its icon color', () => {
    const element = setup(true).nativeElement as HTMLElement;
    expect(element.querySelector('script,b')).toBeNull();
    expect(element.textContent).toContain('Project <script> created');
    expect(element.textContent).toContain('Literal <b>description</b>');
    expect(element.querySelector('.activity-entry')?.getAttribute('data-resource-type')).toBe('project');
  });
  it('preserves the default card presentation for pages outside the visual-review slice', () => {
    const element = setup(false).nativeElement as HTMLElement;
    expect(element.querySelector('.reference-timeline')).toBeNull();
    expect(element.querySelector('.activity-card')).not.toBeNull();
    expect(element.querySelectorAll('time')).toHaveLength(1);
  });
  it('keeps actor and complete resource hierarchy together without inventing links for inaccessible resources', () => {
    const fixture = setup(true);
    fixture.componentRef.setInput('events', [{ id: 2, title: 'Job completed', text: 'Finished normally.', at: '2026-03-29T01:30:00Z', actorName: 'Owner Fixture', resourceType: 'job', resourceId: 18, resources: [
      { type: 'project', id: 7, label: 'Project fixture' },
      { type: 'experiment', id: 9, label: 'Experiment fixture' },
      { type: 'evaluation', id: 11, label: 'Evaluation fixture' },
      { type: 'job', id: 18, label: 'Job #3' },
      { type: 'private-resource', id: 99, label: 'Hidden resource' },
    ] }]);
    fixture.detectChanges();
    const element = fixture.nativeElement as HTMLElement;
    const footer = element.querySelector('footer')!;
    expect(footer.firstElementChild?.textContent).toBe('Owner Fixture');
    expect([...footer.querySelectorAll('a')].map(link => link.getAttribute('href'))).toEqual(['/projects/7', '/experiments/9', '/evaluations/11', '/jobs/18']);
    expect(element.textContent).not.toContain('Hidden resource');
    expect(element.querySelector('time')?.getAttribute('title')).toBe('29.03.2026 03:30:00');
  });
  it('does not add an empty footer or body when an event only has a title', () => {
    const fixture = setup(true);
    fixture.componentRef.setInput('events', [{ id: 3, title: 'Maintenance event', text: '', at: '2026-01-15T12:00:00+01:00' }]);
    fixture.detectChanges();
    const element = fixture.nativeElement as HTMLElement;
    expect(element.querySelector('.activity-content p, .activity-content footer')).toBeNull();
    expect(element.textContent).toContain('Maintenance event');
    expect(element.querySelector('h3 a')).toBeNull();
    expect(element.querySelectorAll('time')).toHaveLength(1);
  });
});
