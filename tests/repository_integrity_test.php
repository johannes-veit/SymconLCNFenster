<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$module = file_get_contents($root . '/LCNWindow/module.php');
$html = file_get_contents($root . '/LCNWindow/module.html');
if ($module === false || $html === false) {
    fwrite(STDERR, "FAIL source files missing\n");
    exit(1);
}

foreach (['library.json', 'LCNWindow/module.json', 'LCNWindow/form.json'] as $relative) {
    $raw = file_get_contents($root . '/' . $relative);
    if ($raw === false || json_decode($raw, true) === null) {
        fwrite(STDERR, "FAIL invalid JSON: $relative\n");
        exit(1);
    }
}

$mustContain = [
    "RegisterAttributeInteger('StableState'",
    'RegisterMessage($variableID, self::MSG_VARIABLE_UPDATE)',
    "Data[1] === false",
    'LCN_SendCommand($sendModule, \'TS\', $data)',
    "IPS_SemaphoreEnter(self::SEND_SEMAPHORE",
    "IPS_Sleep(self::SEND_GAP_MS)",
    "SetVisualizationType(1)",
    'UpdateVisualizationValue($payload)',
];
foreach ($mustContain as $needle) {
    if (!str_contains($module, $needle)) {
        fwrite(STDERR, "FAIL missing invariant: $needle\n");
        exit(1);
    }
}

$forbidden = [
    'LCN_SwitchRelay',
    'LCN_RequestStatus',
    'RegisterTimer(',
    'SetTimerInterval(',
    'LCN_SetRelay',
];
foreach ($forbidden as $needle) {
    if (str_contains($module, $needle)) {
        fwrite(STDERR, "FAIL forbidden hardware/timer path: $needle\n");
        exit(1);
    }
}

if (substr_count($module, 'LCN_SendCommand(') !== 1) {
    fwrite(STDERR, "FAIL expected exactly one LCN_SendCommand call site\n");
    exit(1);
}

foreach (['%%INITIAL_DATA%%', "send('Open')", "send('Close')", 'Der Befehl wird nicht automatisch wiederholt'] as $needle) {
    if (!str_contains($html, $needle)) {
        fwrite(STDERR, "FAIL HTML invariant missing: $needle\n");
        exit(1);
    }
}

if (str_contains($html, 'disabled = inFlight') || str_contains($html, 'disabled=inFlight')) {
    fwrite(STDERR, "FAIL visualization disables controls merely because API is in flight\n");
    exit(1);
}

echo "OK repository_integrity_test\n";
