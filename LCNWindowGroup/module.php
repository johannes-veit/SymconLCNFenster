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
    private const KLF200_RUN_IDENT = 'RunStatus';
    private const KLF200_CLOSED_VALUE = 0xC800; // 51200 = 100 % / geschlossen

    private const TIMER_NAME = 'SequenceTimer';
    private const COMMAND_GAP_MS = 1000;

    private const KLF_DIR_NONE = '';
    private const KLF_DIR_OPEN = 'open';
    private const KLF_DIR_CLOSE = 'close';

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('Members', '[]');

        // Queue/Running beschreiben ausschließlich die noch ausstehenden DISPATCH-Slots.
        // Die eigentlichen Hardwarebefehle laufen bewusst in getrennten Script-Kontexten.
        $this->RegisterAttributeString('Queue', '[]');
        $this->RegisterAttributeBoolean('Running', false);
        $this->RegisterAttributeString('LastError', '');

        // Referenzen / MessageSink-Verwaltung.
        $this->RegisterAttributeString('ObservedStatusVariables', '[]');
        $this->RegisterAttributeString('ObservedMemberInstances', '[]');

        // KLF200 besitzt zwar RunStatus, aber keine veröffentlichte Richtungsvariable.
        // Ein Richtungshinweis wird deshalb nur aus sicher bekannten Informationen
        // (eigener ZU-Befehl oder beobachtete Positionsänderung) geführt.
        $this->RegisterAttributeString('KLFDirectionHints', '{}');
        $this->RegisterAttributeString('KLFLastPositions', '{}');

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

        // Nach Update/Neustart niemals alte Richtungsannahmen fortschreiben.
        $this->WriteAttributeString('KLFDirectionHints', '{}');

        $this->DetachMessages();
        $this->ResetReferences();

        $validation = $this->BuildValidatedMembers();
        $observedStatus = [];
        $observedMembers = [];
        $lastPositions = [];

        foreach ($validation['members'] as $member) {
            $instanceID = (int) $member['instanceID'];

            $this->RegisterReference($instanceID);
            $observedMembers[] = $instanceID;
            try {
                $this->RegisterMessage($instanceID, self::MSG_OBJECT_NAME_CHANGED);
            } catch (Throwable $e) {
                $this->SendDebug('ApplyChanges', sprintf('Namensbeobachtung #%d: %s', $instanceID, $e->getMessage()), 0);
            }

            foreach ($this->GetObserveIDs($member) as $variableID) {
                if ($variableID <= 0 || !IPS_VariableExists($variableID)) {
                    continue;
                }
                $this->RegisterReference($variableID);
                $observedStatus[] = $variableID;
                try {
                    $this->RegisterMessage($variableID, self::MSG_VARIABLE_UPDATE);
                } catch (Throwable $e) {
                    $this->SendDebug('ApplyChanges', sprintf('Statusbeobachtung #%d: %s', $variableID, $e->getMessage()), 0);
                }
            }

            if ($member['type'] === 'klf200') {
                try {
                    $lastPositions[(string) $instanceID] = (int) GetValue((int) $member['positionID']);
                } catch (Throwable) {
                    // Ohne Startwert bleibt die Richtungsanzeige bei externer Fahrt bewusst "LÄUFT".
                }
            }
        }

        $this->WriteAttributeString('ObservedStatusVariables', $this->EncodeIDs($observedStatus));
        $this->WriteAttributeString('ObservedMemberInstances', $this->EncodeIDs($observedMembers));
        $this->WriteJsonMap('KLFLastPositions', $lastPositions);

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
        if (!in_array($SenderID, $observedStatus, true)) {
            return;
        }

        // KLF200-Laufstatus/Richtung nachführen, ohne seine eigenen Variablen zu verändern.
        $this->UpdateKLFTrackingFromSender($SenderID);
        $this->PublishVisualizationState();
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
     * V0.2.2:
     * Der Modultimer führt KEINEN Hardwarebefehl mehr synchron aus. Er startet pro
     * 1-s-Slot lediglich einen eigenen IPS_RunScriptText()-Worker und kehrt sofort
     * zurück. Ein KLF200-Node darf dadurch intern bis zur Fertigmeldung warten,
     * ohne den nächsten LCN-Fensterbefehl zu blockieren.
     */
    public function CloseAll(): bool
    {
        if ($this->ReadAttributeBoolean('Running')) {
            // Kein zweiter paralleler Zentral-Lauf.
            return true;
        }

        $validation = $this->BuildValidatedMembers();
        $this->ApplyValidationStatus($validation);
        if (!$validation['valid'] || $validation['members'] === []) {
            $this->SetLastError($validation['error'] !== '' ? $validation['error'] : 'Keine gültigen Fenster ausgewählt.');
            $this->PublishVisualizationState();
            return false;
        }

        if (!$this->FunctionAvailable('IPS_RunScriptText')) {
            $this->SetLastError('IPS_RunScriptText ist nicht verfügbar. Zentral-ZU wurde nicht gestartet.');
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

        // Erster Slot nach 1 s, danach durchgehend 1 s Abstand.
        $this->SetTimerInterval(self::TIMER_NAME, self::COMMAND_GAP_MS);
        $this->PublishVisualizationState();

        return true;
    }

    /**
     * Sehr kurzer Timer-Callback. Er darf niemals auf ein Fenster warten.
     * Pro Slot wird maximal EIN tatsächlich erforderlicher Worker gestartet.
     */
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
                if ($this->IsAlreadyClosed($member)) {
                    $this->SendDebug('Zentral ZU', sprintf('#%d %s bereits geschlossen/auf ZU unterwegs - übersprungen.', $instanceID, IPS_GetName($instanceID)), 0);
                    continue;
                }

                // Für KLF200 ist die Zielrichtung jetzt sicher bekannt. Der Hint wird
                // erst sichtbar als FÄHRT ZU, sobald dessen echter RunStatus aktiv ist.
                if ($member['type'] === 'klf200') {
                    $this->SetKLFDirectionHint($instanceID, self::KLF_DIR_CLOSE);
                }

                $launched = $this->LaunchCloseWorker($member);
                if (!$launched) {
                    $stepSuccessful = false;
                    $this->SetLastError(sprintf('Schließworker für #%d %s konnte nicht gestartet werden.', $instanceID, IPS_GetName($instanceID)));
                    if ($member['type'] === 'klf200') {
                        $this->SetKLFDirectionHint($instanceID, self::KLF_DIR_NONE);
                    }
                } else {
                    $this->SendDebug('Zentral ZU', sprintf('#%d %s: asynchronen Schließworker gestartet.', $instanceID, IPS_GetName($instanceID)), 0);
                }
            } catch (Throwable $e) {
                $stepSuccessful = false;
                $this->SetLastError(sprintf('#%d %s: %s', $instanceID, IPS_GetName($instanceID), $e->getMessage()));
            }

            if ($queue === []) {
                $this->FinishSequence();
            }
            $this->PublishVisualizationState();
            return $stepSuccessful;
        }

        // Rest bestand nur aus bereits geschlossenen Fenstern.
        $this->FinishSequence();
        $this->PublishVisualizationState();
        return $stepSuccessful;
    }

    /**
     * Rückmeldung eines asynchronen Hardware-Workers.
     * Diese Methode führt selbst keinerlei Hardwareaktion aus.
     */
    public function WorkerResult(int $MemberID, bool $Success, string $Message = ''): void
    {
        $name = IPS_InstanceExists($MemberID) ? IPS_GetName($MemberID) : ('#' . $MemberID);

        if (!$Success) {
            $text = trim($Message) !== '' ? trim($Message) : 'Hardwarebefehl lieferte FALSE.';
            $member = $this->ResolveMember($MemberID);
            if ($member !== null && $member['type'] === 'klf200') {
                $this->SetKLFDirectionHint($MemberID, self::KLF_DIR_NONE);
            }
            $this->SetLastError(sprintf('#%d %s: %s', $MemberID, $name, $text));
        } else {
            $this->SendDebug('Zentral ZU Worker', sprintf('#%d %s: Befehl bestätigt.', $MemberID, $name), 0);
        }

        $this->PublishVisualizationState();
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
                'positionID' => 0,
                'runStatusID' => 0
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
                'positionID' => $positionID,
                // RunStatus ist bei KLF200-Node regulär vorhanden. Optional behandeln,
                // damit ein älterer Sonderstand nicht die gesamte Gruppe unbrauchbar macht.
                'runStatusID' => $this->FindKLF200RunStatusVariable($InstanceID)
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

    private function FindKLF200RunStatusVariable(int $InstanceID): int
    {
        $variableID = @IPS_GetObjectIDByIdent(self::KLF200_RUN_IDENT, $InstanceID);
        if (!is_int($variableID) || $variableID <= 0 || !IPS_VariableExists($variableID)) {
            return 0;
        }

        try {
            $variable = IPS_GetVariable($variableID);
            return (int) ($variable['VariableType'] ?? -1) === 0 ? $variableID : 0;
        } catch (Throwable) {
            return 0;
        }
    }

    private function GetObserveIDs(array $Member): array
    {
        $ids = [];
        foreach (['statusID', 'runStatusID'] as $key) {
            $id = (int) ($Member[$key] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        return array_values(array_unique($ids));
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
                if ((int) GetValue((int) $Member['positionID']) >= self::KLF200_CLOSED_VALUE) {
                    return true;
                }

                $runStatusID = (int) ($Member['runStatusID'] ?? 0);
                if ($runStatusID > 0 && (bool) GetValue($runStatusID)) {
                    return $this->GetKLFDirectionHint((int) $Member['instanceID']) === self::KLF_DIR_CLOSE;
                }
            } catch (Throwable) {
                return false;
            }
        }

        return false;
    }

    /**
     * Startet genau EINEN Hardwareworker. Der Worker läuft parallel zum Modultimer.
     */
    private function LaunchCloseWorker(array $Member): bool
    {
        $instanceID = (int) $Member['instanceID'];
        $groupID = $this->InstanceID;

        if ($Member['type'] === 'lcn') {
            // Vor dem realen Senden nochmals den aktuellen Einzelmodulzustand prüfen.
            $script = sprintf(
                '$ok=true;$msg="";try{$state=(int)LCW_GetWindowState(%1$d);if($state!==%2$d&&$state!==%3$d){$ok=(bool)LCW_Close(%1$d);if(!$ok){$msg="LCW_Close lieferte FALSE.";}}}catch(Throwable $e){$ok=false;$msg=$e->getMessage();}try{LCWG_WorkerResult(%4$d,%1$d,$ok,$msg);}catch(Throwable $e){IPS_LogMessage("LCN Fenster Zentral ZU","WorkerResult #%1$d: ".$e->getMessage());}',
                $instanceID,
                self::LCN_STATE_CLOSED,
                self::LCN_STATE_MOVING_CLOSE,
                $groupID
            );
        } elseif ($Member['type'] === 'klf200') {
            $positionID = (int) $Member['positionID'];
            // Der native KLF200-Schließbefehl wird beibehalten. Falls die KLF200-
            // Instanz "Auf Zustand warten" aktiviert hat, blockiert ausschließlich
            // DIESER Worker und niemals den 1-s-Gruppentimer.
            $script = sprintf(
                '$ok=true;$msg="";try{if(!IPS_VariableExists(%2$d)||(int)GetValue(%2$d)<%3$d){$ok=(bool)KLF200_ShutterMoveDown(%1$d);if(!$ok){$msg="KLF200_ShutterMoveDown lieferte FALSE.";}}}catch(Throwable $e){$ok=false;$msg=$e->getMessage();}try{LCWG_WorkerResult(%4$d,%1$d,$ok,$msg);}catch(Throwable $e){IPS_LogMessage("LCN Fenster Zentral ZU","WorkerResult #%1$d: ".$e->getMessage());}',
                $instanceID,
                $positionID,
                self::KLF200_CLOSED_VALUE,
                $groupID
            );
        } else {
            return false;
        }

        try {
            return (bool) IPS_RunScriptText($script);
        } catch (Throwable $e) {
            $this->SendDebug('Workerstart', sprintf('#%d: %s', $instanceID, $e->getMessage()), 0);
            return false;
        }
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
                    $runStatusID = (int) ($member['runStatusID'] ?? 0);
                    $running = $runStatusID > 0 && (bool) GetValue($runStatusID);

                    if ($running) {
                        $direction = $this->GetKLFDirectionHint($instanceID);
                        if ($direction === self::KLF_DIR_CLOSE) {
                            $statusText = 'FÄHRT ZU';
                        } elseif ($direction === self::KLF_DIR_OPEN) {
                            $statusText = 'FÄHRT AUF';
                        } else {
                            // Das KLF200-Modul veröffentlicht RunStatus, aber keine
                            // eigene Fahrtrichtung. Ohne sichere Richtung nichts erfinden.
                            $statusText = 'LÄUFT';
                        }
                        $statusClass = 'moving';
                    } elseif ($position >= self::KLF200_CLOSED_VALUE) {
                        $statusText = 'ZU';
                        $statusClass = 'closed';
                    } else {
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

    /**
     * Nutzt die echten KLF200-Variablen nur lesend.
     * RunStatus=true ohne bekannte Richtung => LÄUFT.
     * Eine beobachtbare Positionsänderung kann die Richtung sicher ergänzen.
     */
    private function UpdateKLFTrackingFromSender(int $SenderID): void
    {
        $validation = $this->BuildValidatedMembers();
        foreach ($validation['members'] as $member) {
            if ($member['type'] !== 'klf200') {
                continue;
            }

            $instanceID = (int) $member['instanceID'];
            $positionID = (int) $member['positionID'];
            $runStatusID = (int) ($member['runStatusID'] ?? 0);

            if ($SenderID === $positionID) {
                try {
                    $position = (int) GetValue($positionID);
                    $lastPositions = $this->ReadJsonMap('KLFLastPositions');
                    $key = (string) $instanceID;
                    $old = array_key_exists($key, $lastPositions) ? (int) $lastPositions[$key] : null;
                    $running = $runStatusID > 0 && (bool) GetValue($runStatusID);

                    if ($running && $old !== null && $position !== $old) {
                        $this->SetKLFDirectionHint(
                            $instanceID,
                            $position > $old ? self::KLF_DIR_CLOSE : self::KLF_DIR_OPEN
                        );
                    }

                    $lastPositions[$key] = $position;
                    $this->WriteJsonMap('KLFLastPositions', $lastPositions);
                } catch (Throwable) {
                    // Darstellung bleibt beim letzten sicheren Zustand.
                }
            }

            if ($runStatusID > 0 && $SenderID === $runStatusID) {
                try {
                    $running = (bool) GetValue($runStatusID);
                    if (!$running) {
                        $this->SetKLFDirectionHint($instanceID, self::KLF_DIR_NONE);
                        $lastPositions = $this->ReadJsonMap('KLFLastPositions');
                        $lastPositions[(string) $instanceID] = (int) GetValue($positionID);
                        $this->WriteJsonMap('KLFLastPositions', $lastPositions);
                    }
                } catch (Throwable) {
                    // Keine künstliche Zustandsänderung.
                }
            }
        }
    }

    private function GetKLFDirectionHint(int $InstanceID): string
    {
        $map = $this->ReadJsonMap('KLFDirectionHints');
        $value = (string) ($map[(string) $InstanceID] ?? self::KLF_DIR_NONE);
        return in_array($value, [self::KLF_DIR_OPEN, self::KLF_DIR_CLOSE], true) ? $value : self::KLF_DIR_NONE;
    }

    private function SetKLFDirectionHint(int $InstanceID, string $Direction): void
    {
        $map = $this->ReadJsonMap('KLFDirectionHints');
        $key = (string) $InstanceID;

        if (!in_array($Direction, [self::KLF_DIR_OPEN, self::KLF_DIR_CLOSE], true)) {
            unset($map[$key]);
        } else {
            $map[$key] = $Direction;
        }

        $this->WriteJsonMap('KLFDirectionHints', $map);
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
        $this->SetSummary(sprintf('%d Fenster · 1 s Abstand · async', count($Validation['members'])));
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

    private function FunctionAvailable(string $Name): bool
    {
        if (function_exists($Name)) {
            return true;
        }
        try {
            return function_exists('IPS_FunctionExists') && (bool) IPS_FunctionExists($Name);
        } catch (Throwable) {
            return false;
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

    private function ReadJsonMap(string $Attribute): array
    {
        $decoded = json_decode($this->ReadAttributeString($Attribute), true);
        return is_array($decoded) ? $decoded : [];
    }

    private function WriteJsonMap(string $Attribute, array $Map): void
    {
        $encoded = json_encode($Map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->WriteAttributeString($Attribute, $encoded === false ? '{}' : $encoded);
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
