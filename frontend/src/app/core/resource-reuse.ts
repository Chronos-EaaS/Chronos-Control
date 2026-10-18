import { Injectable } from '@angular/core';
import { ActivatedRouteSnapshot, BaseRouteReuseStrategy } from '@angular/router';

/** A resource editor must not retain a draft when its URL selects another ID. */
@Injectable()
export class ResourceRouteReuseStrategy extends BaseRouteReuseStrategy {
  override shouldReuseRoute(future: ActivatedRouteSnapshot, current: ActivatedRouteSnapshot): boolean {
    if ((!future.routeConfig && !current.routeConfig) || (future.routeConfig?.path === '' && current.routeConfig?.path === '')) return super.shouldReuseRoute(future, current);
    const equal = (left: Record<string, unknown>, right: Record<string, unknown>) => {
      const keys = Object.keys(left); return keys.length === Object.keys(right).length && keys.every(key => String(left[key]) === String(right[key]));
    };
    return super.shouldReuseRoute(future, current) && equal(future.params, current.params) && equal(future.queryParams, current.queryParams);
  }
}
