<?php

declare(strict_types=1);

class LCNWindowGroup extends IPSModuleStrict
{
    private const STATUS_ACTIVE = 102;
    private const STATUS_INACTIVE = 104;
    private const STATUS_INVALID_MEMBER = 201;

    private const MSG_VARIABLE_UPDATE = 10603;
    private const MSG_OBJECT_NAME_CHANGED = 10404;

    private const LCN_WINDOW_MODULE_ID = '{7AA3FC56-5CEC-4C42-9AF3-42DB2084772D}';
    private const KLF200_NODE_MODULE_ID = '{4EBD07B1-2962-4531-AC5F-7944789A9CE5}';

    private const LCN_STATE_UNKNOWN = 0;
    private const LCN_STATE_CLOSED = 1;
    private const LCN_STATE_OPEN = 2;
    private const LCN_STATE_MOVING_CLOSE = 3;
    private const LCN_STATE_MOVING_OPEN = 4;
    private const LCN_STATE_FAULT = 5;

    private const LCN_STATUS_IDENT = 'Status';
    private const KLF200_MAIN_IDENT = 'MAIN';
    private const KLF200_CLOSED_VALUE = 0xC800; // 51200 = 100 % / geschlossen

    private const TIMER_NAME = 'SequenceTimer';
    private const COMMAND_GAP_MS = 1000;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('Members', '[]');

        // Queue/Running sind reine Ablaufzustände. ApplyChanges verwirft eine alte
        // Sequenz bewusst, damit ein Update/Neustart niemals Hardwarebefehle fortsetzt.
        $this->RegisterAttributeString('Queue', '[]');
        $this->RegisterAttributeBoolean('Running', false);
        $this->RegisterAttributeString('LastError', '');

        // Nur für saubere Message-Abmeldung bei Konfigurationsänderungen.
        $this->RegisterAttributeString('ObservedStatusVariables', '[]');
        $this->RegisterAttributeString('ObservedMemberInstances', '[]');

        $this->RegisterTimer(self::TIMER_NAME, 0, 'LCWG_ProcessNext($_IPS["TARGET"]);');
        $this->SetVisualizationType(1);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->SetVisualizationType(1);
        $this->SetTimerInterval(self::TIMER_NAME, 0);
        $this->WriteAttributeString('Queue', '[]');
        $this->WriteAttributeBoolean('Running', false);
        $this->ClearLastError();

        $this->DetachMessages();
        $this->ResetReferences();

        $validation = $this->BuildValidatedMembers();
        $observedStatus = [];
        $observedMembers = [];

        foreach ($validation['members'] as $member) {
            $instanceID = (int) $member['instanceID'];
            $statusID = (int) $member['statusID'];

            $this->RegisterReference($instanceID);
            $observedMembers[] = $instanceID;
            try {
                $this->RegisterMessage($instanceID, self::MSG_OBJECT_NAME_CHANGED);
            } catch (Throwable $e) {
                $this->SendDebug('ApplyChanges', sprintf('Namensbeobachtung #%d: %s', $instanceID, $e->getMessage()), 0);
            }

            if ($statusID > 0 && IPS_VariableExists($statusID)) {
                $this->RegisterReference($statusID);
                $observedStatus[] = $statusID;
                try {
                    $this->RegisterMessage($statusID, self::MSG_VARIABLE_UPDATE);
                } catch (Throwable $e) {
                    $this->SendDebug('ApplyChanges', sprintf('Statusbeobachtung #%d: %s', $statusID, $e->getMessage()), 0);
                }
            }
        }

        $this->WriteAttributeString('ObservedStatusVariables', $this->EncodeIDs($observedStatus));
        $this->WriteAttributeString('ObservedMemberInstances', $this->EncodeIDs($observedMembers));

