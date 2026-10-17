import { Component, input } from '@angular/core';
import { RouterLink } from '@angular/router';
import { Activity } from '../core/models';
import { dateTime, relativeTime } from '../core/time';
import { Icon } from './icon';

export function activityRoute(event: Activity): string[] | null {
  const routes: Record<string, string> = { project: 'projects', experiment: 'experiments', evaluation: 'evaluations', job: 'jobs', system: 'systems', node: 'cem/nodes' };
  return event.resourceId && routes[event.resourceType ?? ''] ? ['/', ...routes[event.resourceType!].split('/'), String(event.resourceId)] : null;
}

@Component({
  selector: 'app-activity',
  imports: [RouterLink, Icon],
  template: `<section class="card activity-card"><div class="card-header"><h2 class="card-title">{{ heading() }}</h2></div><div class="card-body">@for (event of events(); track event.id) { <article class="activity-entry"><span class="activity-symbol" aria-hidden="true"><app-icon [name]="icon(event.resourceType)" /></span><div><h3>@if (link(event); as route) { <a [routerLink]="route">{{ event.title }}</a> } @else { {{ event.title }} }</h3><p>{{ event.text }}</p><div class="d-flex gap-2 flex-wrap mb-2">@for (resource of event.resources || []; track resource.type + resource.id) { @if (resourceRoute(resource.type, resource.id); as route) { <a class="badge text-bg-light border text-decoration-none" [routerLink]="route">{{ resource.label }}</a> } }</div><span class="activity-meta">@if (event.actorName) { {{ event.actorName }} · }<time [attr.datetime]="event.at" [attr.title]="formatTime(event.at)">{{ formatTime(event.at) }} · {{ relativeTime(event.at) }}</time></span></div></article> } @empty { <p class="text-secondary py-3 mb-0">No activity yet.</p> }</div></section>`,
})
export class ActivityList {
  readonly events = input<Activity[]>([]);
  readonly heading = input('Recent activity');
  readonly formatTime = dateTime;
  readonly relativeTime = relativeTime;
  readonly link = activityRoute;
  resourceRoute(type: string, id: number | string): string[] | null { return activityRoute({ id: 0, title: '', text: '', at: '', resourceType: type, resourceId: id }); }
  icon(type: string | undefined): string { return ({ project: 'projects', experiment: 'systems', evaluation: 'clock', job: 'clock', user: 'user', node: 'systems', system: 'systems' } as Record<string, string>)[type ?? ''] ?? 'clock'; }
}
