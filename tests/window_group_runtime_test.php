<?php

declare(strict_types=1);

const LCN_MODULE = '{7AA3FC56-5CEC-4C42-9AF3-42DB2084772D}';
const KLF_MODULE = '{4EBD07B1-2962-4531-AC5F-7944789A9CE5}';

$GLOBALS['instances'] = [
    101 => ['module' => LCN_MODULE, 'name' => 'LCN offen'],
    102 => ['module' => LCN_MODULE, 'name' => 'LCN zu'],
    201 => ['module' => KLF_MODULE, 'name' => 'KLF offen'],
    202 => ['module' => KLF_MODULE, 'name' => 'KLF zu'],
    999 => ['module' => '{00000000-0000-0000-0000-000000000999}', 'name' => 'Falsch'],
];
$GLOBALS['variables'] = [
    301 => ['type' => 1, 'value' => 0],
    302 => ['type' => 1, 'value' => 51200],
];
$GLOBALS['idents'] = [
    201 => ['MAIN' => 301],
    202 => ['MAIN' => 302],
];
$GLOBALS['lcn_state'] = [101 => 2, 102 => 1];
$GLOBALS['commands'] = [];

function IPS_InstanceExists(int $id): bool { return isset($GLOBALS['instances'][$id]); }
function IPS_GetInstance(int $id): array { return ['ModuleInfo' => ['ModuleID' => $GLOBALS['instances'][$id]['module']]]; }
function IPS_GetName(int $id): string { return $GLOBALS['instances'][$id]['name'] ?? ('#' . $id); }
function IPS_GetObjectIDByIdent(string $ident, int $parentID): int|false { return $GLOBALS['idents'][$parentID][$ident] ?? false; }
function IPS_VariableExists(int $id): bool { return isset($GLOBALS['variables'][$id]); }
function IPS_GetVariable(int $id): array { return ['VariableType' => $GLOBALS['variables'][$id]['type'] ?? -1]; }
function GetValue(int $id): mixed { return $GLOBALS['variables'][$id]['value']; }
function LCW_GetWindowState(int $id): int { return $GLOBALS['lcn_state'][$id] ?? 0; }
function LCW_Close(int $id): bool { $GLOBALS['commands'][] = ['lcn', $id]; return true; }
function KLF200_ShutterMoveDown(int $id): bool { $GLOBALS['commands'][] = ['klf', $id]; return true; }

class IPSModuleStrict
{
    public int $InstanceID = 900;
    public int $moduleStatus = 0;
    public string $summary = '';
    protected array $properties = [];
    protected array $attributes = [];
    protected array $references = [];
    public array $timers = [];
    public array $visualPayloads = [];

    public function Create(): void {}
    public function ApplyChanges(): void {}
    protected function RegisterPropertyString(string $name, string $default): void { $this->properties[$name] ??= $default; }
    protected function RegisterAttributeString(string $name, string $default): void { $this->attributes[$name] ??= $default; }
    protected function RegisterAttributeBoolean(string $name, bool $default): void { $this->attributes[$name] ??= $default; }
    protected function ReadPropertyString(string $name): string { return (string) $this->properties[$name]; }
    protected function ReadAttributeString(string $name): string { return (string) $this->attributes[$name]; }
    protected function ReadAttributeBoolean(string $name): bool { return (bool) $this->attributes[$name]; }
    protected function WriteAttributeString(string $name, string $value): void { $this->attributes[$name] = $value; }
    protected function WriteAttributeBoolean(string $name, bool $value): void { $this->attributes[$name] = $value; }
    protected function RegisterTimer(string $name, int $interval, string $script): void { $this->timers[$name] = $interval; }
    protected function SetTimerInterval(string $name, int $interval): void { $this->timers[$name] = $interval; }
    protected function SetVisualizationType(int $type): void {}
    protected function RegisterReference(int $id): void { $this->references[$id] = $id; }
    protected function UnregisterReference(int $id): void { unset($this->references[$id]); }
    protected function GetReferenceList(): array { return array_values($this->references); }
    protected function SetStatus(int $status): void { $this->moduleStatus = $status; }
    protected function SetSummary(string $summary): void { $this->summary = $summary; }
    protected function UpdateVisualizationValue(mixed $payload): void { $this->visualPayloads[] = $payload; }
    protected function SendDebug(string $message, string $data, int $format): void {}

    public function MockSetProperty(string $name, mixed $value): void { $this->properties[$name] = $value; }
    public function MockRunning(): bool { return (bool) ($this->attributes['Running'] ?? false); }
    public function MockQueue(): string { return (string) ($this->attributes['Queue'] ?? '[]'); }
}

