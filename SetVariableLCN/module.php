<?php

declare(strict_types=1);

/**
 * Writes values reliably into LCN counter variables.
 *
 * LCN variables can only be changed relatively (Z+/Z-) or set to 0. Telegrams get lost now and then,
 * so every target runs a small control loop: Symcon sends goal − actual only while it knows a fresh
 * actual value (updated after the last write and after the grace period); otherwise it queries the
 * value. After MaxRounds writes without effect the entry is marked as failed instead of sending on.
 */
class SetVariableLCN extends IPSModule
{
    private const LCN_VALUE = '{0102BDC9-3B85-4A11-968D-7D314DA07C06}';
    private const NO_OBJECT = 1;
    private const COUNTER_TYPE = 2;      // LCN Value: value type "Zähler" (raw value)
    private const MAX_RAW = 30000;

    private const FIRMWARE_NEW = 0;      // from 170206 (Feb. 2013): variables 1-12, Z+/Z-, MWTn
    private const FIRMWARE_OLD = 1;      // up to 140C0D (Nov. 2010): only Tvar, ZA/ZS, MWV

    private const STEP_NEW = 4000;       // largest Z+/Z- step
    private const ZERO_NEW = 4090;       // Z-tt4090 sets the variable to 0
    private const STEP_OLD = 29999;      // largest ZA/ZS step
    private const ZERO_OLD = 30000;      // ZS30000 sets Tvar to 0

    private const BUSY_INTERVAL = 500;   // ms, while an entry waits for a reply
    private const IDLE_INTERVAL = 30000; // ms, periodic check of source age and LCN value age
    private const NO_ANSWER_RETRY = 300; // s, query interval for an entry whose module does not answer

    private $requested = [];             // LCN Value instances already queried in this check run

    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyString('Entries', '[]');
        $this->RegisterPropertyInteger('GracePeriod', 3);
        $this->RegisterPropertyInteger('MaxRounds', 3);
        $this->RegisterPropertyInteger('MaxAge', 900);
        $this->RegisterAttributeString('State', '{}');
        $this->RegisterTimer('Check', 0, 'SVLCN_Check($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->RegisterVariableBoolean('Fault', $this->Translate('Fault'), '~Alert', 1);

        foreach ($this->GetMessageList() as $sender => $messages) {
            foreach ($messages as $message) {
                $this->UnregisterMessage($sender, $message);
            }
        }
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            $this->RegisterMessage(0, IPS_KERNELSTARTED);
            return;
        }

        foreach ($this->GetReferenceList() as $reference) {
            $this->UnregisterReference($reference);
        }
        $errors = $this->ConfigurationErrors();
        $state = $this->LoadState();
        $keep = [];
        foreach ($this->Entries() as $index => $entry) {
            foreach ([$entry['Source'], $entry['Target']] as $object) {
                if ($object > self::NO_OBJECT && IPS_ObjectExists($object)) {
                    $this->RegisterReference($object);
                }
            }
            if (isset($errors[$index])) {
                continue;
            }
            $this->RegisterMessage($entry['Source'], VM_UPDATE);
            $this->RegisterMessage($entry['Target'], VM_UPDATE);
            $key = (string) $entry['Target'];
            $keep[$key] = $state[$key] ?? self::NewState();
        }
        $this->SaveState($keep);

