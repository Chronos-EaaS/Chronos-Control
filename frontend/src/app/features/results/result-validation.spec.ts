import { describe, expect, it } from 'vitest';
import { PlotDefinition, ResultConfigurationDocument } from '../../core/results-models';
import { resultConfigurationIssues, validateResultConfigurations } from './result-state';

const plot = (id: string, extra: Partial<PlotDefinition> = {}): PlotDefinition => ({
  id, type: 'bar-plot', name: 'Same chart title', parameter: 'latency', ...extra,
});
const document = (): ResultConfigurationDocument => ({
  version: '1.0', elements: { configuration: { name: 'Configuration', all: [], job: [] } },
});

describe('structured result configuration validation', () => {
  it('reports exact configuration, scope and chart indices even when chart titles and IDs are duplicated', () => {
    const value = document();
    value.elements['configuration'].all = [plot('duplicate'), plot('duplicate', { parameter: '' }), plot('third', { parameter: '' })];
    value.elements['configuration'].job = [plot('duplicate'), plot('duplicate')];
    const issues = resultConfigurationIssues(value);
    expect(issues).toEqual([
      { message: 'Each plot needs a unique stable ID within its scope.', configurationId: 'configuration', scope: 'all', index: 1, field: 'id' },
      { message: '“Same chart title” needs metric or configuration parameter names.', configurationId: 'configuration', scope: 'all', index: 1, field: 'parameter' },
      { message: '“Same chart title” needs metric or configuration parameter names.', configurationId: 'configuration', scope: 'all', index: 2, field: 'parameter' },
      { message: 'Each plot needs a unique stable ID within its scope.', configurationId: 'configuration', scope: 'job', index: 1, field: 'id' },
    ]);
    expect(validateResultConfigurations(value)).toEqual(issues.map(issue => issue.message));
  });

  it('allows the same stable chart ID in different scopes and configurations', () => {
    const value = document(); value.elements['configuration'].all = [plot('shared')]; value.elements['configuration'].job = [plot('shared')];
    value.elements['other'] = { name: 'Other', all: [plot('shared')], job: [plot('shared')] };
    expect(resultConfigurationIssues(value)).toEqual([]); expect(validateResultConfigurations(value)).toEqual([]);
  });

  it('retains the exact legacy string messages and their traversal order while identifying every invalid field', () => {
    const value = { version: 'unsupported', elements: { 'bad id': {
      name: ' ', all: [
        plot('bad id', { type: 'unknown', name: '', parameter: '' }),
        plot('box', { type: 'custom-box', name: 'Box', parameter: 'workers,', plotting: '', orientation: 'diagonal' as PlotDefinition['orientation'] }),
      ], job: [plot('multi', { type: 'line-plot-multi', name: 'Multi', parameter: 'workers', plotting: ' ' })],
    } } } as unknown as ResultConfigurationDocument;
    const expected = [
      'The result configuration version is unsupported.',
      'Each configuration needs a stable ID of at most 50 characters.',
      'Each configuration needs a name.',
      'Each plot needs a unique stable ID within its scope.',
      'Unsupported plot type “unknown”. Its data is preserved; a registered editor and renderer are required before saving.',
      'Each plot needs a title.',
      '“Untitled plot” needs metric or configuration parameter names.',
      '“Box” needs metric or configuration parameter names.',
      '“Box” needs a result metric to plot.',
      'Choose a valid box plot orientation.',
      '“Multi” needs a result metric to plot.',
    ];
    const aliases = { 'custom-box': 'box-plot' }, issues = resultConfigurationIssues(value, aliases);
    expect(validateResultConfigurations(value, aliases)).toEqual(expected);
    expect(issues.map(issue => issue.message)).toEqual(expected);
    expect(issues.map(({ message: _, ...target }) => target)).toEqual([
      { field: 'version' },
      { configurationId: 'bad id', field: 'configurationId' },
      { configurationId: 'bad id', field: 'configurationName' },
      ...['id', 'type', 'name', 'parameter'].map(field => ({ configurationId: 'bad id', scope: 'all', index: 0, field })),
      ...['parameter', 'plotting', 'orientation'].map(field => ({ configurationId: 'bad id', scope: 'all', index: 1, field })),
      { configurationId: 'bad id', scope: 'job', index: 0, field: 'plotting' },
    ]);
  });

  it('uses registered base types for required fields and preserves definitions, whitespace and extension metadata', () => {
    const value = document();
    value.elements['configuration'].all = [plot('custom', {
      type: 'custom-box', parameter: 'workers', plotting: '', orientation: 'horizontal',
      customZero: 0, customFalse: false, metadata: { text: '  Zürich <literal>  ' },
    })];
    const before = JSON.stringify(value);
    expect(resultConfigurationIssues(value, { 'custom-box': 'box-plot' })).toEqual([
      { message: '“Same chart title” needs a result metric to plot.', configurationId: 'configuration', scope: 'all', index: 0, field: 'plotting' },
    ]);
    expect(resultConfigurationIssues(value, { 'custom-box': 'unsupported-base' })).toEqual([
      { message: 'Unsupported plot type “custom-box”. Its data is preserved; a registered editor and renderer are required before saving.', configurationId: 'configuration', scope: 'all', index: 0, field: 'type' },
    ]);
    expect(JSON.stringify(value)).toBe(before);
  });

  it('keeps duplicate and malformed IDs attached to their original entries rather than matching message text', () => {
    const value = document();
    value.elements['configuration'].all = [plot('', { name: 'A' }), plot('', { name: 'B' }), plot('valid', { name: '' })];
    const issues = resultConfigurationIssues(value);
    expect(issues.map(issue => [issue.index, issue.field])).toEqual([[0, 'id'], [1, 'id'], [2, 'name']]);
    expect(issues[0].message).toBe(issues[1].message);
    expect(validateResultConfigurations(value)).toEqual([
      'Each plot needs a unique stable ID within its scope.',
      'Each plot needs a unique stable ID within its scope.',
      'Each plot needs a title.',
    ]);
  });
});
