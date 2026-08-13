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
    'private const KLF200_RUN_IDENT = \'RunStatus\'',
    'private const MSG_VARIABLE_UPDATE = 10603',
    'private const COMMAND_GAP_MS = 1000',
    'IPS_RunScriptText($script)',
    'KLF200_ShutterMoveDown(%1$d)',
    'LCW_Close(%1$d)',
    'LCW_GetWindowState((int) $Member[\'instanceID\'])',
    'IPS_GetObjectIDByIdent(self::LCN_STATUS_IDENT, $InstanceID)',
    'IPS_GetObjectIDByIdent(self::KLF200_MAIN_IDENT, $InstanceID)',
    'IPS_GetObjectIDByIdent(self::KLF200_RUN_IDENT, $InstanceID)',
    'SetTimerInterval(self::TIMER_NAME, self::COMMAND_GAP_MS)',
    "WriteAttributeBoolean('Running', false)",
    "WriteAttributeString('Queue', '[]')",
    'RegisterMessage($variableID, self::MSG_VARIABLE_UPDATE)',
    "'members' => \$this->GetMemberInfo(\$validation['members'])",
    "\$statusText = 'LÄUFT'",
    "\$statusText = 'FÄHRT ZU'",
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
    'RequestAction($Member',
    'KLF200_ShutterMoveUp',
    'START_DELAY_MS',
];
foreach ($forbidden as $needle) {
    if (str_contains($module, $needle)) {
        fwrite(STDERR, "FAIL forbidden group path: $needle\n");
        exit(1);
    }
}

// CloseAll must queue every selected member; no pre-filter by click-time status.
$closeStart = strpos($module, 'public function CloseAll(): bool');
$processStart = strpos($module, 'public function ProcessNext(): bool');
$closeBody = substr($module, $closeStart, $processStart - $closeStart);
if (str_contains($closeBody, 'IsAlreadyClosed(')) {
    fwrite(STDERR, "FAIL CloseAll still pre-filters members by click-time state\n");
    exit(1);
}
if (!str_contains($closeBody, "static fn (array \$member): int => (int) \$member['instanceID']")) {
    fwrite(STDERR, "FAIL CloseAll does not queue all selected member IDs\n");
    exit(1);
}

// Critical V0.2.2 architecture: ProcessNext must only launch an asynchronous
// worker; no direct hardware command is allowed in the timer callback.
$workerResultStart = strpos($module, 'public function WorkerResult(');
$processBody = substr($module, $processStart, $workerResultStart - $processStart);
foreach (['KLF200_ShutterMoveDown(', 'LCW_Close('] as $needle) {
    if (str_contains($processBody, $needle)) {
        fwrite(STDERR, "FAIL timer callback still calls hardware synchronously: $needle\n");
        exit(1);
    }
}
if (!str_contains($processBody, 'LaunchCloseWorker($member)')) {
    fwrite(STDERR, "FAIL timer callback does not launch async worker\n");
    exit(1);
}

// The tile mirrors Zentral-AUS and provides feedback + live member list.
foreach ([
    'background: #00c7b0',
    'width: 116px',
    'height: 116px',
    'border-radius: 58px',
    'fa-power-off',
    "requestAction('CloseAll', true)",
    'id="feedback"',
    'Befehl gesendet',
    '}, 3000);',
    'id="members"',
    'Fensterstatus',
    'max-height: none',
    'overflow: visible',
] as $needle) {
    if (!str_contains($html, $needle)) {
        fwrite(STDERR, "FAIL tile invariant missing: $needle\n");
        exit(1);
    }
}

if (str_contains($html, 'disabled = Boolean(state.running)') || str_contains($html, 'disabled=Boolean(state.running)')) {
    fwrite(STDERR, "FAIL running sequence greys button\n");
    exit(1);
}
if (str_contains($html, 'overflow-y: auto')) {
    fwrite(STDERR, "FAIL member list has internal vertical scroll\n");
    exit(1);
}

echo "OK window_group_integrity_test\n";