require_once __DIR__ . '/../LCNWindowGroup/module.php';

function assertSameStrict(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL $label: expected " . var_export($expected, true) . ', got ' . var_export($actual, true) . "\n");
        exit(1);
    }
}

$group = new LCNWindowGroup();
$group->Create();
$group->MockSetProperty('Members', json_encode([
    ['InstanceID' => 101], // must close
    ['InstanceID' => 102], // already closed -> skip
    ['InstanceID' => 201], // must close
    ['InstanceID' => 202], // already closed -> skip
]));
$group->ApplyChanges();

assertSameStrict(102, $group->moduleStatus, 'valid mixed selection active');
assertSameStrict('4 Fenster · 1 s Abstand', $group->summary, 'summary');
assertSameStrict(0, $group->timers['SequenceTimer'], 'ApplyChanges timer off');
assertSameStrict([], $GLOBALS['commands'], 'ApplyChanges sends no hardware command');

// Visu click only creates the queue and a short timer; it sends no hardware command.
assertSameStrict(true, $group->CloseAll(), 'CloseAll starts');
assertSameStrict([], $GLOBALS['commands'], 'CloseAll click itself sends no hardware command');
assertSameStrict(true, $group->MockRunning(), 'sequence running after click');
assertSameStrict(50, $group->timers['SequenceTimer'], 'short start timer armed');

// Repeated press while running must not duplicate the queue or send a command.
assertSameStrict(true, $group->CloseAll(), 'repeated CloseAll accepted as no-op');
assertSameStrict([], $GLOBALS['commands'], 'no duplicate from repeated press');

// First timer callback sends LCN close and arms exactly 1 s for the next required command.
assertSameStrict(true, $group->ProcessNext(), 'first timer step');
assertSameStrict([['lcn', 101]], $GLOBALS['commands'], 'first command is LCN close');
assertSameStrict(1000, $group->timers['SequenceTimer'], '1s timer armed after first command');
assertSameStrict(true, $group->MockRunning(), 'sequence still running');

// Next timer sends only the KLF native close command, then sequence ends.
assertSameStrict(true, $group->ProcessNext(), 'second timer step');
assertSameStrict([['lcn', 101], ['klf', 201]], $GLOBALS['commands'], 'second command is KLF native close');
assertSameStrict(false, $group->MockRunning(), 'sequence finished');
assertSameStrict(0, $group->timers['SequenceTimer'], 'timer off after finish');
assertSameStrict('[]', $group->MockQueue(), 'queue empty after finish');

// If all are already closed, no command and no timer is created.
$GLOBALS['lcn_state'][101] = 1;
$GLOBALS['variables'][301]['value'] = 51200;
$before = count($GLOBALS['commands']);
assertSameStrict(true, $group->CloseAll(), 'all-closed CloseAll succeeds');
assertSameStrict($before, count($GLOBALS['commands']), 'all-closed sends nothing');
assertSameStrict(false, $group->MockRunning(), 'all-closed not running');
assertSameStrict(0, $group->timers['SequenceTimer'], 'all-closed timer remains off');

// Unsupported instance is rejected as a configuration error; nothing is sent.
$group->MockSetProperty('Members', json_encode([['InstanceID' => 999]]));
$group->ApplyChanges();
assertSameStrict(201, $group->moduleStatus, 'unsupported instance status');
$before = count($GLOBALS['commands']);
assertSameStrict(false, $group->CloseAll(), 'unsupported selection blocked');
assertSameStrict($before, count($GLOBALS['commands']), 'unsupported selection sends nothing');

// ApplyChanges while a sequence is running must discard queue/timer and never resume it.
$GLOBALS['lcn_state'][101] = 2;
$GLOBALS['variables'][301]['value'] = 0;
$group->MockSetProperty('Members', json_encode([['InstanceID' => 101], ['InstanceID' => 201]]));
$group->ApplyChanges();
assertSameStrict(true, $group->CloseAll(), 'restart test sequence starts');
assertSameStrict(true, $group->MockRunning(), 'restart test running');
$group->ApplyChanges();
assertSameStrict(false, $group->MockRunning(), 'ApplyChanges cancels running sequence');
assertSameStrict(0, $group->timers['SequenceTimer'], 'ApplyChanges cancels timer');
assertSameStrict('[]', $group->MockQueue(), 'ApplyChanges clears queue');

echo "OK window_group_runtime_test\n";
