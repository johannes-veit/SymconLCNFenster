<?php

declare(strict_types=1);

class LCNWindow extends IPSModuleStrict
{
    private const STATUS_ACTIVE = 102;
    private const STATUS_INACTIVE = 104;
    private const STATUS_INVALID_FEEDBACK = 201;
    private const STATUS_SAME_FEEDBACK = 202;
    private const STATUS_RELAY_CONFLICT = 203;
    private const STATUS_LCN_UNAVAILABLE = 204;

    private const MSG_VARIABLE_UPDATE = 10603; // VM_UPDATE

    private const STATE_UNKNOWN = 0;
    private const STATE_CLOSED = 1;
    private const STATE_OPEN = 2;
    private const STATE_MOVING_CLOSE = 3;
    private const STATE_MOVING_OPEN = 4;
    private const STATE_FAULT = 5;

    private const TS_HIT = 'K';
    private const TS_MAKE = 'L';
    private const TS_DONT_SEND = '-';

    private const SEND_SEMAPHORE = 'LCNWindowControl.TS';
    private const SEND_GAP_MS = 100;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyInteger('SendModule', 0);
        $this->RegisterPropertyString('Table', 'A');
        $this->RegisterPropertyInteger('Key', 1);
        $this->RegisterPropertyInteger('RelayOpenVariable', 0);
        $this->RegisterPropertyInteger('RelayCloseVariable', 0);

        // StableState is deliberately persistent. It is not reset merely because
        // both relays are OFF after LCN has completed a movement.
        $this->RegisterAttributeInteger('StableState', self::STATE_UNKNOWN);
        $this->RegisterAttributeInteger('LastMotion', self::STATE_UNKNOWN);
        $this->RegisterAttributeBoolean('RuntimeReady', false);
        $this->RegisterAttributeString('LastError', '');
        $this->RegisterAttributeInteger('ActiveRelayOpenVariable', 0);
        $this->RegisterAttributeInteger('ActiveRelayCloseVariable', 0);

        $this->EnsureStatusProfile();
        $this->EnsureVariables();
        $this->SetVisualizationType(1);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        // Prevent a command from being emitted while references/messages are being
        // rebuilt. No hardware command is sent from ApplyChanges itself.
        $this->WriteAttributeBoolean('RuntimeReady', false);
        $this->SetVisualizationType(1);
        $this->EnsureStatusProfile();
        $this->EnsureVariables();

        $this->DetachRelayMessages();
        $this->ResetReferences();

        $sendModule = $this->ReadPropertyInteger('SendModule');
        $relayOpen = $this->ReadPropertyInteger('RelayOpenVariable');
        $relayClose = $this->ReadPropertyInteger('RelayCloseVariable');

        if ($sendModule > 0 && IPS_InstanceExists($sendModule)) {
            $this->RegisterReference($sendModule);
        }

        foreach (array_unique([$relayOpen, $relayClose]) as $variableID) {
            if ($variableID > 0 && IPS_VariableExists($variableID)) {
                $this->RegisterReference($variableID);
                $this->RegisterMessage($variableID, self::MSG_VARIABLE_UPDATE);
            }
        }
        $this->WriteAttributeInteger('ActiveRelayOpenVariable', $relayOpen > 0 && IPS_VariableExists($relayOpen) ? $relayOpen : 0);
        $this->WriteAttributeInteger('ActiveRelayCloseVariable', $relayClose > 0 && IPS_VariableExists($relayClose) ? $relayClose : 0);

        $this->UpdateSummary();
        $valid = $this->UpdateModuleStatus();
        $this->WriteAttributeBoolean('RuntimeReady', $valid);

