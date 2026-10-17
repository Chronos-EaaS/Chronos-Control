<?php
declare(strict_types=1);

// Synthetic schemas only. No installed system or production data is copied here.
$elements = [
    ['id' => 'fixture-enabled', 'type' => 'boolean', 'name' => 'Enabled', 'parameter' => 'enabled', 'default' => 'false'],
    ['id' => 'fixture-quantity', 'type' => 'integer', 'name' => 'Quantity', 'parameter' => 'quantity', 'default' => '0'],
    ['id' => 'fixture-label', 'type' => 'string', 'name' => 'Label', 'parameter' => 'label', 'default' => 'Fixture label'],
    ['id' => 'fixture-mode', 'type' => 'dropdown', 'name' => 'Mode', 'parameter' => 'mode', 'options' => 'basic,advanced', 'names' => 'Basic,Advanced', 'default' => 'basic'],
    ['id' => 'fixture-choice', 'type' => 'checkbox-selection', 'name' => 'Choice', 'parameter' => 'choice', 'options' => 'read,write', 'default' => 'read'],
    ['id' => 'fixture-size', 'type' => 'interval', 'name' => 'Size', 'parameter' => 'size', 'default-min' => '10', 'default-max' => '20', 'default-step' => '10'],
    ['id' => 'fixture-share', 'type' => 'percentage', 'name' => 'Share', 'parameter' => 'share', 'parameter-type' => 'int', 'dependency' => 'quantity', 'default' => '50'],
    ['id' => 'fixture-portion', 'type' => 'percentage-interval', 'name' => 'Portion', 'parameter' => 'portion', 'parameter-type' => 'int', 'dependency' => 'quantity', 'default-start' => '0', 'default-end' => '100', 'default-step' => '100'],
    ['id' => 'fixture-dependent', 'type' => 'percentage-dep', 'name' => 'Dependent Share', 'parameter' => 'dependent', 'parameter-type' => 'int', 'dependency' => 'portion', 'default' => '50'],
    ['id' => 'fixture-ratio', 'type' => 'ratio', 'name' => 'Ratio', 'parameter' => 'left,right', 'default-start' => '0%:100%', 'default-end' => '100%:0%', 'default-step' => '100%'],
    ['id' => 'fixture-tag', 'type' => 'string-list', 'name' => 'Tags', 'parameter' => 'tag', 'default' => 'alpha'],
    ['id' => 'fixture-version', 'type' => 'software-versions-list', 'name' => 'Version', 'parameter' => 'version', 'repository' => '/fixture/repository'],
];

$plots = [
    ['id' => 'plot-bar', 'type' => 'bar-plot', 'name' => 'Fixture Bar', 'parameter' => 'metric'],
    ['id' => 'plot-line', 'type' => 'line-plot', 'name' => 'Fixture Line', 'parameter' => 'series'],
    ['id' => 'plot-multi', 'type' => 'line-plot-multi', 'name' => 'Fixture Multi', 'parameter' => 'size', 'plotting' => 'metric'],
    ['id' => 'plot-pie', 'type' => 'pie-plot', 'name' => 'Fixture Pie', 'parameter' => 'read,write'],
    ['id' => 'plot-box', 'type' => 'box-plot', 'name' => 'Fixture Box', 'parameter' => 'size', 'plotting' => 'metric', 'orientation' => 'vertical'],
];
$overallPlots = $plots;
// The legacy line renderer supports bracketed series only in per-job groups.
// Overall scope uses numeric metrics to avoid its early-return limitation.
$overallPlots[1]['parameter'] = 'metric,read';

return [
    'parameters' => [
        ['id' => 'fixture-basic', 'title' => 'Fixture Parameters', 'depends' => '', 'dependsValue' => '', 'elements' => $elements],
        ['id' => 'fixture-conditional', 'title' => 'Advanced Parameters', 'depends' => 'mode', 'dependsValue' => 'advanced', 'elements' => [
            ['id' => 'fixture-detail', 'type' => 'string', 'name' => 'Detail', 'parameter' => 'detail', 'default' => 'Advanced detail'],
        ]],
    ],
    'plots' => $plots,
    'overallPlots' => $overallPlots,
    'results' => ['version' => '1.0', 'elements' => [
        'system-1' => ['name' => 'Fixture Results', 'all' => $overallPlots, 'job' => $plots],
    ]],
    // A deliberately small saved experiment keeps run-generation tests independent
    // of browser-side defaults and the legacy software-version copy defect.
    'experimentPostData' => [
        'name' => 'Fixture Experiment', 'description' => 'Synthetic experiment',
        'projectId' => '1', 'runs' => '1', 'run-distribution' => 'alter',
        'phase_warmUp' => '1', 'elements' => ['fixture-quantity'],
        'fixture-quantity-type' => 'integer', 'fixture-quantity-parameter' => 'quantity',
        'quantity' => '10',
    ],
];
