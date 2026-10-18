import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { beforeEach, describe, expect, it } from 'vitest';
import { ActivityList } from './activity';

describe('Reference workspace activity timeline', () => {
  beforeEach(() => TestBed.configureTestingModule({ imports: [ActivityList], providers: [provideRouter([])] }));
  function setup(timeline: boolean) {
    const fixture = TestBed.createComponent(ActivityList);
    if (timeline) fixture.componentRef.setInput('presentation', 'timeline');
    fixture.componentRef.setInput('events', [{ id: 1, title: 'Project <script> created', text: 'Literal <b>description</b>', at: '2026-01-15T12:00:00+01:00', actorName: 'Owner Fixture', resourceType: 'project', resourceId: 7, resources: [{ type: 'experiment', id: 9, label: 'Experiment 9' }] }]);
    fixture.detectChanges(); return fixture;
  }
  it('keeps real resource navigation, actor and German timestamps in the compact timeline', () => {
    const element = setup(true).nativeElement as HTMLElement;
    expect(element.querySelector('.reference-timeline')).not.toBeNull();
    expect(element.querySelector('h3 a')?.getAttribute('href')).toBe('/projects/7');
    expect(element.querySelector('.activity-resources a')?.getAttribute('href')).toBe('/experiments/9');
    expect(element.textContent).toContain('Owner Fixture');
    expect(element.querySelectorAll('time')).toHaveLength(1);
    expect(element.querySelector('time')?.getAttribute('title')).toBe('15.01.2026 12:00:00');
    expect(element.querySelector('time')?.textContent).toContain('15.01.2026 12:00');
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
});