        // Adopt the *current* relay state once. If both relays are OFF, the
        // persistent StableState remains untouched across restart/update.
        $this->SyncRelays();
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message !== self::MSG_VARIABLE_UPDATE) {
            return;
        }

        $relayOpen = $this->ReadPropertyInteger('RelayOpenVariable');
        $relayClose = $this->ReadPropertyInteger('RelayCloseVariable');
        if ($SenderID !== $relayOpen && $SenderID !== $relayClose) {
            return;
        }

        // VM_UPDATE = [new value, changed, old value, timestamp]. Ignore unchanged
        // refresh messages so an old/repeated relay value cannot generate a false
        // state transition after an action or update.
        if (array_key_exists(1, $Data) && $Data[1] === false) {
            $this->SendDebug('RelayFeedback', 'Unveränderte Relais-Wiederholung ignoriert.', 0);
            return;
        }

        $this->SyncRelays();
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        switch ($Ident) {
            case 'Open':
                if (!$this->Open()) {
                    throw new RuntimeException('Fenster-AUF konnte nicht an LCN gesendet werden.');
                }
                break;

            case 'Close':
                if (!$this->Close()) {
                    throw new RuntimeException('Fenster-ZU konnte nicht an LCN gesendet werden.');
                }
                break;

            case 'Sync':
                $this->SyncRelays();
                break;

            default:
                throw new InvalidArgumentException('Unbekannte Aktion: ' . $Ident);
        }
    }

    /** Sends the configured GT8 LONG/MAKE event. LCN remains responsible for relays. */
    public function Open(): bool
    {
        if (!$this->CanSendDirection(self::STATE_OPEN)) {
            return false;
        }
        if ($this->IsAlreadyAtOrMovingTo(self::STATE_OPEN)) {
            return true;
        }
        return $this->SendKey(self::TS_MAKE, 'AUF / LANG', self::STATE_OPEN);
    }

    /** Sends the configured GT8 SHORT/HIT event. LCN remains responsible for relays. */
    public function Close(): bool
    {
        if (!$this->CanSendDirection(self::STATE_CLOSED)) {
            return false;
        }
        if ($this->IsAlreadyAtOrMovingTo(self::STATE_CLOSED)) {
            return true;
        }
        return $this->SendKey(self::TS_HIT, 'ZU / KURZ', self::STATE_CLOSED);
    }

    /** Re-reads both configured relay variables; never sends a bus command. */
    public function SyncRelays(bool $Publish = true): bool
    {
        $relayOpenID = $this->ReadPropertyInteger('RelayOpenVariable');
        $relayCloseID = $this->ReadPropertyInteger('RelayCloseVariable');
        if (!$this->IsBooleanVariable($relayOpenID) || !$this->IsBooleanVariable($relayCloseID) || $relayOpenID === $relayCloseID) {
            $this->WriteAttributeBoolean('RuntimeReady', false);
            $this->UpdateModuleStatus();
            $this->SetWindowState(self::STATE_UNKNOWN);
            if ($Publish) {
                $this->PublishVisualizationState();
            }
            return false;
        }

        try {
            $open = (bool) GetValue($relayOpenID);
            $close = (bool) GetValue($relayCloseID);
        } catch (Throwable $e) {
            $this->SetLastError('Relais-Rückmeldung konnte nicht gelesen werden: ' . $e->getMessage());
            $this->SetWindowState(self::STATE_UNKNOWN);
            if ($Publish) {
                $this->PublishVisualizationState();
            }
            return false;
        }

        $this->ProcessRelayState($open, $close, $Publish);
        return true;
    }

    public function GetWindowState(): int
    {
        return $this->GetCurrentState();
    }

    public function GetStableState(): int
    {
        return $this->ReadAttributeInteger('StableState');
    }

    public function GetVisualizationTile(): string
    {
        $html = @file_get_contents(__DIR__ . '/module.html');
        if ($html === false) {
            return '<div>LCN Fenster: module.html konnte nicht geladen werden.</div>';
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

    private function ProcessRelayState(bool $Open, bool $Close, bool $Publish = true): void
    {
        $resolved = $this->ResolveStateFromRelays($Open, $Close, $this->ReadAttributeInteger('StableState'));

        if ($resolved['state'] === self::STATE_FAULT) {
            $this->SetLastError('Beide Richtungsrelais melden gleichzeitig EIN. Es wird kein Fahrbefehl gesendet.');
            $this->SetWindowState(self::STATE_FAULT);
            $this->WriteAttributeBoolean('RuntimeReady', false);
            $this->SetStatus(self::STATUS_RELAY_CONFLICT);
            if ($Publish) {
                $this->PublishVisualizationState();
            }
            return;
        }

        if ($resolved['stable'] !== $this->ReadAttributeInteger('StableState')) {
            $this->WriteAttributeInteger('StableState', $resolved['stable']);
        }
        if ($resolved['motion'] !== self::STATE_UNKNOWN) {
            $this->WriteAttributeInteger('LastMotion', $resolved['motion']);
        }

        // A changed, safe real relay state supersedes transient command/UI errors.
        $this->ClearLastError();
        $this->SetWindowState($resolved['state']);

        $valid = $this->UpdateModuleStatus();
        $this->WriteAttributeBoolean('RuntimeReady', $valid);
        if ($Publish) {
            $this->PublishVisualizationState();
        }
    }

    /** Pure state resolver used by runtime and regression tests. */
    private function ResolveStateFromRelays(bool $Open, bool $Close, int $StableState): array
    {
        $stable = in_array($StableState, [self::STATE_OPEN, self::STATE_CLOSED], true)
            ? $StableState
            : self::STATE_UNKNOWN;

        if ($Open && $Close) {
            return ['state' => self::STATE_FAULT, 'stable' => $stable, 'motion' => self::STATE_UNKNOWN];
        }
        if ($Open) {
            return ['state' => self::STATE_MOVING_OPEN, 'stable' => self::STATE_OPEN, 'motion' => self::STATE_MOVING_OPEN];
        }
        if ($Close) {
            return ['state' => self::STATE_MOVING_CLOSE, 'stable' => self::STATE_CLOSED, 'motion' => self::STATE_MOVING_CLOSE];
        }

        // Both OFF means idle. Never invent UNKNOWN if a valid stable end state was
        // already learned before a restart/update.
        return ['state' => $stable, 'stable' => $stable, 'motion' => self::STATE_UNKNOWN];
    }

    private function SendKey(string $Command, string $Label, int $TargetStableState): bool
    {
        if (!$this->WriteAttributeBooleanSafe('RuntimeReady', $this->IsConfigurationValid())) {
            // Attribute write failure must never become a reason to send blindly.
            return false;
        }
        if (!$this->ReadAttributeBoolean('RuntimeReady')) {
            $this->SetLastError('Modul ist nicht vollständig oder sicher konfiguriert.');
            $this->PublishVisualizationState();
            return false;
        }

        // Re-read real relay state immediately before sending. A relay conflict
        // must block the command; no corrective relay operation is attempted.
        if (!$this->SyncRelays(false) || $this->GetCurrentState() === self::STATE_FAULT) {
            $this->PublishVisualizationState();
            return false;
        }
        // Re-check under the freshest relay state. If the same direction was
        // started externally between the click and this send path, do not duplicate it.
        if ($this->IsAlreadyAtOrMovingTo($TargetStableState)) {
            return true;
        }

        $sendModule = $this->ReadPropertyInteger('SendModule');
        $table = $this->NormalizeTable($this->ReadPropertyString('Table'));
        $key = $this->ReadPropertyInteger('Key');
        $data = $this->BuildTSData($table, $key, $Command);

        $locked = false;
        try {
            $locked = IPS_SemaphoreEnter(self::SEND_SEMAPHORE, 2000);
            if (!$locked) {
                $this->SetLastError('LCN-Sendeschutz konnte nicht rechtzeitig belegt werden.');
                $this->PublishVisualizationState();
                return false;
            }

            $result = (bool) LCN_SendCommand($sendModule, 'TS', $data);
            $this->SendDebug('LCN', sprintf('%s -> TS %s über #%d', $Label, $data, $sendModule), 0);

            if (!$result) {
                $this->SetLastError('LCN_SendCommand hat den Befehl nicht bestätigt. Keine automatische Wiederholung.');
                $this->PublishVisualizationState();
                return false;
            }

            // Keep only the actual telegram section serialized. This small guard
            // prevents telegram bursts but never waits for relay feedback. If this
            // spacing sleep itself fails, the telegram has already been accepted
            // and MUST NOT be reported as unsent or retried.
            try {
                IPS_Sleep(self::SEND_GAP_MS);
            } catch (Throwable $sleepError) {
                $this->SendDebug('LCN', 'Busabstandspause fehlgeschlagen: ' . $sleepError->getMessage(), 0);
            }

            $this->ClearLastError();
            // Publish once so the HTML-SDK can release its local request queue even
            // before the independent real relay feedback arrives.
            $this->PublishVisualizationState();
            return true;
        } catch (Throwable $e) {
            $this->SetLastError('LCN_SendCommand fehlgeschlagen: ' . $e->getMessage());
            $this->PublishVisualizationState();
            return false;
        } finally {
            if ($locked) {
                IPS_SemaphoreLeave(self::SEND_SEMAPHORE);
            }
        }
    }

    private function BuildTSData(string $Table, int $Key, string $Command): string
    {
        $tableIndex = array_search($Table, ['A', 'B', 'C', 'D'], true);
        if ($tableIndex === false || $Key < 1 || $Key > 8 || !in_array($Command, [self::TS_HIT, self::TS_MAKE], true)) {
            throw new InvalidArgumentException('Ungültige LCN-Tastencodierung.');
        }

        $tables = array_fill(0, 4, self::TS_DONT_SEND);
        $tables[$tableIndex] = $Command;
        $keys = array_fill(0, 8, '0');
        $keys[$Key - 1] = '1';

        return implode('', $tables) . implode('', $keys);
    }

    private function CanSendDirection(int $TargetStableState): bool
    {
        if (!in_array($TargetStableState, [self::STATE_OPEN, self::STATE_CLOSED], true)) {
            return false;
        }
        if (!$this->IsConfigurationValid() || !$this->ReadAttributeBoolean('RuntimeReady')) {
            $this->SetLastError('Befehl abgewiesen: Konfiguration oder Laufzeit nicht freigegeben.');
            $this->PublishVisualizationState();
            return false;
        }
        if ($this->GetCurrentState() === self::STATE_FAULT) {
            return false;
        }
        return true;
    }

    private function IsAlreadyAtOrMovingTo(int $TargetStableState): bool
    {
        $state = $this->GetCurrentState();
        if ($TargetStableState === self::STATE_OPEN) {
            return $state === self::STATE_OPEN || $state === self::STATE_MOVING_OPEN;
        }
        return $state === self::STATE_CLOSED || $state === self::STATE_MOVING_CLOSE;
    }

    private function IsConfigurationValid(): bool
    {
        $sendModule = $this->ReadPropertyInteger('SendModule');
        if ($sendModule <= 0 || !IPS_InstanceExists($sendModule)) {
            return false;
        }
        if (function_exists('IPS_FunctionExists') && !IPS_FunctionExists('LCN_SendCommand')) {
            return false;
        }

        $open = $this->ReadPropertyInteger('RelayOpenVariable');
        $close = $this->ReadPropertyInteger('RelayCloseVariable');
        if ($open === $close || !$this->IsBooleanVariable($open) || !$this->IsBooleanVariable($close)) {
            return false;
        }

        $key = $this->ReadPropertyInteger('Key');
        if ($key < 1 || $key > 8) {
            return false;
        }

        return in_array($this->NormalizeTable($this->ReadPropertyString('Table')), ['A', 'B', 'C', 'D'], true);
    }

    private function UpdateModuleStatus(): bool
    {
        $sendModule = $this->ReadPropertyInteger('SendModule');
        if ($sendModule <= 0 || !IPS_InstanceExists($sendModule)) {
            $this->SetStatus(self::STATUS_INACTIVE);
            return false;
        }
        if (function_exists('IPS_FunctionExists') && !IPS_FunctionExists('LCN_SendCommand')) {
            $this->SetStatus(self::STATUS_LCN_UNAVAILABLE);
            return false;
        }

        $open = $this->ReadPropertyInteger('RelayOpenVariable');
        $close = $this->ReadPropertyInteger('RelayCloseVariable');
        if ($open > 0 && $open === $close) {
            $this->SetStatus(self::STATUS_SAME_FEEDBACK);
            return false;
        }
        if (!$this->IsBooleanVariable($open) || !$this->IsBooleanVariable($close)) {
            $this->SetStatus(self::STATUS_INVALID_FEEDBACK);
            return false;
        }

        try {
            if ((bool) GetValue($open) && (bool) GetValue($close)) {
                $this->SetStatus(self::STATUS_RELAY_CONFLICT);
                return false;
            }
        } catch (Throwable) {
            $this->SetStatus(self::STATUS_INVALID_FEEDBACK);
            return false;
        }

        $this->SetStatus(self::STATUS_ACTIVE);
        return true;
    }

    private function IsBooleanVariable(int $VariableID): bool
    {
        if ($VariableID <= 0 || !IPS_VariableExists($VariableID)) {
            return false;
        }
        try {
            $variable = IPS_GetVariable($VariableID);
            return (int) ($variable['VariableType'] ?? -1) === 0;
        } catch (Throwable) {
            return false;
        }
    }

    private function DetachRelayMessages(): void
    {
        // Use the previously active IDs, not the newly edited properties, so a
        // configuration change cannot leave stale VM_UPDATE subscriptions behind.
        foreach ([$this->ReadAttributeInteger('ActiveRelayOpenVariable'), $this->ReadAttributeInteger('ActiveRelayCloseVariable')] as $variableID) {
            if ($variableID > 0) {
                @$this->UnregisterMessage($variableID, self::MSG_VARIABLE_UPDATE);
            }
        }
        $this->WriteAttributeInteger('ActiveRelayOpenVariable', 0);
        $this->WriteAttributeInteger('ActiveRelayCloseVariable', 0);
    }

    private function EnsureVariables(): void
    {
        $created = $this->MaintainVariable('Status', 'Status', 1, 'LCW.WindowState', 10, true);
        if ($created) {
            $this->SetValue('Status', self::STATE_UNKNOWN);
        }
        @IPS_SetIcon($this->InstanceID, 'Window');
        $statusID = @IPS_GetObjectIDByIdent('Status', $this->InstanceID);
        if (is_int($statusID) && $statusID > 0) {
            @IPS_SetIcon($statusID, 'Window');
        }
    }

    private function EnsureStatusProfile(): void
    {
        $profile = 'LCW.WindowState';
        if (!IPS_VariableProfileExists($profile)) {
            IPS_CreateVariableProfile($profile, 1);
        }
        IPS_SetVariableProfileValues($profile, 0, 5, 1);
        IPS_SetVariableProfileAssociation($profile, self::STATE_UNKNOWN, 'UNBEKANNT', 'Question', 0x8A8A8A);
        IPS_SetVariableProfileAssociation($profile, self::STATE_CLOSED, 'GESCHLOSSEN', 'Window', 0x00C7B0);
        IPS_SetVariableProfileAssociation($profile, self::STATE_OPEN, 'OFFEN', 'Window', 0x00C7B0);
        IPS_SetVariableProfileAssociation($profile, self::STATE_MOVING_CLOSE, 'FÄHRT ZU', 'ArrowDown', 0x00C7B0);
        IPS_SetVariableProfileAssociation($profile, self::STATE_MOVING_OPEN, 'FÄHRT AUF', 'ArrowUp', 0x00C7B0);
        IPS_SetVariableProfileAssociation($profile, self::STATE_FAULT, 'FEHLER', 'Warning', 0xC85C5C);
    }

    private function SetWindowState(int $State): void
    {
        if (!in_array($State, [self::STATE_UNKNOWN, self::STATE_CLOSED, self::STATE_OPEN, self::STATE_MOVING_CLOSE, self::STATE_MOVING_OPEN, self::STATE_FAULT], true)) {
            $State = self::STATE_UNKNOWN;
        }

        try {
            if ((int) $this->GetValue('Status') !== $State) {
                $this->SetValue('Status', $State);
            }
        } catch (Throwable $e) {
            $this->SendDebug('Status', $e->getMessage(), 0);
        }
    }

    private function GetCurrentState(): int
    {
        try {
            $state = (int) $this->GetValue('Status');
        } catch (Throwable) {
            $state = self::STATE_UNKNOWN;
        }
        return in_array($state, [0, 1, 2, 3, 4, 5], true) ? $state : self::STATE_UNKNOWN;
    }

    private function BuildVisualizationState(): array
    {
        $state = $this->GetCurrentState();
        return [
            'state' => $state,
            'statusText' => $this->StateText($state),
            'canControl' => $this->IsConfigurationValid() && $this->ReadAttributeBoolean('RuntimeReady') && $state !== self::STATE_FAULT,
            'openCurrent' => $state === self::STATE_OPEN || $state === self::STATE_MOVING_OPEN,
            'closeCurrent' => $state === self::STATE_CLOSED || $state === self::STATE_MOVING_CLOSE,
            'errorText' => $this->ReadAttributeString('LastError')
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

    private function StateText(int $State): string
    {
        return match ($State) {
            self::STATE_CLOSED => 'GESCHLOSSEN',
            self::STATE_OPEN => 'OFFEN',
            self::STATE_MOVING_CLOSE => 'FÄHRT ZU',
            self::STATE_MOVING_OPEN => 'FÄHRT AUF',
            self::STATE_FAULT => 'FEHLER',
            default => 'UNBEKANNT'
        };
    }

    private function NormalizeTable(string $Table): string
    {
        $table = strtoupper(trim($Table));
        return in_array($table, ['A', 'B', 'C', 'D'], true) ? $table : 'A';
    }

    private function UpdateSummary(): void
    {
        $this->SetSummary(sprintf(
            '%s%d · KURZ=ZU · LANG=AUF · R AUF #%d · R ZU #%d',
            $this->NormalizeTable($this->ReadPropertyString('Table')),
            $this->ReadPropertyInteger('Key'),
            $this->ReadPropertyInteger('RelayOpenVariable'),
            $this->ReadPropertyInteger('RelayCloseVariable')
        ));
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

    private function WriteAttributeBooleanSafe(string $Name, bool $Value): bool
    {
        try {
            $this->WriteAttributeBoolean($Name, $Value);
            return true;
        } catch (Throwable $e) {
            $this->SendDebug('Attribut', $Name . ': ' . $e->getMessage(), 0);
            return false;
        }
    }
}
