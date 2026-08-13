<?php

declare(strict_types=1);

const LCN_MODULE = '{7AA3FC56-5CEC-4C42-9AF3-42DB2084772D}';
const KLF_MODULE = '{4EBD07B1-2962-4531-AC5F-7944789A9CE5}';

$GLOBALS['instances'] = [
    101 => ['module' => LCN_MODULE, 'name' => 'EG Gäste WC'],
    102 => ['module' => LCN_MODULE, 'name' => 'LCN schon zu'],
    103 => ['module' => LCN_MODULE, 'name' => 'EG Küche'],
    201 => ['module' => KLF_MODULE, 'name' => 'Dachfenster Schlafzimmer'],
    202 => ['module' => KLF_MODULE, 'name' => 'KLF schon zu'],
    999 => ['module' => '{00000000-0000-0000-0000-000000000999}', 'name' => 'Falsch'],
];
$GLOBALS['variables'] = [
    301 => ['type' => 1, 'value' => 0],      // KLF MAIN offen
    302 => ['type' => 1, 'value' => 51200],  // KLF MAIN zu
    311 => ['type' => 0, 'value' => false],  // KLF RunStatus gestoppt
    312 => ['type' => 0, 'value' => false],
    401 => ['type' => 1, 'value' => 2],      // LCN Status offen
    402 => ['type' => 1, 'value' => 1],      // LCN Status zu
    403 => ['type' => 1, 'value' => 2],      // LCN Status offen
];
$GLOBALS['idents'] = [
    101 => ['Status' => 401],
    102 => ['Status' => 402],
    103 => ['Status' => 403],
    201 => ['MAIN' => 301, 'RunStatus' => 311],
    202 => ['MAIN' => 302, 'RunStatus' => 312],
];
$GLOBALS['lcn_state'] = [101 => 2, 102 => 1, 103 => 2];
$GLOBALS['commands'] = [];
$GLOBALS['asyncScripts'] = [];
$GLOBALS['workerResults'] = [];
$GLOBALS['logs'] = [];
$GLOBALS['groupObject'] = null;

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
function IPS_RunScriptText(string $script): bool { $GLOBALS['asyncScripts'][] = $script; return true; }
function IPS_FunctionExists(string $name): bool { return function_exists($name); }
function IPS_LogMessage(string $sender, string $message): void { $GLOBALS['logs'][] = [$sender, $message]; }
function LCWG_WorkerResult(int $groupID, int $memberID, bool $success, string $message = ''): void
{
    $GLOBALS['workerResults'][] = [$memberID, $success, $message];
    if ($GLOBALS['groupObject'] instanceof LCNWindowGroup) {
        $GLOBALS['groupObject']->WorkerResult($memberID, $success, $message);
    }
}

class IPSModuleStrict
{
    public int $InstanceID = 900;
    public int $moduleStatus = 0;
    public string $summary = '';
    protected array $properties = [];
    protected array $attributes = [];
    protected array $references = [];
    protected array $messages = [];
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
    protected function RegisterMessage(int $id, int $message): void { $this->messages[$id][$message] = true; }
    protected function UnregisterMessage(int $id, int $message): void { unset($this->messages[$id][$message]); }
    protected function SetStatus(int $status): void { $this->moduleStatus = $status; }
    protected function SetSummary(string $summary): void { $this->summary = $summary; }
    protected function UpdateVisualizationValue(mixed $payload): void { $this->visualPayloads[] = $payload; }
    protected function SendDebug(string $message, string $data, int $format): void {}

    public function MockSetProperty(string $name, mixed $value): void { $this->properties[$name] = $value; }
    public function MockRunning(): bool { return (bool) ($this->attributes['Running'] ?? false); }
    public function MockQueue(): string { return (string) ($this->attributes['Queue'] ?? '[]'); }
    public function MockMessages(): array { return $this->messages; }
}

require_once __DIR__ . '/../LCNWindowGroup/module.php';

