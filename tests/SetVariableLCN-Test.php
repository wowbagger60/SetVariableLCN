<?php

declare(strict_types=1);

// Test script for SetVariableLCN on br.symcon. Run it as a Symcon script (console: new script, paste, execute).
//
// Uses the LCN Value instances 39690 (M8, counter A1-A12) and 12082 (M9, counter T/R1/R2).
// Creates the category «SVLCN Test» next to this script with 15 float source variables and one SetVariableLCN
// instance «SVLCN Test» (entries 1-15). Running it again reuses them.
//
// Tests:
//   1  Write three converted values (temperature ×10+1000, azimuth ×10+1, elevation ×10+1000)
//   2  LCN changes A1 by +7 (simulated foreign write)       -> module corrects
//   3  LCN sets A2 to 0 (simulated module restart)          -> module writes the value again
//   4  Big steps: A4 100 -> 9000 (3 × Z+) -> 100 (via 0)
//   5  MW query with unchanged value refreshes «Aktualisiert» (basis of the freshness test)
//   6  Source max. age: A5 source not updated -> 0
//   7  All 15 variables at once (bus load)
//   8  Fault path: M9 T with firmware «bis Nov. 2010» (ZA/ZS on a new module) -> OK or fault after 3 rounds
//   9  Informational: does M9 accept ZA/ZS at all (direct, without the module)?

const NEW_VALUES = 39690;   // M8, A1-A12 counter
const OLD_VALUES = 12082;   // M9, T/R1/R2 counter
const MODULE_GUID = '{8D55F1C2-665E-47CD-B4B8-DBD2D46F8990}';

$failures = 0;

function Out(string $s): void { echo date('H:i:s') . ' ' . $s . "\n"; }
function Wait(float $s): void { IPS_Sleep((int) ($s * 1000)); }
function Check(bool $ok, string $label): void
{
    global $failures;
    if (!$ok) { $failures++; }
    Out(($ok ? 'OK    ' : 'FEHLER') . ' ' . $label);
}

function Child(int $parent, string $ident): int
{
    $id = @IPS_GetObjectIDByIdent($ident, $parent);
    if ($id === false) { throw new Exception("Ident $ident fehlt unter $parent"); }
    return $id;
}

function Category(string $name, string $ident, int $parent): int
{
    $id = @IPS_GetObjectIDByIdent($ident, $parent);
    if ($id !== false) { return $id; }
    $id = IPS_CreateCategory();
    IPS_SetName($id, $name);
    IPS_SetIdent($id, $ident);
    IPS_SetParent($id, $parent);
    return $id;
}

function Source(int $parent, string $ident): int
{
    $id = @IPS_GetObjectIDByIdent($ident, $parent);
    if ($id !== false) { return $id; }
    $id = IPS_CreateVariable(2);
    IPS_SetName($id, 'Quelle ' . $ident);
    IPS_SetIdent($id, $ident);
    IPS_SetParent($id, $parent);
    return $id;
}

function Unit(int $valueInstance): int { return IPS_GetInstance($valueInstance)['ConnectionID']; }
function Raw(int $variable): int { return (int) round((float) GetValue($variable)); }

/** Waits until all (or the given) entries are OK; returns the seconds needed or -1. */
function WaitSync(int $inst, float $timeout, array $only = []): float
{
    $start = microtime(true);
    while (microtime(true) - $start < $timeout) {
        $ok = true;
        foreach (SVLCN_GetStatus($inst) as $i => $row) {
            if ($only !== [] && !in_array($i, $only, true)) { continue; }
            if ($row['Error'] !== '' || $row['Failed'] || $row['Goal'] !== $row['Actual']) { $ok = false; }
        }
        if ($ok) { return round(microtime(true) - $start, 1); }
        Wait(0.5);
    }
    return -1;
}

function Dump(int $inst, array $only = []): void
{
    foreach (SVLCN_GetStatus($inst) as $i => $row) {
        if ($only !== [] && !in_array($i, $only, true)) { continue; }
        Out(sprintf('       %-10s Soll %-6s Ist %-6s Runden %d %s %s %s', $row['Name'], $row['Goal'], $row['Actual'],
            $row['Rounds'], $row['Failed'] ? 'STÖRUNG(' . $row['Reason'] . ')' : '', $row['Error'],
            $row['Updated'] > 0 ? date('H:i:s', (int) $row['Updated']) : ''));
    }
}

