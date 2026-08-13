<?php

declare(strict_types=1);

$GLOBALS['mock_instances'] = [100 => true];
$GLOBALS['mock_variables'] = [
    201 => ['type' => 0, 'value' => false], // AUF
    202 => ['type' => 0, 'value' => false], // ZU
    203 => ['type' => 0, 'value' => false], // alternative AUF
];
$GLOBALS['mock_profiles'] = [];
$GLOBALS['mock_commands'] = [];
$GLOBALS['mock_send_result'] = true;
$GLOBALS['mock_object_idents'] = [];
$GLOBALS['mock_next_object_id'] = 1000;

function IPS_InstanceExists(int $id): bool { return isset($GLOBALS['mock_instances'][$id]); }
function IPS_VariableExists(int $id): bool { return isset($GLOBALS['mock_variables'][$id]); }
function IPS_GetVariable(int $id): array { return ['VariableType' => $GLOBALS['mock_variables'][$id]['type'] ?? -1]; }
function GetValue(int $id): mixed { return $GLOBALS['mock_variables'][$id]['value']; }
function IPS_FunctionExists(string $name): bool { return $name === 'LCN_SendCommand'; }
function LCN_SendCommand(int $instanceID, string $function, string $data): bool {
    $GLOBALS['mock_commands'][] = [$instanceID, $function, $data];
    return (bool) $GLOBALS['mock_send_result'];
}
function IPS_SemaphoreEnter(string $name, int $timeout): bool { return true; }
function IPS_SemaphoreLeave(string $name): void {}
function IPS_Sleep(int $ms): void {}
function IPS_VariableProfileExists(string $name): bool { return isset($GLOBALS['mock_profiles'][$name]); }
function IPS_CreateVariableProfile(string $name, int $type): void { $GLOBALS['mock_profiles'][$name] = ['type' => $type]; }
function IPS_SetVariableProfileValues(string $name, float $min, float $max, float $step): void {}
function IPS_SetVariableProfileAssociation(string $profile, float $value, string $name, string $icon, int $color): void {}
function IPS_SetIcon(int $id, string $icon): void {}
function IPS_GetObjectIDByIdent(string $ident, int $parentID): int|false {
    return $GLOBALS['mock_object_idents'][$parentID][$ident] ?? false;
}

class IPSModuleStrict
{
    public int $InstanceID = 900;
    protected array $properties = [];
    protected array $attributes = [];
    protected array $values = [];
    protected array $messages = [];
    public int $moduleStatus = 0;
    public string $summary = '';
    public array $visualPayloads = [];

    public function Create(): void {}
    public function ApplyChanges(): void {}
    protected function RegisterPropertyInteger(string $name, int $default): void { $this->properties[$name] ??= $default; }
    protected function RegisterPropertyString(string $name, string $default): void { $this->properties[$name] ??= $default; }
    protected function ReadPropertyInteger(string $name): int { return (int) $this->properties[$name]; }
    protected function ReadPropertyString(string $name): string { return (string) $this->properties[$name]; }
    protected function RegisterAttributeInteger(string $name, int $default): void { $this->attributes[$name] ??= $default; }
    protected function RegisterAttributeBoolean(string $name, bool $default): void { $this->attributes[$name] ??= $default; }
    protected function RegisterAttributeString(string $name, string $default): void { $this->attributes[$name] ??= $default; }
    protected function ReadAttributeInteger(string $name): int { return (int) $this->attributes[$name]; }
    protected function ReadAttributeBoolean(string $name): bool { return (bool) $this->attributes[$name]; }
    protected function ReadAttributeString(string $name): string { return (string) $this->attributes[$name]; }
    protected function WriteAttributeInteger(string $name, int $value): void { $this->attributes[$name] = $value; }
    protected function WriteAttributeBoolean(string $name, bool $value): void { $this->attributes[$name] = $value; }
    protected function WriteAttributeString(string $name, string $value): void { $this->attributes[$name] = $value; }
    protected function SetVisualizationType(int $type): void {}
    protected function MaintainVariable(string $ident, string $name, int $type, mixed $profile, int $position, bool $keep): bool {
        if (!$keep) { return false; }
        if (array_key_exists($ident, $this->values)) { return false; }
        $this->values[$ident] = $type === 0 ? false : 0;
        $id = $GLOBALS['mock_next_object_id']++;
        $GLOBALS['mock_object_idents'][$this->InstanceID][$ident] = $id;
        $GLOBALS['mock_variables'][$id] = ['type' => $type, 'value' => $this->values[$ident]];
        return true;
    }
    protected function SetValue(string $ident, mixed $value): void {
        $this->values[$ident] = $value;
        $id = $GLOBALS['mock_object_idents'][$this->InstanceID][$ident] ?? 0;
        if ($id) { $GLOBALS['mock_variables'][$id]['value'] = $value; }
    }
    protected function GetValue(string $ident): mixed { return $this->values[$ident]; }
    protected function RegisterReference(int $id): void {}
    protected function ResetReferences(): void {}
    protected function RegisterMessage(int $id, int $message): void { $this->messages[$id][$message] = true; }
    protected function UnregisterMessage(int $id, int $message): void { unset($this->messages[$id][$message]); }
    protected function SetStatus(int $status): void { $this->moduleStatus = $status; }
    protected function SetSummary(string $summary): void { $this->summary = $summary; }
    protected function UpdateVisualizationValue(mixed $payload): void { $this->visualPayloads[] = $payload; }
    protected function SendDebug(string $message, string $data, int $format): void {}

    public function MockSetProperty(string $name, mixed $value): void { $this->properties[$name] = $value; }
    public function MockMessages(): array { return $this->messages; }
}

