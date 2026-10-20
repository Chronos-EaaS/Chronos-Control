import { TestBed } from '@angular/core/testing';
import { describe, expect, it, vi } from 'vitest';
import { ResultChartView } from './result-chart';
import { ResultChart } from '../../core/results-models';

describe('pushed chart renderer failures', () => {
  it('reports an update failure without an unhandled rejection and releases the damaged chart', async () => {
    const fixture = TestBed.createComponent(ResultChartView);
    const component = fixture.componentInstance;
    const canvas = document.createElement('canvas');
    const destroy = vi.fn();
    const update = vi.fn(() => { throw new Error('Invalid updated scale'); });
    const chart = { id: 'bar', definition: { type: 'bar-plot', name: 'Test', parameter: 'value' }, data: { labels: ['One'], datasets: [{ label: 'Value', data: [1] }] } } as unknown as ResultChart;
    const renderer = component as unknown as {
      instance: unknown; render(canvas: HTMLCanvasElement, chart: ResultChart, message: string): Promise<void>;
    };
    renderer.instance = { canvas, config: { type: 'bar' }, update, destroy };
    await expect(renderer.render(canvas, chart, '')).resolves.toBeUndefined();
    expect(component.renderError()).toBe('The chart could not be drawn in this browser.');
    expect(update).toHaveBeenCalledExactlyOnceWith('none');
    expect(component.ready()).toBe(false); expect(destroy).toHaveBeenCalledOnce(); expect(renderer.instance).toBeNull();
    fixture.destroy();
  });
});
