<?php
// Installed synthetic processor, exercised only by the disposable test stack.
foreach ($allConfigurations as &$configuration) {
    $configuration['parameters'][$parameter] = 2 * (int) $data[$parameter];
}
unset($configuration);
