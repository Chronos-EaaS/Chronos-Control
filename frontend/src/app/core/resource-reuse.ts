import { Injectable } from '@angular/core';
import { ActivatedRouteSnapshot, BaseRouteReuseStrategy } from '@angular/router';

const equalParams = (left: Record<string, unknown>, right: Record<string, unknown>): boolean => {
  const keys = Object.keys(left);
  return (
    keys.length === Object.keys(right).length &&
    keys.every((key) => String(left[key]) === String(right[key]))
  );
};

/** Only explicitly declared view selectors may change without replacing a resource editor. */
export function preservesResourceViewContext(
  future: ActivatedRouteSnapshot,
  current: ActivatedRouteSnapshot,
): boolean {
  if (future.routeConfig !== current.routeConfig || !equalParams(future.params, current.params))
    return false;
  const configured: unknown = future.routeConfig?.data?.['queryContextKeys'];
  if (!Array.isArray(configured) || !configured.length) return false;
  const keys = new Set(configured.filter((key): key is string => typeof key === 'string'));
  const resourceQuery = (query: Record<string, unknown>) =>
    Object.fromEntries(Object.entries(query).filter(([key]) => !keys.has(key)));
  return equalParams(resourceQuery(future.queryParams), resourceQuery(current.queryParams));
}

/** A resource editor must not retain a draft when its URL selects another ID. */
@Injectable()
export class ResourceRouteReuseStrategy extends BaseRouteReuseStrategy {
  override shouldReuseRoute(
    future: ActivatedRouteSnapshot,
    current: ActivatedRouteSnapshot,
  ): boolean {
    if (
      (!future.routeConfig && !current.routeConfig) ||
      (future.routeConfig?.path === '' && current.routeConfig?.path === '')
    )
      return super.shouldReuseRoute(future, current);
    return (
      super.shouldReuseRoute(future, current) &&
      equalParams(future.params, current.params) &&
      (equalParams(future.queryParams, current.queryParams) ||
        preservesResourceViewContext(future, current))
    );
  }
}