require_once __DIR__ . '/../LCNWindow/module.php';

function assertSameValue(mixed $expected, mixed $actual, string $label): void {
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL $label: expected " . var_export($expected, true) . ', got ' . var_export($actual, true) . "\n");
        exit(1);
    }
}

function relayEvent(LCNWindow $module, int $id, bool $value, bool $changed = true): void {
    $old = (bool) $GLOBALS['mock_variables'][$id]['value'];
    $GLOBALS['mock_variables'][$id]['value'] = $value;
    $module->MessageSink(time(), $id, 10603, [$value, $changed, $old, time()]);
}

$module = new LCNWindow();
$module->Create();
$module->MockSetProperty('SendModule', 100);
$module->MockSetProperty('Table', 'A');
$module->MockSetProperty('Key', 3);
$module->MockSetProperty('RelayOpenVariable', 201);
$module->MockSetProperty('RelayCloseVariable', 202);
$module->ApplyChanges();

assertSameValue(102, $module->moduleStatus, 'module active after valid apply');
assertSameValue(0, $module->GetWindowState(), 'initial unknown');
assertSameValue(0, count($GLOBALS['mock_commands']), 'ApplyChanges sends no hardware command');

// External GT8 ZU: real close relay defines the direction, then both OFF keep CLOSED.
relayEvent($module, 202, true);
assertSameValue(3, $module->GetWindowState(), 'external close -> moving close');
assertSameValue(1, $module->GetStableState(), 'external close learns CLOSED');
relayEvent($module, 202, false);
assertSameValue(1, $module->GetWindowState(), 'relay off -> CLOSED persists');

// Routine restart/update with both relays OFF must not lose the learned end state.
$module->ApplyChanges();
assertSameValue(1, $module->GetWindowState(), 'ApplyChanges with both relays off preserves CLOSED');
assertSameValue(1, $module->GetStableState(), 'stable CLOSED persists across ApplyChanges');
assertSameValue(0, count($GLOBALS['mock_commands']), 'routine ApplyChanges still sends nothing');

// Symcon AUF emits exactly one LONG/MAKE event. Final state is not invented until relay feedback.
assertSameValue(true, $module->Open(), 'Open accepted');
assertSameValue(1, count($GLOBALS['mock_commands']), 'one command after Open');
assertSameValue([100, 'TS', 'L---00100000'], $GLOBALS['mock_commands'][0], 'Open TS encoding');
assertSameValue(1, $module->GetWindowState(), 'Open command alone does not fake relay motion');
relayEvent($module, 201, true);
assertSameValue(4, $module->GetWindowState(), 'open relay -> moving open');
assertSameValue(2, $module->GetStableState(), 'open relay learns OPEN');
relayEvent($module, 201, false);
assertSameValue(2, $module->GetWindowState(), 'open relay off -> OPEN');

// Redundant AUF is a no-op, matching the grey AUF button.
assertSameValue(true, $module->Open(), 'redundant Open treated as already fulfilled');
assertSameValue(1, count($GLOBALS['mock_commands']), 'redundant Open sends nothing');

// ZU emits exactly one SHORT/HIT event.
assertSameValue(true, $module->Close(), 'Close accepted');
assertSameValue(2, count($GLOBALS['mock_commands']), 'one additional command after Close');
assertSameValue([100, 'TS', 'K---00100000'], $GLOBALS['mock_commands'][1], 'Close TS encoding');

// An unchanged refresh must never create a false movement, even if test storage is manipulated.
$GLOBALS['mock_variables'][201]['value'] = true;
$module->MessageSink(time(), 201, 10603, [true, false, true, time()]);
assertSameValue(2, $module->GetWindowState(), 'unchanged VM_UPDATE ignored');
$GLOBALS['mock_variables'][201]['value'] = false;

// Both real direction relays ON is a hard safety fault; no command is allowed.
relayEvent($module, 201, true);
relayEvent($module, 202, true);
assertSameValue(5, $module->GetWindowState(), 'both relays -> fault');
assertSameValue(203, $module->moduleStatus, 'both relays -> module status 203');
$before = count($GLOBALS['mock_commands']);
assertSameValue(false, $module->Open(), 'Open blocked during conflict');
assertSameValue($before, count($GLOBALS['mock_commands']), 'fault sends no command');

// Clearing the real conflict via feedback self-recovers without losing stable state.
relayEvent($module, 202, false);
assertSameValue(4, $module->GetWindowState(), 'one remaining open relay -> moving open');
assertSameValue(102, $module->moduleStatus, 'module recovers after conflict clears');
relayEvent($module, 201, false);
assertSameValue(2, $module->GetWindowState(), 'both off after recovered open -> OPEN');

// Reconfiguration must remove old message subscriptions and attach new one.
$module->MockSetProperty('RelayOpenVariable', 203);
$module->ApplyChanges();
$msgs = $module->MockMessages();
assertSameValue(false, isset($msgs[201][10603]), 'old open feedback unsubscribed');
assertSameValue(true, isset($msgs[203][10603]), 'new open feedback subscribed');

// Failed send is reported once; there is no internal retry.
$GLOBALS['mock_send_result'] = false;
$GLOBALS['mock_variables'][203]['value'] = false;
$GLOBALS['mock_variables'][202]['value'] = false;
// Stable is OPEN, therefore choose Close to force a transmission.
$before = count($GLOBALS['mock_commands']);
assertSameValue(false, $module->Close(), 'failed LCN send returns false');
assertSameValue($before + 1, count($GLOBALS['mock_commands']), 'failed send attempted exactly once');

echo "OK runtime_simulation_test\n";
