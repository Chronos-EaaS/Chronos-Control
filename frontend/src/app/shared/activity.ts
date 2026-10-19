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
  templateUrl: './activity.html',
  styleUrl: './activity.scss',
})
export class ActivityList {
  readonly events = input<Activity[]>([]);
  readonly heading = input('Recent activity');
  readonly presentation = input<'card' | 'timeline'>('card');
  readonly formatTime = dateTime;
  readonly relativeTime = relativeTime;
  readonly link = activityRoute;
  resourceRoute(type: string, id: number | string): string[] | null { return activityRoute({ id: 0, title: '', text: '', at: '', resourceType: type, resourceId: id }); }
  resources(event: Activity) {
    return (event.resources ?? []).flatMap(resource => {
      const route = this.resourceRoute(resource.type, resource.id);
      return route ? [{ ...resource, route }] : [];
    });
  }
  icon(type: string | undefined): string { return ({ project: 'archive', experiment: 'experiment', evaluation: 'evaluation', job: 'tasks', user: 'user', node: 'systems', system: 'systems' } as Record<string, string>)[type ?? ''] ?? 'clock'; }
}
