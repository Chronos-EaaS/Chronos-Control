import { CanActivateFn, CanActivateChildFn, CanDeactivateFn, Routes, Router } from '@angular/router';
import { inject } from '@angular/core';
import { Session, signedIn } from './core/session';
import { UnsavedChanges } from './core/unsaved';
import { preservesResourceViewContext } from './core/resource-reuse';
import { Shell } from './shared/shell';
import { Login } from './features/login';

const mayLeave: CanDeactivateFn<unknown> = () => inject(UnsavedChanges).mayDiscard();
export const mayEnterResource: CanActivateChildFn = future => {
  const changes = inject(UnsavedChanges);
  let current = inject(Router).routerState.snapshot.root;
  while (current.firstChild) current = current.firstChild;
  return preservesResourceViewContext(future, current) || changes.mayDiscard();
};
const admin: CanActivateFn = () => inject(Session).isAdmin() || inject(Router).createUrlTree(['/forbidden']);

export const routes: Routes = [
  { path: 'login', component: Login, title: 'Sign in · Chronos' },
  { path: '', component: Shell, canActivate: [signedIn], canActivateChild: [mayEnterResource], canDeactivate: [mayLeave], children: [
    { path: '', loadComponent: () => import('./features/dashboard').then(m => m.Dashboard), title: 'Overview · Chronos' },
    { path: 'projects', loadComponent: () => import('./features/resources').then(m => m.Resources), data: { kind: 'projects' }, runGuardsAndResolvers: 'paramsOrQueryParamsChange', title: 'Projects · Chronos' },
    { path: 'projects/:id/experiments/new', loadComponent: () => import('./features/workflow/experiment-create').then(m => m.ExperimentCreate), runGuardsAndResolvers: 'paramsOrQueryParamsChange', title: 'Create experiment · Chronos' },
    { path: 'projects/:id', loadComponent: () => import('./features/workflow/project-detail').then(m => m.ProjectDetail), runGuardsAndResolvers: 'paramsOrQueryParamsChange', title: 'Project · Chronos' },
    { path: 'experiments/:id', loadComponent: () => import('./features/workflow/experiment-detail').then(m => m.ExperimentDetail), data: { queryContextKeys: ['configuration', 'scope', 'tab'] }, runGuardsAndResolvers: 'paramsOrQueryParamsChange', title: 'Experiment · Chronos' },
    { path: 'evaluations/:id/results', loadComponent: () => import('./features/workflow/evaluation-results').then(m => m.EvaluationResults), runGuardsAndResolvers: 'paramsOrQueryParamsChange', title: 'Evaluation results · Chronos' },
    { path: 'evaluations/:id', loadComponent: () => import('./features/workflow/evaluation-detail').then(m => m.EvaluationDetail), runGuardsAndResolvers: 'paramsOrQueryParamsChange', title: 'Evaluation · Chronos' },
    { path: 'jobs/:id/results', loadComponent: () => import('./features/workflow/job-results').then(m => m.JobResults), runGuardsAndResolvers: 'paramsOrQueryParamsChange', title: 'Job results · Chronos' },
    { path: 'jobs/:id', loadComponent: () => import('./features/workflow/job-detail').then(m => m.JobDetail), runGuardsAndResolvers: 'paramsOrQueryParamsChange', title: 'Job · Chronos' },
    { path: 'status', loadComponent: () => import('./features/workflow/status').then(m => m.Status), title: 'Status · Chronos' },
    { path: 'extensions/systems/:systemId/pages/:pageId', loadComponent: () => import('./features/extensions/extension-page').then(m => m.ExtensionPage), title: 'System extension · Chronos' },
    { path: 'systems/:id', loadComponent: () => import('./features/systems/system-detail').then(m => m.SystemDetail), runGuardsAndResolvers: 'paramsOrQueryParamsChange', title: 'System · Chronos' },
    { path: 'systems', loadComponent: () => import('./features/resources').then(m => m.Resources), data: { kind: 'systems' }, runGuardsAndResolvers: 'paramsOrQueryParamsChange', title: 'Systems · Chronos' },
    { path: 'profile', loadComponent: () => import('./features/profile').then(m => m.Profile), runGuardsAndResolvers: 'paramsOrQueryParamsChange', title: 'Profile · Chronos' },
    { path: 'admin', canActivate: [admin], loadComponent: () => import('./features/admin/admin-overview').then(m => m.AdminOverview), runGuardsAndResolvers: 'paramsOrQueryParamsChange', title: 'Administration · Chronos' },
    { path: 'admin/users/:id', canActivate: [admin], loadComponent: () => import('./features/admin/user-editor').then(m => m.UserEditor), runGuardsAndResolvers: 'paramsOrQueryParamsChange', title: 'User · Chronos' },
    { path: 'cem', canActivate: [admin], loadComponent: () => import('./features/cem/cem-overview').then(m => m.CemOverview), title: 'CEM · Chronos' },
    { path: 'cem/nodes/:id', canActivate: [admin], loadComponent: () => import('./features/cem/cem-node').then(m => m.CemNode), title: 'Node · Chronos' },
    { path: 'forbidden', loadComponent: () => import('./features/forbidden').then(m => m.Forbidden), title: 'Access denied · Chronos' },
  ] },
  { path: '**', loadComponent: () => import('./features/not-found').then(m => m.NotFound), title: 'Page not found · Chronos' },
];
