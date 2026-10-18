<?php
$values = [];
foreach ($jobs as $group) foreach ($group as $job) {
    $result = json_decode($job->getResult(), true);
    $values[] = (float) ($result['metric'] ?? 0);
}
$plotData = json_encode(['labels' => ['Synthetic score'], 'datasets' => [['label' => 'Total', 'data' => [array_sum($values)]]]], JSON_THROW_ON_ERROR);