        $this->ApplyValidationStatus($validation);
        $this->PublishVisualizationState();
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === self::MSG_OBJECT_NAME_CHANGED) {
            $observedMembers = $this->DecodeIDs($this->ReadAttributeString('ObservedMemberInstances'));
            if (in_array($SenderID, $observedMembers, true)) {
                $this->PublishVisualizationState();
            }
            return;
        }

        if ($Message !== self::MSG_VARIABLE_UPDATE) {
            return;
        }

        $observedStatus = $this->DecodeIDs($this->ReadAttributeString('ObservedStatusVariables'));
        if (in_array($SenderID, $observedStatus, true)) {
            $this->PublishVisualizationState();
        }
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        switch ($Ident) {
            case 'CloseAll':
                $this->CloseAll();
                break;

            case 'Validate':
                $this->ValidateSelection();
                break;

            default:
                throw new InvalidArgumentException('Unbekannte Aktion: ' . $Ident);
        }
    }

    /**
     * Startet die Zentral-ZU-Folge.
     *
     * V0.2.1: Die Queue enthält bewusst ALLE ausgewählten Fenster. Der Istzustand
     * wird erst unmittelbar vor dem jeweiligen Slot geprüft. Dadurch kann kein
     * Fenster wegen eines beim Tastendruck kurz veralteten Zustands aus der Queue
     * herausfallen.
     *
     * Der Timer bleibt während der gesamten Folge mit 1000 ms aktiv und wird nicht
     * nach jedem Callback auf 0 gesetzt und neu bewaffnet. Erst FinishSequence()
     * schaltet ihn ab. Damit gibt es keinen Re-Arm-Abbruch nach mehreren Fenstern.
     */
    public function CloseAll(): bool
    {
        if ($this->ReadAttributeBoolean('Running')) {
            // Zweiter Tastendruck während derselben Sequenz erzeugt keine Duplikate.
            return true;
        }

        $validation = $this->BuildValidatedMembers();
        $this->ApplyValidationStatus($validation);
        if (!$validation['valid'] || $validation['members'] === []) {
            $this->SetLastError($validation['error'] !== '' ? $validation['error'] : 'Keine gültigen Fenster ausgewählt.');
            $this->PublishVisualizationState();
            return false;
        }

        $queue = array_map(
            static fn (array $member): int => (int) $member['instanceID'],
            $validation['members']
        );

        $this->WriteAttributeString('Queue', $this->EncodeIDs($queue));
        $this->WriteAttributeBoolean('Running', true);
        $this->ClearLastError();

        // Bewusst ein dauerhaft laufender 1-s-Timer. Der erste Geräteslot liegt
        // nach 1 s; jeder weitere tatsächlich notwendige Befehl folgt frühestens
        // im nächsten Timer-Slot. Geschlossene Fenster werden im Slot übersprungen.
        $this->SetTimerInterval(self::TIMER_NAME, self::COMMAND_GAP_MS);
        $this->PublishVisualizationState();

        return true;
    }

    /** Timer-Callback; kann auch von Regressionstests direkt aufgerufen werden. */
    public function ProcessNext(): bool
    {
        if (!$this->ReadAttributeBoolean('Running')) {
            $this->SetTimerInterval(self::TIMER_NAME, 0);
            return true;
        }

        $queue = $this->DecodeIDs($this->ReadAttributeString('Queue'));
        $stepSuccessful = true;

        while ($queue !== []) {
            $instanceID = array_shift($queue);
            $this->WriteAttributeString('Queue', $this->EncodeIDs($queue));

            $member = $this->ResolveMember($instanceID);
            if ($member === null) {
                $stepSuccessful = false;
                $this->SetLastError(sprintf('Instanz #%d wird nicht mehr als unterstütztes Fenster erkannt.', $instanceID));
                continue;
            }

            try {
                // Zustand erst JETZT prüfen, nicht beim Klick auf den Zentralbutton.
                if ($this->IsAlreadyClosed($member)) {
                    $this->SendDebug('Zentral ZU', sprintf('#%d %s bereits geschlossen - übersprungen.', $instanceID, IPS_GetName($instanceID)), 0);
                    continue;
                }

                $result = $this->SendCloseCommand($member);
                if (!$result) {
                    $stepSuccessful = false;
                    $this->SetLastError(sprintf('Schließbefehl für #%d %s wurde nicht bestätigt.', $instanceID, IPS_GetName($instanceID)));
                } else {
                    $this->SendDebug('Zentral ZU', sprintf('#%d %s: Schließbefehl gesendet.', $instanceID, IPS_GetName($instanceID)), 0);
                }
            } catch (Throwable $e) {
                $stepSuccessful = false;
                $this->SetLastError(sprintf('#%d %s: %s', $instanceID, IPS_GetName($instanceID), $e->getMessage()));
            }

            // Genau ein tatsächlich notwendiger Befehlsversuch pro 1-s-Slot.
            // Der Timer bleibt aktiv; kein Disable/Re-Arm innerhalb des Callbacks.
            if ($queue === []) {
                $this->FinishSequence();
            }
            $this->PublishVisualizationState();
            return $stepSuccessful;
        }

        // Alle noch in diesem Slot betrachteten Fenster waren bereits geschlossen.
        $this->FinishSequence();
        $this->PublishVisualizationState();
        return $stepSuccessful;
    }

    public function ValidateSelection(): bool
    {
        $validation = $this->BuildValidatedMembers();
        $this->ApplyValidationStatus($validation);
        if ($validation['valid']) {
            $this->ClearLastError();
        } else {
            $this->SetLastError($validation['error']);
        }
        $this->PublishVisualizationState();
        return $validation['valid'];
    }

    public function GetVisualizationTile(): string
    {
        $html = @file_get_contents(__DIR__ . '/module.html');
        if ($html === false) {
            return '<div>LCN Fenster Zentral ZU: module.html konnte nicht geladen werden.</div>';
        }

        $initial = json_encode(
            $this->BuildVisualizationState(),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
        if ($initial === false) {
            $initial = '{}';
        }

        return str_replace('%%INITIAL_DATA%%', $initial, $html);
    }

    /** @return array{valid:bool,members:array<int,array>,invalid:array<int,int>,error:string} */
    private function BuildValidatedMembers(): array
    {
        $selected = $this->GetSelectedMemberIDs();
        $members = [];
        $invalid = [];

        foreach ($selected as $instanceID) {
            $member = $this->ResolveMember($instanceID);
            if ($member === null) {
                $invalid[] = $instanceID;
                continue;
            }
            $members[] = $member;
        }

        $error = '';
        if ($invalid !== []) {
            $names = array_map(
                static fn (int $id): string => IPS_InstanceExists($id) ? IPS_GetName($id) . ' (#' . $id . ')' : '#' . $id,
                $invalid
            );
            $error = 'Nicht unterstützte oder unvollständige Auswahl: ' . implode(', ', $names);
        }

        return [
            'valid' => $selected !== [] && $invalid === [] && count($members) === count($selected),
            'members' => $members,
            'invalid' => $invalid,
            'error' => $error
        ];
    }

    private function ResolveMember(int $InstanceID): ?array
    {
        if ($InstanceID <= 0 || !IPS_InstanceExists($InstanceID)) {
            return null;
        }

        try {
            $instance = IPS_GetInstance($InstanceID);
            $moduleID = strtoupper((string) ($instance['ModuleInfo']['ModuleID'] ?? ''));
        } catch (Throwable) {
            return null;
        }

        if ($moduleID === self::LCN_WINDOW_MODULE_ID) {
            $statusID = $this->FindLCNStatusVariable($InstanceID);
            if ($statusID <= 0) {
                return null;
            }
            return [
                'type' => 'lcn',
                'instanceID' => $InstanceID,
                'statusID' => $statusID,
                'positionID' => 0
            ];
        }

        if ($moduleID === self::KLF200_NODE_MODULE_ID) {
            $positionID = $this->FindKLF200MainVariable($InstanceID);
            if ($positionID <= 0) {
                return null;
            }
            return [
                'type' => 'klf200',
                'instanceID' => $InstanceID,
                'statusID' => $positionID,
                'positionID' => $positionID
            ];
        }

        return null;
    }

    private function FindLCNStatusVariable(int $InstanceID): int
    {
        $variableID = @IPS_GetObjectIDByIdent(self::LCN_STATUS_IDENT, $InstanceID);
        if (!is_int($variableID) || $variableID <= 0 || !IPS_VariableExists($variableID)) {
            return 0;
        }

        try {
            $variable = IPS_GetVariable($variableID);
            return (int) ($variable['VariableType'] ?? -1) === 1 ? $variableID : 0;
        } catch (Throwable) {
            return 0;
        }
    }

    private function FindKLF200MainVariable(int $InstanceID): int
    {
        $variableID = @IPS_GetObjectIDByIdent(self::KLF200_MAIN_IDENT, $InstanceID);
        if (!is_int($variableID) || $variableID <= 0 || !IPS_VariableExists($variableID)) {
            return 0;
        }

        try {
            $variable = IPS_GetVariable($variableID);
            return (int) ($variable['VariableType'] ?? -1) === 1 ? $variableID : 0;
        } catch (Throwable) {
            return 0;
        }
    }

    private function IsAlreadyClosed(array $Member): bool
    {
        if ($Member['type'] === 'lcn') {
            try {
                $state = (int) LCW_GetWindowState((int) $Member['instanceID']);
                return $state === self::LCN_STATE_CLOSED || $state === self::LCN_STATE_MOVING_CLOSE;
            } catch (Throwable) {
                // Zustand unbekannt: zur Sicherheit den definierten ZU-Befehl senden.
                return false;
            }
        }

        if ($Member['type'] === 'klf200') {
            try {
                return (int) GetValue((int) $Member['positionID']) >= self::KLF200_CLOSED_VALUE;
            } catch (Throwable) {
                return false;
            }
        }

        return false;
    }

    private function SendCloseCommand(array $Member): bool
    {
        $instanceID = (int) $Member['instanceID'];

        if ($Member['type'] === 'lcn') {
            return (bool) LCW_Close($instanceID);
        }

        if ($Member['type'] === 'klf200') {
            // Nicht SetValue() verwenden: Der echte KLF200-Modulbefehl führt die
            // Hardwareaktion aus. Die KLF200-Rückmeldungen aktualisieren danach
            // dessen Position/Laufstatus und damit die vorhandene Dachfenster-Visu.
            return (bool) KLF200_ShutterMoveDown($instanceID);
        }

        return false;
    }

    private function GetSelectedMemberIDs(): array
    {
        $rows = json_decode($this->ReadPropertyString('Members'), true);
        if (!is_array($rows)) {
            return [];
        }

        $ids = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (int) ($row['InstanceID'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    private function GetMemberInfo(array $Members): array
    {
        $result = [];

        foreach ($Members as $member) {
            $instanceID = (int) $member['instanceID'];
            $statusText = '?';
            $statusClass = 'unknown';

            try {
                if ($member['type'] === 'lcn') {
                    $state = (int) LCW_GetWindowState($instanceID);
                    switch ($state) {
                        case self::LCN_STATE_CLOSED:
                            $statusText = 'ZU';
                            $statusClass = 'closed';
                            break;
                        case self::LCN_STATE_OPEN:
                            $statusText = 'AUF';
                            $statusClass = 'open';
                            break;
                        case self::LCN_STATE_MOVING_CLOSE:
                            $statusText = 'FÄHRT ZU';
                            $statusClass = 'moving';
                            break;
                        case self::LCN_STATE_MOVING_OPEN:
                            $statusText = 'FÄHRT AUF';
                            $statusClass = 'moving';
                            break;
                        case self::LCN_STATE_FAULT:
                            $statusText = 'FEHLER';
                            $statusClass = 'fault';
                            break;
                        default:
                            $statusText = '?';
                            $statusClass = 'unknown';
                    }
                } elseif ($member['type'] === 'klf200') {
                    $position = (int) GetValue((int) $member['positionID']);
                    if ($position >= self::KLF200_CLOSED_VALUE) {
                        $statusText = 'ZU';
                        $statusClass = 'closed';
                    } else {
                        // KLF200 liefert hier die reale Position. Ohne einen separaten,
                        // sicher typisierten Laufstatus zeigen wir bis zur Endlage AUF,
                        // statt vorzeitig ZU zu behaupten.
                        $statusText = 'AUF';
                        $statusClass = 'open';
                    }
                }
            } catch (Throwable $e) {
                $this->SendDebug('Fensterstatus', sprintf('#%d: %s', $instanceID, $e->getMessage()), 0);
            }

            $result[] = [
                'id' => $instanceID,
                'name' => IPS_GetName($instanceID),
                'type' => (string) $member['type'],
                'statusText' => $statusText,
                'statusClass' => $statusClass
            ];
        }

        return $result;
    }

    private function ApplyValidationStatus(array $Validation): void
    {
        $selectedCount = count($this->GetSelectedMemberIDs());
        if ($selectedCount === 0) {
            $this->SetStatus(self::STATUS_INACTIVE);
            $this->SetSummary('0 Fenster');
            return;
        }

        if (!$Validation['valid']) {
            $this->SetStatus(self::STATUS_INVALID_MEMBER);
            $this->SetSummary(sprintf('%d gewählt · Auswahl prüfen', $selectedCount));
            return;
        }

        $this->SetStatus(self::STATUS_ACTIVE);
        $this->SetSummary(sprintf('%d Fenster · 1 s Abstand', count($Validation['members'])));
    }

    private function FinishSequence(): void
    {
        $this->SetTimerInterval(self::TIMER_NAME, 0);
        $this->WriteAttributeString('Queue', '[]');
        $this->WriteAttributeBoolean('Running', false);
    }

    private function BuildVisualizationState(): array
    {
        $validation = $this->BuildValidatedMembers();
        return [
            'configured' => $validation['valid'],
            'memberCount' => count($validation['members']),
            'running' => $this->ReadAttributeBoolean('Running'),
            'errorText' => $this->ReadAttributeString('LastError'),
            'members' => $this->GetMemberInfo($validation['members'])
        ];
    }

    private function PublishVisualizationState(): void
    {
        $payload = json_encode($this->BuildVisualizationState(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            return;
        }
        try {
            $this->UpdateVisualizationValue($payload);
        } catch (Throwable $e) {
            $this->SendDebug('Visualisierung', 'UpdateVisualizationValue: ' . $e->getMessage(), 0);
        }
    }

    private function DetachMessages(): void
    {
        foreach ($this->DecodeIDs($this->ReadAttributeString('ObservedStatusVariables')) as $variableID) {
            try {
                $this->UnregisterMessage($variableID, self::MSG_VARIABLE_UPDATE);
            } catch (Throwable) {
                // Ziel oder alte Registrierung existiert nicht mehr.
            }
        }

        foreach ($this->DecodeIDs($this->ReadAttributeString('ObservedMemberInstances')) as $instanceID) {
            try {
                $this->UnregisterMessage($instanceID, self::MSG_OBJECT_NAME_CHANGED);
            } catch (Throwable) {
                // Ziel oder alte Registrierung existiert nicht mehr.
            }
        }

        $this->WriteAttributeString('ObservedStatusVariables', '[]');
        $this->WriteAttributeString('ObservedMemberInstances', '[]');
    }

    private function ResetReferences(): void
    {
        foreach ($this->GetReferenceList() as $referenceID) {
            try {
                $this->UnregisterReference($referenceID);
            } catch (Throwable) {
                // Referenz wurde zusammen mit dem Ziel bereits entfernt.
            }
        }
    }

    private function EncodeIDs(array $IDs): string
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $IDs), static fn (int $id): bool => $id > 0)));
        $encoded = json_encode($ids);
        return $encoded === false ? '[]' : $encoded;
    }

    private function DecodeIDs(string $JSON): array
    {
        $decoded = json_decode($JSON, true);
        if (!is_array($decoded)) {
            return [];
        }
        return array_values(array_unique(array_filter(array_map('intval', $decoded), static fn (int $id): bool => $id > 0)));
    }

    private function SetLastError(string $Text): void
    {
        $this->WriteAttributeString('LastError', $Text);
        $this->SendDebug('Fehler', $Text, 0);
    }

    private function ClearLastError(): void
    {
        if ($this->ReadAttributeString('LastError') !== '') {
            $this->WriteAttributeString('LastError', '');
        }
    }
}
