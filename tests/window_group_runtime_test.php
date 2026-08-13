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
    301 => ['type' => 1, 'value' => 0],      // KLF offen
    302 => ['type' => 1, 'value' => 51200],  // KLF zu
    401 => ['type' => 1, 'value' => 2],      // LCN Status offen
    402 => ['type' => 1, 'value' => 1],      // LCN Status zu
    403 => ['type' => 1, 'value' => 2],      // LCN Status offen
];
$GLOBALS['idents'] = [
    101 => ['Status' => 401],
    102 => ['Status' => 402],
    103 => ['Status' => 403],
    201 => ['MAIN' => 301],
    202 => ['MAIN' => 302],
];
$GLOBALS['lcn_state'] = [101 => 2, 102 => 1, 103 => 2];
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
function assertTrueStrict(bool $actual, string $label): void
{
    assertSameStrict(true, $actual, $label);
}

$group = new LCNWindowGroup();
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
assertSameStrict('5 Fenster · 1 s Abstand', $group->summary, 'summary');
assertSameStrict(0, $group->timers['SequenceTimer'], 'ApplyChanges timer off');
assertSameStrict([], $GLOBALS['commands'], 'ApplyChanges sends no hardware command');

// Statusbeobachtung muss für LCN-Status und KLF-MAIN registriert sein.
$messages = $group->MockMessages();
foreach ([301, 302, 401, 402, 403] as $statusID) {
    assertTrueStrict(isset($messages[$statusID][10603]), 'status message registered #' . $statusID);
}

// Visu-Zustand enthält die Mitgliederliste mit realen Zuständen.
$initialPayload = json_decode((string) end($group->visualPayloads), true);
assertSameStrict(5, count($initialPayload['members'] ?? []), 'five status list members');
assertSameStrict('Dachfenster Schlafzimmer', $initialPayload['members'][0]['name'] ?? '', 'first member name');
assertSameStrict('AUF', $initialPayload['members'][0]['statusText'] ?? '', 'open KLF status');
assertSameStrict('AUF', $initialPayload['members'][1]['statusText'] ?? '', 'open LCN status');

// CloseAll legt ALLE Mitglieder in die Queue und startet einen dauerhaft aktiven 1-s-Timer.
assertSameStrict(true, $group->CloseAll(), 'CloseAll starts');
assertSameStrict([], $GLOBALS['commands'], 'click itself sends no hardware command');
assertSameStrict(true, $group->MockRunning(), 'sequence running after click');
assertSameStrict(1000, $group->timers['SequenceTimer'], 'persistent 1s timer armed');
assertSameStrict([201, 101, 103, 102, 202], json_decode($group->MockQueue(), true), 'all selected members queued');

// Zweiter Tastendruck während laufender Folge darf keine zweite Queue erzeugen.
assertSameStrict(true, $group->CloseAll(), 'repeated CloseAll accepted as no-op');
assertSameStrict([], $GLOBALS['commands'], 'no duplicate from repeated press');

// Drei tatsächlich notwendige Befehle hintereinander: genau der reale Fehlerfall aus V0.2.0.
assertSameStrict(true, $group->ProcessNext(), 'slot 1');
assertSameStrict([['klf', 201]], $GLOBALS['commands'], 'slot 1 closes roof window');
assertSameStrict(1000, $group->timers['SequenceTimer'], 'timer stays continuously armed after slot 1');
assertSameStrict(true, $group->MockRunning(), 'still running after slot 1');

assertSameStrict(true, $group->ProcessNext(), 'slot 2');
assertSameStrict([['klf', 201], ['lcn', 101]], $GLOBALS['commands'], 'slot 2 closes WC');
assertSameStrict(1000, $group->timers['SequenceTimer'], 'timer stays continuously armed after slot 2');
assertSameStrict(true, $group->MockRunning(), 'still running after slot 2');

assertSameStrict(true, $group->ProcessNext(), 'slot 3');
assertSameStrict([['klf', 201], ['lcn', 101], ['lcn', 103]], $GLOBALS['commands'], 'slot 3 closes kitchen');
assertSameStrict(1000, $group->timers['SequenceTimer'], 'timer still armed until trailing closed members are consumed');
assertSameStrict(true, $group->MockRunning(), 'still running before closed tail is consumed');

// Nächster Slot überspringt beide bereits geschlossenen Fenster und beendet die Folge.
assertSameStrict(true, $group->ProcessNext(), 'slot 4 consumes closed tail');
assertSameStrict([['klf', 201], ['lcn', 101], ['lcn', 103]], $GLOBALS['commands'], 'no commands for already closed tail');
assertSameStrict(false, $group->MockRunning(), 'sequence finished');
assertSameStrict(0, $group->timers['SequenceTimer'], 'timer off only at sequence finish');
assertSameStrict('[]', $group->MockQueue(), 'queue empty after finish');

// Ein beim Klick noch geschlossenes Fenster muss trotzdem in der Queue bleiben.
// Öffnet es vor seinem Slot, muss Zentral-ZU es sicher erfassen.
$GLOBALS['commands'] = [];
$GLOBALS['lcn_state'][103] = 1;
$GLOBALS['variables'][403]['value'] = 1;
$group->MockSetProperty('Members', json_encode([['InstanceID' => 103]]));
$group->ApplyChanges();
assertSameStrict(true, $group->CloseAll(), 'late-state sequence starts');
assertSameStrict([103], json_decode($group->MockQueue(), true), 'closed-at-click member still queued');
$GLOBALS['lcn_state'][103] = 2;
$GLOBALS['variables'][403]['value'] = 2;
assertSameStrict(true, $group->ProcessNext(), 'late-open member processed');
assertSameStrict([['lcn', 103]], $GLOBALS['commands'], 'late-open member receives close command');
assertSameStrict(false, $group->MockRunning(), 'single-member sequence finishes');

// Statusänderung muss die Visualisierung aktualisieren, ohne einen Hardwarebefehl zu senden.
$beforePayloads = count($group->visualPayloads);
$beforeCommands = count($GLOBALS['commands']);
$group->MessageSink(time(), 403, 10603, [1, true, true]);
assertSameStrict($beforePayloads + 1, count($group->visualPayloads), 'status MessageSink refreshes visualization');
assertSameStrict($beforeCommands, count($GLOBALS['commands']), 'status refresh sends no hardware command');

// Unsupported instance remains a hard configuration error.
$group->MockSetProperty('Members', json_encode([['InstanceID' => 999]]));
$group->ApplyChanges();
assertSameStrict(201, $group->moduleStatus, 'unsupported instance status');
$before = count($GLOBALS['commands']);
assertSameStrict(false, $group->CloseAll(), 'unsupported selection blocked');
assertSameStrict($before, count($GLOBALS['commands']), 'unsupported selection sends nothing');

// ApplyChanges während einer Sequenz muss Queue/Timer verwerfen und niemals fortsetzen.
$GLOBALS['lcn_state'][101] = 2;
$GLOBALS['variables'][401]['value'] = 2;
$group->MockSetProperty('Members', json_encode([['InstanceID' => 101], ['InstanceID' => 201]]));
$group->ApplyChanges();
assertSameStrict(true, $group->CloseAll(), 'restart test sequence starts');
assertSameStrict(true, $group->MockRunning(), 'restart test running');
$group->ApplyChanges();
assertSameStrict(false, $group->MockRunning(), 'ApplyChanges cancels running sequence');
assertSameStrict(0, $group->timers['SequenceTimer'], 'ApplyChanges cancels timer');
assertSameStrict('[]', $group->MockQueue(), 'ApplyChanges clears queue');

echo "OK window_group_runtime_test\n";