// ---------------------------------------------------------------- setup
if (!IPS_ModuleExists(MODULE_GUID)) {
    Out('Modul SetVariableLCN ist nicht geladen. Module Control: Modul neu laden, dann Skript erneut starten.');
    return;
}
$root = Category('SVLCN Test', 'SVLCNTest', IPS_GetParent($_IPS['SELF']));
$targets = [];
$sources = [];
for ($n = 1; $n <= 12; $n++) {
    $targets[] = Child(NEW_VALUES, "A{$n}Value");
    $sources[] = Source($root, "A$n");
}
foreach (['T', 'R1', 'R2'] as $k) {
    $targets[] = Child(OLD_VALUES, "{$k}Value");
    $sources[] = Source($root, $k);
}

function Entries(array $sources, array $targets, array $override = []): string
{
    $entries = [];
    foreach ($targets as $i => $t) {
        $e = ['Name' => IPS_GetName($t), 'Source' => $sources[$i], 'Target' => $t, 'Factor' => 1, 'Offset' => 0,
              'Deadband' => 0, 'SourceMaxAge' => 0, 'Firmware' => 0];
        if ($i === 0) { $e = array_merge($e, ['Name' => 'Temperatur', 'Factor' => 10, 'Offset' => 1000]); }
        if ($i === 1) { $e = array_merge($e, ['Name' => 'Azimut', 'Factor' => 10, 'Offset' => 1]); }
        if ($i === 2) { $e = array_merge($e, ['Name' => 'Höhe', 'Factor' => 10, 'Offset' => 1000]); }
        $entries[] = array_merge($e, $override[$i] ?? []);
    }
    return json_encode($entries);
}

$inst = @IPS_GetObjectIDByIdent('SVLCNInstance', $root);
if ($inst === false) {
    $inst = IPS_CreateInstance(MODULE_GUID);
    IPS_SetName($inst, 'SVLCN Test');
    IPS_SetIdent($inst, 'SVLCNInstance');
    IPS_SetParent($inst, $root);
}
function Configure(int $inst, string $entries): void
{
    IPS_SetProperty($inst, 'Entries', $entries);
    IPS_SetProperty($inst, 'GracePeriod', 3);
    IPS_SetProperty($inst, 'MaxRounds', 3);
    IPS_SetProperty($inst, 'MaxAge', 900);
    IPS_ApplyChanges($inst);
    SVLCN_ResetFaults($inst);
}
Configure($inst, Entries($sources, $targets));
Out("Instanz $inst, Kategorie $root, Build " . (IPS_GetLibrary('{E94C4769-A087-47AF-94E4-36A8C9E97EB8}')['Build'] ?? '?'));
foreach (SVLCN_GetStatus($inst) as $row) {
    if ($row['Error'] !== '') { Check(false, 'Konfiguration: ' . $row['Error']); }
}

// Start state: all sources at a known value.
foreach ($sources as $i => $s) { SetValue($s, $i < 3 ? 10.0 : 500.0 + $i); }
$t = WaitSync($inst, 60);
Check($t >= 0, "Startzustand synchron ($t s)");

// ---------------------------------------------------------------- 1
Out('Test 1: drei umgerechnete Werte');
SetValue($sources[0], 21.5);    // 1215
SetValue($sources[1], 183.4);   // 1835
SetValue($sources[2], -12.3);   // 877
$t = WaitSync($inst, 20, [0, 1, 2]);
Check($t >= 0 && Raw($targets[0]) === 1215 && Raw($targets[1]) === 1835 && Raw($targets[2]) === 877,
    "Test 1: A1 1215 / A2 1835 / A3 877 ($t s)");
Dump($inst, [0, 1, 2]);

// ---------------------------------------------------------------- 2
Out('Test 2: LCN ändert A1 um +7');
LCN_SendCommand(Unit(NEW_VALUES), 'Z+', '0010007');
Wait(1.5);
$seen = Raw($targets[0]);
$t = WaitSync($inst, 20, [0]);
Check($t >= 0 && Raw($targets[0]) === 1215, "Test 2: gesehen $seen, korrigiert auf 1215 ($t s)");

// ---------------------------------------------------------------- 3
Out('Test 3: A2 auf 0 (Neustart simuliert)');
LCN_SendCommand(Unit(NEW_VALUES), 'Z-', '0024090');
Wait(1.5);
$seen = Raw($targets[1]);
$t = WaitSync($inst, 20, [1]);
Check($t >= 0 && Raw($targets[1]) === 1835, "Test 3: gesehen $seen, wieder 1835 ($t s)");