        foreach ($errors as $error) {
            $this->SendDebug('ConfigurationError', $error, 0);
        }
        $this->SetStatus(count($errors) === 0 ? 102 : 200);
        $this->SetTimerInterval('Check', 1000);
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message === IPS_KERNELSTARTED) {
            $this->ApplyChanges();
            return;
        }
        if ($Message === VM_UPDATE) {
            // VariableUpdated has a resolution of one second; keep the exact time for the freshness test.
            $this->SetBuffer('U' . $SenderID, (string) microtime(true));
            $this->SetTimerInterval('Check', 100);
        }
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        $errors = $this->ConfigurationErrors();
        foreach ($form['elements'] as &$element) {
            if (($element['name'] ?? '') === 'ValidationMessage') {
                $element['caption'] = implode("\n", $errors);
                $element['visible'] = count($errors) > 0;
            }
        }
        unset($element);
        foreach ($form['actions'] as &$action) {
            if (($action['name'] ?? '') === 'StatusList') {
                $action['values'] = $this->StatusRows();
            }
        }
        unset($action);
        return json_encode($form);
    }

    /** Runs the control loop for all entries (timer and SVLCN_Check). */
    public function Check()
    {
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }
        if (!IPS_SemaphoreEnter('SVLCN_' . $this->InstanceID, 5000)) {
            $this->SetTimerInterval('Check', self::BUSY_INTERVAL);
            return;
        }
        try {
            $errors = $this->ConfigurationErrors();
            $state = $this->LoadState();
            $this->requested = [];
            $busy = false;
            $fault = false;
            foreach ($this->Entries() as $index => $entry) {
                if (isset($errors[$index])) {
                    continue;
                }
                $key = (string) $entry['Target'];
                $entryState = $state[$key] ?? self::NewState();
                $busy = $this->Evaluate($entry, $entryState) || $busy;
                $fault = $fault || $entryState['failed'];
                $state[$key] = $entryState;
            }
            $this->SaveState($state);
            if ($this->GetValue('Fault') !== $fault) {
                $this->SetValue('Fault', $fault);
            }
            $this->SetTimerInterval('Check', $busy ? self::BUSY_INTERVAL : self::IDLE_INTERVAL);
        } finally {
            IPS_SemaphoreLeave('SVLCN_' . $this->InstanceID);
        }
    }

    /** Clears all faults and round counters, then writes again where needed. */
    public function ResetFaults()
    {
        $state = $this->LoadState();
        foreach ($state as &$entryState) {
            $entryState['rounds'] = 0;
            $entryState['queries'] = 0;
            $entryState['failed'] = false;
            $entryState['reason'] = '';
        }
        unset($entryState);
        $this->SaveState($state);
        $this->Check();
    }

    /** Current state of all entries, e.g. for scripts and tests. */
    public function GetStatus()
    {
        $errors = $this->ConfigurationErrors();
        $state = $this->LoadState();
        $now = microtime(true);
        $result = [];
        foreach ($this->Entries() as $index => $entry) {
            $row = [
                'Name'    => $this->EntryName($entry),
                'Source'  => $entry['Source'],
                'Target'  => $entry['Target'],
                'Goal'    => null,
                'Actual'  => null,
                'Updated' => 0,
                'Rounds'  => 0,
                'Failed'  => false,
                'Reason'  => '',
                'Error'   => $errors[$index] ?? ''
            ];
            if ($row['Error'] === '') {
                $entryState = $state[(string) $entry['Target']] ?? self::NewState();
                $row['Goal'] = $this->Goal($entry, $now);
                $row['Actual'] = (int) round((float) GetValue($entry['Target']));
                $row['Updated'] = $this->UpdatedAt($entry['Target']);
                $row['Rounds'] = $entryState['rounds'];
                $row['Failed'] = $entryState['failed'];
                $row['Reason'] = $entryState['reason'];
            }
            $result[] = $row;
        }
        return $result;
    }

    public function RefreshStatus()
    {
        $this->UpdateFormField('StatusList', 'values', json_encode($this->StatusRows()));
    }

    /**
     * One step of the control loop for one entry. Returns true while the entry waits for a reply.
     */
    private function Evaluate(array $entry, array &$st): bool
    {
        $now = microtime(true);
        $grace = max(1, $this->ReadPropertyInteger('GracePeriod'));
        $maxRounds = max(1, $this->ReadPropertyInteger('MaxRounds'));
        $maxAge = $this->ReadPropertyInteger('MaxAge');
        $deadband = max(0, $entry['Deadband']);
        $name = $this->EntryName($entry);
        $target = $this->TargetInfo($entry);

        $goal = $this->Goal($entry, $now);
        if ($st['goal'] === null || abs($goal - $st['goal']) > $deadband) {
            // A new goal starts a new attempt.
            $st['goal'] = $goal;
            $st['rounds'] = 0;
            if ($st['failed'] && $st['reason'] === 'effect') {
                $st['failed'] = false;
                $st['reason'] = '';
            }
        }

        $actual = (int) round((float) GetValue($entry['Target']));
        $updated = $this->UpdatedAt($entry['Target']);
        if ($updated > $st['query'] && $st['queries'] > 0) {
            $st['queries'] = 0;
            if ($st['failed'] && $st['reason'] === 'answer') {
                $st['failed'] = false;
                $st['reason'] = '';
                $this->LogMessage(sprintf($this->Translate('%s: LCN module answers again'), $name), KL_NOTIFY);
            }
        }
        $stale = $updated <= $st['sent'] || ($maxAge > 0 && $now - $updated > $maxAge);
        $inSync = abs($goal - $actual) <= $deadband;

        if (!$stale && $inSync) {
            if ($st['failed']) {
                $this->LogMessage(sprintf($this->Translate('%s: LCN variable has the goal value again'), $name), KL_NOTIFY);
            }
            $st['rounds'] = 0;
            $st['failed'] = false;
            $st['reason'] = '';
            return false;
        }
        if ($now - $st['sent'] < $grace) {
            return true; // wait for the effect of the last write
        }

        if ($stale) {
            if ($st['queries'] >= $maxRounds) {
                if (!$st['failed']) {
                    $st['failed'] = true;
                    $st['reason'] = 'answer';
                    $this->LogMessage(sprintf($this->Translate('%s: LCN module does not answer (%d queries)'), $name, $st['queries']), KL_ERROR);
                }
                if ($now - $st['query'] < max(self::NO_ANSWER_RETRY, $maxAge)) {
                    return false;
                }
            } elseif ($now - $st['query'] < $grace) {
                return true;
            }
            $st['query'] = $now;
            $st['queries']++;
            $this->Query($target, $name);
            return true;
        }

        // Fresh actual value that differs from the goal.
        if ($st['failed']) {
            return false;
        }
        if ($st['rounds'] >= $maxRounds) {
            $st['failed'] = true;
            $st['reason'] = 'effect';
            $this->LogMessage(sprintf(
                $this->Translate('%s: LCN variable does not take the value (goal %d, actual %d) after %d rounds; no further writes'),
                $name,
                $goal,
                $actual,
                $st['rounds']
            ), KL_ERROR);
            return false;
        }
        $st['rounds']++;
        $st['sent'] = $now; // before sending: status reports of the first telegrams already count as fresh
        $this->Write($target, $actual, $goal, $name);
        return true;
    }

    /** Sends goal − actual as relative commands, via 0 when that needs fewer telegrams. */
    private function Write(array $target, int $actual, int $goal, string $name): void
    {
        $old = $target['firmware'] === self::FIRMWARE_OLD;
        $step = $old ? self::STEP_OLD : self::STEP_NEW;
        $diff = $goal - $actual;
        $direct = (int) ceil(abs($diff) / $step);
        $viaZero = 1 + (int) ceil($goal / $step);
        $commands = [];
        if ($goal === 0 || $actual < 0 || $actual > self::MAX_RAW || ($diff < 0 && $viaZero < $direct)) {
            $commands[] = $this->ZeroCommand($target);
            $diff = $goal;
        }
        while ($diff !== 0) {
            $amount = min(abs($diff), $step);
            $commands[] = $this->StepCommand($target, $diff > 0, $amount);
            $diff += $diff > 0 ? -$amount : $amount;
        }
        $this->SendDebug($name, sprintf('write %d -> %d', $actual, $goal), 0);
        foreach ($commands as $command) {
            $this->Send($target['unit'], $command[0], $command[1], $name);
        }
    }

    private function ZeroCommand(array $target): array
    {
        if ($target['firmware'] === self::FIRMWARE_OLD) {
            return ['ZS', (string) self::ZERO_OLD];
        }
        return ['Z-', sprintf('%03d%04d', $target['var'], self::ZERO_NEW)];
    }

    private function StepCommand(array $target, bool $up, int $amount): array
    {
        if ($target['firmware'] === self::FIRMWARE_OLD) {
            return [$up ? 'ZA' : 'ZS', sprintf('%05d', $amount)];
        }
        return [$up ? 'Z+' : 'Z-', sprintf('%03d%04d', $target['var'], $amount)];
    }

    private function Query(array $target, string $name): void
    {
        if ($target['key'] === 'T' || $target['key'] === 'R1' || $target['key'] === 'R2') {
            // Symcon does not map the answer to MWV/MWTn onto T/R1/R2; its own query does. It reads all
            // variables of the LCN Value instance, so send it only once per check run.
            if (isset($this->requested[$target['values']])) {
                return;
            }
            $this->requested[$target['values']] = true;
            $this->SendDebug($name, 'LCN_RequestRead(' . $target['values'] . ')', 0);
            if (@LCN_RequestRead($target['values']) === false) {
                $error = error_get_last();
                $this->SendDebug($name, 'failed: ' . ($error['message'] ?? '?'), 0);
            }
            return;
        }
        $this->Send($target['unit'], 'MW', 'T' . $target['var'], $name);
    }

    private function Send(int $unit, string $function, string $data, string $name): void
    {
        $this->SendDebug($name, $function . $data, 0);
        $ok = @LCN_SendCommand($unit, $function, $data);
        if ($ok === false) {
            $error = error_get_last();
            $this->SendDebug($name, 'failed: ' . ($error['message'] ?? '?'), 0);
        }
    }

    /** Raw goal value: round(value × factor + offset), 0 when the source is too old. */
    private function Goal(array $entry, float $now): int
    {
        $source = $entry['Source'];
        if ($entry['SourceMaxAge'] > 0 && $now - IPS_GetVariable($source)['VariableUpdated'] > $entry['SourceMaxAge']) {
            return 0;
        }
        $raw = (int) round((float) GetValue($source) * $entry['Factor'] + $entry['Offset']);
        return max(0, min(self::MAX_RAW, $raw));
    }

    private function UpdatedAt(int $variable): float
    {
        $exact = (float) $this->GetBuffer('U' . $variable);
        return max((float) IPS_GetVariable($variable)['VariableUpdated'], $exact);
    }

    private function Entries(): array
    {
        $entries = json_decode($this->ReadPropertyString('Entries'), true);
        if (!is_array($entries)) {
            return [];
        }
        $result = [];
        foreach ($entries as $entry) {
            $result[] = [
                'Name'         => (string) ($entry['Name'] ?? ''),
                'Source'       => (int) ($entry['Source'] ?? self::NO_OBJECT),
                'Target'       => (int) ($entry['Target'] ?? self::NO_OBJECT),
                'Factor'       => (float) ($entry['Factor'] ?? 10),
                'Offset'       => (float) ($entry['Offset'] ?? 1000),
                'Deadband'     => (int) ($entry['Deadband'] ?? 0),
                'SourceMaxAge' => (int) ($entry['SourceMaxAge'] ?? 0),
                'Firmware'     => (int) ($entry['Firmware'] ?? self::FIRMWARE_NEW)
            ];
        }
        return $result;
    }

    private function EntryName(array $entry): string
    {
        if ($entry['Name'] !== '') {
            return $entry['Name'];
        }
        if (IPS_ObjectExists($entry['Source'])) {
            return IPS_GetName($entry['Source']);
        }
        return '#' . $entry['Target'];
    }

    /** Per entry index: the first configuration error. */
    private function ConfigurationErrors(): array
    {
        $errors = [];
        $targets = [];
        foreach ($this->Entries() as $index => $entry) {
            $prefix = sprintf($this->Translate('Entry %d'), $index + 1) . ': ';
            if (!IPS_VariableExists($entry['Source'])) {
                $errors[$index] = $prefix . $this->Translate('source is not a variable');
                continue;
            }
            if (IPS_GetVariable($entry['Source'])['VariableType'] === 3) {
                $errors[$index] = $prefix . $this->Translate('source must be numeric');
                continue;
            }
            $target = $this->TargetInfo($entry);
            if (is_string($target)) {
                $errors[$index] = $prefix . $this->Translate($target);
                continue;
            }
            if (isset($targets[$entry['Target']])) {
                $errors[$index] = $prefix . $this->Translate('target is used twice');
                continue;
            }
            $targets[$entry['Target']] = true;
        }
        return $errors;
    }

    /** LCN unit, variable number and firmware of the target, or an (untranslated) error. */
    private function TargetInfo(array $entry)
    {
        $variable = $entry['Target'];
        if (!IPS_VariableExists($variable)) {
            return 'target is not a variable';
        }
        $parent = IPS_GetParent($variable);
        if (!IPS_InstanceExists($parent) || IPS_GetInstance($parent)['ModuleInfo']['ModuleID'] !== self::LCN_VALUE) {
            return 'target is not a variable of an LCN Value instance';
        }
        $ident = IPS_GetObject($variable)['ObjectIdent'];
        if (!preg_match('/^(A([1-9]|1[0-2])|T|R1|R2)Value$/', $ident, $match)) {
            return 'target is not an LCN variable (A1-A12, T, R1, R2)';
        }
        $key = $match[1];
        $number = isset($match[2]) && $match[2] !== '' ? (int) $match[2] : ['T' => 1, 'R1' => 2, 'R2' => 3][$key];
        $configuration = json_decode(IPS_GetConfiguration($parent), true);
        if ((int) ($configuration[$key . 'ValueType'] ?? -1) !== self::COUNTER_TYPE) {
            return 'LCN variable must have the value type counter';
        }
        $unit = IPS_GetInstance($parent)['ConnectionID'];
        if ($unit <= 0) {
            return 'LCN Value instance has no LCN module';
        }
        if ($entry['Firmware'] === self::FIRMWARE_OLD && $key !== 'T') {
            return 'old firmware can only write T';
        }
        return ['unit' => $unit, 'values' => $parent, 'key' => $key, 'var' => $number, 'firmware' => $entry['Firmware']];
    }

    private function StatusRows(): array
    {
        $rows = [];
        foreach ($this->GetStatus() as $row) {
            if ($row['Error'] !== '') {
                $state = $row['Error'];
            } elseif ($row['Failed']) {
                $state = $this->Translate($row['Reason'] === 'answer' ? 'Fault: no answer' : 'Fault: value not taken');
            } elseif ($row['Goal'] === $row['Actual']) {
                $state = $this->Translate('OK');
            } else {
                $state = sprintf($this->Translate('Correcting (round %d)'), $row['Rounds']);
            }
            $rows[] = [
                'Name'    => $row['Name'],
                'Goal'    => $row['Goal'] === null ? '' : (string) $row['Goal'],
                'Actual'  => $row['Actual'] === null ? '' : (string) $row['Actual'],
                'Updated' => $row['Updated'] > 0 ? date('d.m.Y H:i:s', (int) $row['Updated']) : '',
                'State'   => $state
            ];
        }
        return $rows;
    }

    private static function NewState(): array
    {
        return ['goal' => null, 'sent' => 0.0, 'query' => 0.0, 'rounds' => 0, 'queries' => 0, 'failed' => false, 'reason' => ''];
    }

    private function LoadState(): array
    {
        $state = json_decode($this->ReadAttributeString('State'), true);
        if (!is_array($state)) {
            return [];
        }
        foreach ($state as &$entryState) {
            $entryState = array_merge(self::NewState(), $entryState);
            $entryState['sent'] = (float) $entryState['sent'];
            $entryState['query'] = (float) $entryState['query'];
        }
        unset($entryState);
        return $state;
    }

    private function SaveState(array $state): void
    {
        $this->WriteAttributeString('State', json_encode($state));
    }
}
