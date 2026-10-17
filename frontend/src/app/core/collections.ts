import { Api } from './api';
import { PageData } from './models';

/** Load all authorized choices, including installations with more than 100 items. */
export async function allPages<T>(api: Api, path: string, query: Record<string, string | number | boolean> = {}): Promise<T[]> {
  const items: T[] = [];
  let page = 1;
  for (;;) {
    const result = await api.get<PageData<T>>(path, { ...query, page, pageSize: 100 });
    items.push(...result.items);
    if (items.length >= result.total || !result.items.length) return items;
    page++;
  }
}