function assertSameStrict(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL $label: expected " . var_export($expected, true) . ', got ' . var_export($actual, true) . "\n");
        exit(1);
    }
}
function assertTrueStrict(bool $actual, string $label): void { assertSameStrict(true, $actual, $label); }

$group = new LCNWindowGroup();
$GLOBALS['groupObject'] = $group;
$group->Create();
$group->MockSetProperty('Members', json_encode([
    ['InstanceID' => 201], // Dachfenster offen
    ['InstanceID' => 101], // Gäste-WC offen
    ['InstanceID' => 103], // Küche offen
    ['InstanceID' => 102], // schon geschlossen
    ['InstanceID' => 202], // schon geschlossen
]));
$group->ApplyChanges();

assertSameStrict(102, $group->moduleStatus, 'valid mixed selection active');
assertSameStrict('5 Fenster · 1 s Abstand · async', $group->summary, 'summary');
assertSameStrict(0, $group->timers['SequenceTimer'], 'ApplyChanges timer off');
assertSameStrict([], $GLOBALS['commands'], 'ApplyChanges sends no hardware command');

// Beobachtet werden LCN Status, KLF MAIN UND KLF RunStatus.
$messages = $group->MockMessages();
foreach ([301, 302, 311, 312, 401, 402, 403] as $statusID) {
    assertTrueStrict(isset($messages[$statusID][10603]), 'status message registered #' . $statusID);
}

$initialPayload = json_decode((string) end($group->visualPayloads), true);
assertSameStrict('AUF', $initialPayload['members'][0]['statusText'] ?? '', 'KLF idle open');
assertSameStrict('AUF', $initialPayload['members'][1]['statusText'] ?? '', 'LCN idle open');

assertSameStrict(true, $group->CloseAll(), 'CloseAll starts');
assertSameStrict([], $GLOBALS['commands'], 'click itself sends no hardware command');
assertSameStrict([], $GLOBALS['asyncScripts'], 'click itself starts no worker');
assertSameStrict(1000, $group->timers['SequenceTimer'], '1s dispatcher armed');

// Kritischer Realfall: KLF zuerst, danach zwei LCN-Fenster.
// ProcessNext darf dabei niemals KLF/LCN synchron ausführen, sondern muss drei
// voneinander unabhängige Worker starten. Ein blockierender KLF-Worker kann den
// Gruppentimer daher nicht festhalten.
assertSameStrict(true, $group->ProcessNext(), 'dispatch slot 1');
assertSameStrict([], $GLOBALS['commands'], 'slot 1 did not execute KLF synchronously');
assertSameStrict(1, count($GLOBALS['asyncScripts']), 'slot 1 launched worker');
assertSameStrict(true, $group->MockRunning(), 'still running after slot 1');

assertSameStrict(true, $group->ProcessNext(), 'dispatch slot 2');
assertSameStrict([], $GLOBALS['commands'], 'slot 2 did not execute LCN synchronously');
assertSameStrict(2, count($GLOBALS['asyncScripts']), 'slot 2 launched independent worker');

assertSameStrict(true, $group->ProcessNext(), 'dispatch slot 3');
assertSameStrict([], $GLOBALS['commands'], 'slot 3 did not execute LCN synchronously');
assertSameStrict(3, count($GLOBALS['asyncScripts']), 'slot 3 launched independent worker');
assertSameStrict(true, $group->MockRunning(), 'closed tail still queued');

assertSameStrict(true, $group->ProcessNext(), 'slot 4 consumes closed tail');
assertSameStrict(3, count($GLOBALS['asyncScripts']), 'closed windows launched no workers');
assertSameStrict(false, $group->MockRunning(), 'dispatch sequence finished');
assertSameStrict(0, $group->timers['SequenceTimer'], 'timer off after dispatch sequence');