// ---------------------------------------------------------------- 4
Out('Test 4: grosse Sprünge auf A4');
SetValue($sources[3], 9000.0);
$t = WaitSync($inst, 30, [3]);
Check($t >= 0 && Raw($targets[3]) === 9000, "Test 4a: 9000 ($t s)");
SetValue($sources[3], 100.0);
$t = WaitSync($inst, 30, [3]);
Check($t >= 0 && Raw($targets[3]) === 100, "Test 4b: 100 über 0 ($t s)");

// ---------------------------------------------------------------- 5
Out('Test 5: Abfrage ohne Änderung aktualisiert den Zeitstempel');
Wait(2);
$before = IPS_GetVariable($targets[4])['VariableUpdated'];
LCN_SendCommand(Unit(NEW_VALUES), 'MW', 'T5');
Wait(2);
$after = IPS_GetVariable($targets[4])['VariableUpdated'];
Check($after > $before, "Test 5: Aktualisiert vorher " . date('H:i:s', $before) . ", nachher " . date('H:i:s', $after));
LCN_SendCommand(Unit(OLD_VALUES), 'MW', 'T1');
Wait(2);
Out('       M9 T nach MWT1 aktualisiert ' . date('H:i:s', IPS_GetVariable($targets[12])['VariableUpdated']));

// ---------------------------------------------------------------- 6
Out('Test 6: Quelle zu alt -> 0');
Configure($inst, Entries($sources, $targets, [4 => ['SourceMaxAge' => 3]]));
Wait(4);
SVLCN_Check($inst);
$t = WaitSync($inst, 20, [4]);
Check($t >= 0 && Raw($targets[4]) === 0, "Test 6a: A5 = 0 ($t s)");
SetValue($sources[4], 777.0);
$t = WaitSync($inst, 20, [4]);
Check($t >= 0 && Raw($targets[4]) === 777, "Test 6b: frische Quelle -> 777 ($t s)");
Configure($inst, Entries($sources, $targets));

// ---------------------------------------------------------------- 7
Out('Test 7: alle 15 Variablen gleichzeitig');
$expect = [];
foreach ($sources as $i => $s) {
    $v = $i < 3 ? (float) rand(0, 300) / 10 : (float) rand(1, 8000);
    SetValue($s, $v);
}
$t = WaitSync($inst, 60);
Check($t >= 0, "Test 7: alle synchron ($t s)");
if ($t < 0) { Dump($inst); }

// ---------------------------------------------------------------- 8
Out('Test 8: M9 T mit Firmware «bis Nov. 2010» (ZA/ZS)');
Configure($inst, Entries($sources, $targets, [12 => ['Firmware' => 1]]));
SetValue($sources[12], 1234.0);
Wait(25);
$row = SVLCN_GetStatus($inst)[12];
Out(sprintf('       Soll %s Ist %s Runden %d Störung %s (%s)', $row['Goal'], $row['Actual'], $row['Rounds'],
    $row['Failed'] ? 'ja' : 'nein', $row['Reason']));
Check($row['Actual'] === 1234 || $row['Failed'], 'Test 8: entweder übernommen oder Störung gemeldet');
Out('       Variable «Störung»: ' . (GetValue(IPS_GetObjectIDByIdent('Fault', $inst)) ? 'EIN' : 'aus'));
Configure($inst, Entries($sources, $targets));
$t = WaitSync($inst, 30, [12]);
Check($t >= 0 && !GetValue(IPS_GetObjectIDByIdent('Fault', $inst)), "Test 8: zurück auf neue Befehle, Störung aus ($t s)");

// ---------------------------------------------------------------- 9
Out('Test 9: nimmt M9 ZA/ZS direkt an? (nur Information)');
$v = Raw($targets[12]);
IPS_SetProperty($inst, 'Entries', '[]');   // module off for the moment
IPS_ApplyChanges($inst);
$r = @LCN_SendCommand(Unit(OLD_VALUES), 'ZA', '00005');
Wait(2);
LCN_SendCommand(Unit(OLD_VALUES), 'MW', 'T1');
Wait(2);
Out(sprintf('       ZA00005: Antwort %s, T vorher %d, nachher %d', var_export($r, true), $v, Raw($targets[12])));
Configure($inst, Entries($sources, $targets));
WaitSync($inst, 30);

Out($failures === 0 ? 'ALLE TESTS OK' : "$failures FEHLER");
