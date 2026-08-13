<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$module = file_get_contents($root . '/LCNWindowGroup/module.php');
$html = file_get_contents($root . '/LCNWindowGroup/module.html');
if ($module === false || $html === false) {
    fwrite(STDERR, "FAIL group source missing\n");
    exit(1);
}

foreach (['LCNWindowGroup/module.json', 'LCNWindowGroup/form.json'] as $relative) {
    $raw = file_get_contents($root . '/' . $relative);
    if ($raw === false || json_decode($raw, true) === null) {
        fwrite(STDERR, "FAIL invalid JSON: $relative\n");
        exit(1);
    }
}

$mustContain = [
    "private const LCN_WINDOW_MODULE_ID = '{7AA3FC56-5CEC-4C42-9AF3-42DB2084772D}'",
    "private const KLF200_NODE_MODULE_ID = '{4EBD07B1-2962-4531-AC5F-7944789A9CE5}'",
    "private const START_DELAY_MS = 50",
    "private const COMMAND_GAP_MS = 1000",
    'KLF200_ShutterMoveDown($instanceID)',
    'LCW_Close($instanceID)',
    'LCW_GetWindowState((int) $Member[\'instanceID\'])',
    'IPS_GetObjectIDByIdent(self::KLF200_MAIN_IDENT, $InstanceID)',
    "SetTimerInterval(self::TIMER_NAME, self::START_DELAY_MS)",
    "SetTimerInterval(self::TIMER_NAME, self::COMMAND_GAP_MS)",
    "WriteAttributeBoolean('Running', false)",
    "WriteAttributeString('Queue', '[]')",
];
foreach ($mustContain as $needle) {
    if (!str_contains($module, $needle)) {
        fwrite(STDERR, "FAIL group invariant missing: $needle\n");
        exit(1);
    }
}

$forbidden = [
    '$this->SetValue(',
    'usleep(',
    'sleep(',
    'RequestAction($Member',
    "KLF200_ShutterMoveUp",
];
foreach ($forbidden as $needle) {
    if (str_contains($module, $needle)) {
        fwrite(STDERR, "FAIL forbidden group path: $needle\n");
        exit(1);
    }
}

// The group tile intentionally mirrors the LCNCommand/Zentral-AUS geometry and color.
foreach (['background: #00c7b0', 'width: 116px', 'height: 116px', 'border-radius: 58px', 'fa-power-off', "requestAction('CloseAll', true)"] as $needle) {
    if (!str_contains($html, $needle)) {
        fwrite(STDERR, "FAIL tile invariant missing: $needle\n");
        exit(1);
    }
}

// Running a sequence must not visually disable/grey the button.
if (str_contains($html, 'disabled = Boolean(state.running)') || str_contains($html, 'disabled=Boolean(state.running)')) {
    fwrite(STDERR, "FAIL running sequence greys button\n");
    exit(1);
}

echo "OK window_group_integrity_test\n";