// Workertexte müssen die richtigen Geräte adressieren.
assertTrueStrict(str_contains($GLOBALS['asyncScripts'][0], 'KLF200_ShutterMoveDown(201)'), 'worker 1 KLF target');
assertTrueStrict(str_contains($GLOBALS['asyncScripts'][1], 'LCW_Close(101)'), 'worker 2 WC target');
assertTrueStrict(str_contains($GLOBALS['asyncScripts'][2], 'LCW_Close(103)'), 'worker 3 kitchen target');

// Simuliere die getrennten Script-Kontexte: alle drei Befehle erreichen ihre Hardwarefunktion.
foreach ($GLOBALS['asyncScripts'] as $script) {
    eval($script);
}
assertSameStrict([['klf', 201], ['lcn', 101], ['lcn', 103]], $GLOBALS['commands'], 'all three async workers execute');
assertSameStrict(3, count($GLOBALS['workerResults']), 'all workers reported result');

// Zentral-ZU kennt beim KLF die Zielrichtung. Sobald der echte RunStatus läuft,
// muss die Gruppenanzeige FÄHRT ZU zeigen.
$GLOBALS['variables'][311]['value'] = true;
$group->MessageSink(time(), 311, 10603, [true, true, true]);
$payload = json_decode((string) end($group->visualPayloads), true);
assertSameStrict('FÄHRT ZU', $payload['members'][0]['statusText'] ?? '', 'central KLF closing status');

// Nach Ende der Fahrt: echter RunStatus false + MAIN=100% => ZU.
$GLOBALS['variables'][301]['value'] = 51200;
$group->MessageSink(time(), 301, 10603, [51200, true, true]);
$GLOBALS['variables'][311]['value'] = false;
$group->MessageSink(time(), 311, 10603, [false, true, true]);
$payload = json_decode((string) end($group->visualPayloads), true);
assertSameStrict('ZU', $payload['members'][0]['statusText'] ?? '', 'KLF finished closed');

// Externe KLF-Fahrt ohne sichere Richtung: niemals erfinden, aber LÄUFT anzeigen.
$GLOBALS['variables'][301]['value'] = 20000;
$group->MessageSink(time(), 301, 10603, [20000, true, true]);
$GLOBALS['variables'][311]['value'] = true;
$group->MessageSink(time(), 311, 10603, [true, true, true]);
$payload = json_decode((string) end($group->visualPayloads), true);
assertSameStrict('LÄUFT', $payload['members'][0]['statusText'] ?? '', 'external KLF unknown-direction moving status');
$GLOBALS['variables'][311]['value'] = false;
$group->MessageSink(time(), 311, 10603, [false, true, true]);

// Unsupported instance blocks the whole action.
$group->MockSetProperty('Members', json_encode([['InstanceID' => 999]]));
$group->ApplyChanges();
assertSameStrict(201, $group->moduleStatus, 'unsupported instance status');
$before = count($GLOBALS['asyncScripts']);
assertSameStrict(false, $group->CloseAll(), 'unsupported selection blocked');
assertSameStrict($before, count($GLOBALS['asyncScripts']), 'unsupported selection starts no worker');

// ApplyChanges verwirft noch nicht dispatchte Queue-Slots.
$GLOBALS['lcn_state'][101] = 2;
$GLOBALS['variables'][401]['value'] = 2;
$group->MockSetProperty('Members', json_encode([['InstanceID' => 101], ['InstanceID' => 201]]));
$group->ApplyChanges();
assertSameStrict(true, $group->CloseAll(), 'restart test sequence starts');
assertSameStrict(true, $group->MockRunning(), 'restart test running');
$group->ApplyChanges();
assertSameStrict(false, $group->MockRunning(), 'ApplyChanges cancels undispatched queue');
assertSameStrict(0, $group->timers['SequenceTimer'], 'ApplyChanges cancels timer');
assertSameStrict('[]', $group->MockQueue(), 'ApplyChanges clears queue');

echo "OK window_group_runtime_test\n";
