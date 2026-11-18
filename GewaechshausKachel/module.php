<?php

declare(strict_types=1);

/**
 * Gewächshaus Kachel (HTML-SDK)
 *
 * Übersicht und Bewässerungssteuerung für Gewächshäuser und Beete mit GARDENA smart system (Modul de.symcon.gardena):
 * - je Bereich: Raumklima (Zigbee2MQTT, Temperatur/Luftfeuchte), Boden (GARDENA Sensor, Feuchte/Temperatur),
 *   Ventil (GARDENA Valve) mit Status, Restlaufzeit, Hand-Start und Stopp
 * - Bewässerung nach Zeitplan (Startzeiten mit Dauer, Wochentage), übersprungen wenn
 *   der Boden feucht genug ist, Frost droht oder – bei Beeten im Freien – Regen erwartet wird bzw. es geregnet hat
 * - Regenprognose: DWD MOSMIX (JSON-Variable des Skripts „Wettervorhersage_DWD“, Stundenwerte „prec“ in mm)
 * - Regensensor (z. B. KNX-Wetterstation) als Ist-Wert, Zisternenpumpe schaltbar und optional vor jedem Ventil
 *
 * Die Ventile laufen nacheinander (eine Automatik-Bewässerung zur Zeit); Hand-Starts laufen sofort (GARDENA: höchstens 2 Ventile).
 */
class GewaechshausKachel extends IPSModuleStrict
{
    private const DESIGN_GUID = '{018EEC85-8FFC-4E1F-A966-702C4BBDC2E8}';
    private const ARCHIVE_GUID = '{43192F0B-135B-4CE7-A0A7-1475603F3060}';
    private const COMMON_GUID = '{FD9A4547-26FE-A75F-B636-839CAB25EACA}';
    private const DAYS = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];
    private const SOIL_STALE = 26 * 3600;     // Bodenwert älter → Zeitplan ohne Feuchteprüfung
    private const CLIMATE_STALE = 6 * 3600;   // Raumklima älter → als veraltet anzeigen

    public function Create(): void
    {
        parent::Create();
        $this->RegisterPropertyString('Zones', '[]');
        $this->RegisterPropertyInteger('ForecastVar', 0);     // JSON MOSMIX (hourly: ts, prec)
        $this->RegisterPropertyInteger('RainVar', 0);         // Regensensor (bool)
        $this->RegisterPropertyFloat('RainMinutes', 60);      // so viele Minuten Regen in 24 h zählen als „hat geregnet“
        $this->RegisterPropertyInteger('OutdoorTempVar', 0);  // Außentemperatur für Frostschutz
        $this->RegisterPropertyFloat('FrostTemp', 3.0);
        $this->RegisterPropertyInteger('PumpVar', 0);         // Pumpe Zisterne schalten
        $this->RegisterPropertyInteger('PumpStatusVar', 0);   // Pumpe Zisterne Status
        $this->RegisterPropertyBoolean('PumpForValves', false);
        $this->RegisterPropertyBoolean('PumpRequired', true);  // Wasser kommt aus der Zisterne: Automatik nur bei laufender Pumpe
        $this->RegisterPropertyInteger('PumpLead', 20);       // Sekunden Vorlauf der Pumpe vor dem Ventil
        $this->RegisterPropertyInteger('DetailID', 0);        // Seite für die kleine Kachel (openObject)
        $this->RegisterAttributeString('Watched', '[]');
        $this->RegisterAttributeString('Queue', '[]');
        $this->RegisterAttributeString('Running', '[]');
        $this->RegisterAttributeString('Done', '[]');
        $this->RegisterAttributeString('Log', '[]');
        $this->RegisterAttributeBoolean('PumpByUs', false);
        $this->RegisterTimer('Tick', 0, 'GWK_Tick($_IPS[\'TARGET\']);');
        $this->SetVisualizationType(1);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        foreach ((array) json_decode($this->ReadAttributeString('Watched'), true) as $w) {
            @$this->UnregisterMessage((int) $w[0], (int) $w[1]);
        }
        foreach ($this->GetReferenceList() as $ref) {
            $this->UnregisterReference($ref);
        }
        $watch = [];
        $design = $this->DesignID();
        if ($design > 0) {
            $this->RegisterMessage($design, IM_CHANGESETTINGS);
            $watch[] = [$design, IM_CHANGESETTINGS];
        }
        $vars = [];
        foreach ($this->Zones() as $z) {
            foreach ([$z['valve'], $z['soil'], $z['climate']] as $inst) {
                if ($inst > 0) {
                    $this->RegisterReference($inst);
                }
            }
            $v = $this->ZoneVars($z);
            foreach (['activity', 'duration', 'vstate', 'moist', 'stemp', 'temp', 'hum'] as $k) {
                if (isset($v[$k])) {
                    $vars[] = $v[$k];
                }
            }
        }
        foreach (['ForecastVar', 'RainVar', 'OutdoorTempVar', 'PumpVar', 'PumpStatusVar'] as $p) {
            $id = $this->ReadPropertyInteger($p);
            if ($id > 0 && IPS_VariableExists($id)) {
                $vars[] = $id;
                $this->RegisterReference($id);
            }
        }
        foreach (array_unique($vars) as $vid) {
            $this->RegisterMessage($vid, VM_UPDATE);
            $watch[] = [$vid, VM_UPDATE];
        }
        $this->WriteAttributeString('Watched', json_encode($watch));
        $this->SetTimerInterval('Tick', 30 * 1000);
        $this->SetStatus($this->Zones() === [] ? 201 : IS_ACTIVE);
        $this->Push();
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        $this->Push();
    }

    public function GetVisualizationTile(): string
    {
        $initial = '<script>handleMessage(' . json_encode(json_encode($this->BuildState())) . ');</script>';
        return '<style id="w5d">' . $this->DesignCSS() . '</style>' . file_get_contents(__DIR__ . '/module.html') . $this->DesignScript() . $initial;
    }

    /* ---------- Bedienung aus der Kachel ---------- */

    public function RequestAction(string $Ident, mixed $Value): void
    {
        $p = is_string($Value) ? json_decode($Value, true) : null;
        $zi = is_array($p) ? (int) ($p['z'] ?? -1) : -1;
        switch ($Ident) {
            case 'Start':
                $this->ManualStart($zi, max(1, min(120, (int) ($p['min'] ?? 10))));
                break;
            case 'Stop':
                $this->StopZone($zi, 'Hand-Stopp');
                break;
            case 'Auto':
                $this->SetZoneField($zi, 'Auto', (bool) ($p['on'] ?? false));
                return;
            case 'Moist':
                $this->SetZoneField($zi, 'MoistStop', max(0, min(95, (int) ($p['v'] ?? 0))));
                return;
            case 'Rain':
                $this->SetZoneField($zi, 'RainStop', max(0.0, min(30.0, round((float) ($p['v'] ?? 0), 1))));
                return;
            case 'Times':
                $this->SetZoneField($zi, 'Times', $this->FormatTimes($this->ParseTimes((string) ($p['v'] ?? ''), 10)));
                return;
            case 'Days':
                $this->SetZoneField($zi, 'Days', (string) ($p['v'] ?? ''));
                return;
            case 'Pump':
                $pv = $this->ReadPropertyInteger('PumpVar');
                if ($pv > 0 && IPS_VariableExists($pv)) {
                    RequestAction($pv, (bool) $Value);
                    $this->WriteAttributeBoolean('PumpByUs', false);
                    $this->AddLog('Zisterne', 'Pumpe von Hand ' . ((bool) $Value ? 'ein' : 'aus'));
                }
                break;
            case 'Refresh':
                break;
            default:
                throw new Exception('Unbekannte Aktion: ' . $Ident);
        }
        $this->Push();
    }

    /** Ein Feld eines Bereichs dauerhaft ändern (Eigenschaft „Zones“, sichtbar in der Konsole). */
    private function SetZoneField(int $zi, string $field, mixed $value): void
    {
        $list = (array) json_decode($this->ReadPropertyString('Zones'), true);
        if (!isset($list[$zi])) {
            return;
        }
        if ($field === 'Days') {
            $value = implode(',', array_values(array_intersect(self::DAYS, array_map('trim', explode(',', (string) $value)))));
        }
        $list[$zi][$field] = $value;
        IPS_SetProperty($this->InstanceID, 'Zones', json_encode($list));
        IPS_ApplyChanges($this->InstanceID);
        $this->AddLog((string) ($list[$zi]['Name'] ?? ''), 'Einstellung ' . $field . ' = ' . (is_bool($value) ? ($value ? 'ein' : 'aus') : $value));
    }

    /* ---------- Ablauf (Timer alle 30 s) ---------- */

    public function Tick(): void
    {
        $now = time();
        $min = (int) date('G', $now) * 60 + (int) date('i', $now);
        $today = date('Y-m-d', $now);
        $dow = (int) date('N', $now);
        $done = array_values(array_filter((array) json_decode($this->ReadAttributeString('Done'), true), fn ($k) => str_starts_with((string) $k, $today)));
        $queue = (array) json_decode($this->ReadAttributeString('Queue'), true);
        $changed = false;

        // fällige Startzeiten prüfen (bis 3 min Verspätung, z. B. nach einem Neustart)
        foreach ($this->Zones() as $i => $z) {
            if (!$z['auto'] || $z['valve'] <= 0 || !in_array($dow, $z['days'], true)) {
                continue;
            }
            foreach ($z['times'] as $t) {
                if ($min < $t['m'] || $min > $t['m'] + 3) {
                    continue;
                }
                $key = $today . ' ' . $i . ' ' . $t['m'];
                if (in_array($key, $done, true)) {
                    continue;
                }
                $done[] = $key;
                $changed = true;
                [$ok, $why] = $this->Decide($z);
                if ($ok) {
                    $queue[] = ['z' => $i, 'min' => $t['d'], 'src' => 'auto', 'at' => $now];
                    $this->AddLog($z['name'], sprintf('%s: %d min geplant%s', $this->Hm($t['m']), $t['d'], $why !== '' ? ' (' . $why . ')' : ''));
                } else {
                    $this->AddLog($z['name'], sprintf('%s: übersprungen – %s', $this->Hm($t['m']), $why));
                }
            }
        }
        $this->WriteAttributeString('Done', json_encode($done));
        $this->WriteAttributeString('Queue', json_encode($queue));
        $changed = $this->Process() || $changed;
        if ($changed) {
            $this->Push();
        }
    }

    /** Warteschlange abarbeiten, laufende Ventile überwachen, Pumpe nachführen. Liefert true bei Änderungen. */
    private function Process(): bool
    {
        $now = time();
        $zones = $this->Zones();
        $run = (array) json_decode($this->ReadAttributeString('Running'), true);
        $queue = (array) json_decode($this->ReadAttributeString('Queue'), true);
        $changed = false;

        foreach ($run as $k => $r) {
            $z = $zones[$r['z']] ?? null;
            if ($z === null) {
                unset($run[$k]);
                $changed = true;
                continue;
            }
            if ($r['phase'] === 'pump') {
                if ($now >= (int) $r['startAt']) {
                    $ok = $this->OpenValve($z, (int) $r['min']);
                    $run[$k] = ['phase' => 'open', 'started' => $now, 'until' => $now + (int) $r['min'] * 60 + 60] + $r;
                    if (!$ok) {
                        unset($run[$k]);
                    }
                    $changed = true;
                }
                continue;
            }
            $act = $this->Val($this->ZoneVars($z), 'activity');
            // fertig: Laufzeit um oder Ventil meldet nach dem Anlauf wieder „geschlossen“
            if ($now > (int) $r['until'] || ($now > (int) $r['started'] + 120 && $act === 'CLOSED')) {
                $this->AddLog($z['name'], sprintf('Bewässerung beendet (%d min)', max(1, (int) round(($now - (int) $r['started']) / 60))));
                unset($run[$k]);
                $changed = true;
            }
        }
        $run = array_values($run);

        // nächsten Automatik-Lauf starten, wenn nichts läuft
        if ($run === [] && $queue !== []) {
            $next = array_shift($queue);
            $z = $zones[$next['z']] ?? null;
            if ($z !== null) {
                $run[] = $this->Begin($next['z'], $z, (int) $next['min'], (string) $next['src']);
            }
            $run = array_values(array_filter($run));
            $changed = true;
        }

        // Pumpe aus, wenn das Modul sie eingeschaltet hat und kein Ventil mehr offen ist
        if ($run === [] && $queue === [] && $this->ReadAttributeBoolean('PumpByUs')) {
            $open = false;
            foreach ($zones as $z) {
                $a = $this->Val($this->ZoneVars($z), 'activity');
                $open = $open || ($a !== null && $a !== 'CLOSED');
            }
            if (!$open) {
                $this->SwitchPump(false);
                $this->WriteAttributeBoolean('PumpByUs', false);
                $this->AddLog('Zisterne', 'Pumpe aus (Bewässerung fertig)');
                $changed = true;
            }
        }
        $this->WriteAttributeString('Running', json_encode($run));
        $this->WriteAttributeString('Queue', json_encode($queue));
        return $changed;
    }

    /** Lauf beginnen: ggf. erst die Pumpe, sonst sofort das Ventil. */
    private function Begin(int $zi, array $z, int $minutes, string $src): ?array
    {
        $now = time();
        if ($this->ReadPropertyBoolean('PumpForValves') && !$this->PumpOn()) {
            $this->SwitchPump(true);
            $this->WriteAttributeBoolean('PumpByUs', true);
            $this->AddLog('Zisterne', 'Pumpe ein für ' . $z['name']);
            return ['z' => $zi, 'min' => $minutes, 'src' => $src, 'phase' => 'pump', 'startAt' => $now + max(0, $this->ReadPropertyInteger('PumpLead'))];
        }
        if (!$this->OpenValve($z, $minutes)) {
            return null;
        }
        return ['z' => $zi, 'min' => $minutes, 'src' => $src, 'phase' => 'open', 'started' => $now, 'until' => $now + $minutes * 60 + 60];
    }

    private function ManualStart(int $zi, int $minutes): void
    {
        $zones = $this->Zones();
        if (!isset($zones[$zi])) {
            return;
        }
        $run = (array) json_decode($this->ReadAttributeString('Running'), true);
        foreach ($run as $r) {
            if ((int) $r['z'] === $zi) {
                return;   // läuft schon
            }
        }
        $this->AddLog($zones[$zi]['name'], 'Hand-Start ' . $minutes . ' min');
        if (count($run) >= 2) {
            // GARDENA: höchstens zwei Ventile gleichzeitig → vorn in die Warteschlange
            $queue = (array) json_decode($this->ReadAttributeString('Queue'), true);
            array_unshift($queue, ['z' => $zi, 'min' => $minutes, 'src' => 'hand', 'at' => time()]);
            $this->WriteAttributeString('Queue', json_encode($queue));
            return;
        }
        $e = $this->Begin($zi, $zones[$zi], $minutes, 'hand');
        if ($e !== null) {
            $run[] = $e;
        }
        $this->WriteAttributeString('Running', json_encode(array_values($run)));
    }

    private function StopZone(int $zi, string $why): void
    {
        $zones = $this->Zones();
        if (!isset($zones[$zi])) {
            return;
        }
        $v = $this->ZoneVars($zones[$zi]);
        if (isset($v['control'])) {
            try {
                RequestAction($v['control'], 'STOP_UNTIL_NEXT_TASK');
            } catch (Throwable $e) {
                $this->AddLog($zones[$zi]['name'], 'Stopp fehlgeschlagen: ' . $e->getMessage());
            }
        }
        $run = array_values(array_filter((array) json_decode($this->ReadAttributeString('Running'), true), fn ($r) => (int) $r['z'] !== $zi));
        $queue = array_values(array_filter((array) json_decode($this->ReadAttributeString('Queue'), true), fn ($r) => (int) $r['z'] !== $zi));
        $this->WriteAttributeString('Running', json_encode($run));
        $this->WriteAttributeString('Queue', json_encode($queue));
        $this->AddLog($zones[$zi]['name'], $why);
        $this->Process();
    }

    /** GARDENA-Ventil für $minutes öffnen. Die Öffnungsdauer der Ventil-Instanz wird danach wiederhergestellt. */
    private function OpenValve(array $z, int $minutes): bool
    {
        $v = $this->ZoneVars($z);
        if (!isset($v['control'], $v['vduration'])) {
            $this->AddLog($z['name'], 'Ventil nicht gefunden');
            return false;
        }
        $old = (int) GetValue($v['vduration']);
        try {
            RequestAction($v['vduration'], $minutes);
            ob_start();
            RequestAction($v['control'], 'START_SECONDS_TO_OVERRIDE');
            $out = trim((string) ob_get_clean());
            RequestAction($v['vduration'], $old > 0 ? $old : $minutes);
        } catch (Throwable $e) {
            @ob_end_clean();
            $this->AddLog($z['name'], 'Start fehlgeschlagen: ' . $e->getMessage());
            return false;
        }
        $this->AddLog($z['name'], 'Ventil auf, ' . $minutes . ' min' . ($out !== '' ? ' (' . mb_substr($out, 0, 120) . ')' : ''));
        return true;
    }

    /* ---------- Entscheidung: gießen oder nicht ---------- */

    /** [bool gießen, string Begründung] für eine Automatik-Bewässerung jetzt. */
    private function Decide(array $z): array
    {
        $v = $this->ZoneVars($z);
        $now = time();
        if (($this->Val($v, 'vstate') ?? 'OK') === 'UNAVAILABLE' || ($this->Val($v, 'vlink') ?? 'ONLINE') === 'OFFLINE') {
            return [false, 'Ventil nicht erreichbar'];
        }
        // Wasser aus der Zisterne, Pumpe wird von Hand geschaltet: ohne laufende Pumpe kommt kein Wasser
        if ($this->PumpMissing()) {
            return [false, 'Pumpe Zisterne ist aus'];
        }
        // Frost: im Gewächshaus die Raumtemperatur (wenn aktuell), sonst die Außentemperatur
        $frost = $this->ReadPropertyFloat('FrostTemp');
        $t = null;
        if (!$z['outdoor'] && isset($v['temp']) && $now - $this->ClimateSeen($v) < self::CLIMATE_STALE) {
            $t = (float) GetValue($v['temp']);
        } else {
            $ot = $this->ReadPropertyInteger('OutdoorTempVar');
            $t = $ot > 0 && IPS_VariableExists($ot) ? (float) GetValue($ot) : null;
        }
        if ($t !== null && $t < $frost) {
            return [false, sprintf('Frostgefahr (%.1f °C)', $t)];
        }
        $note = '';
        if ($z['moistStop'] > 0 && isset($v['moist'])) {
            $age = $now - IPS_GetVariable($v['moist'])['VariableUpdated'];
            $m = (int) GetValue($v['moist']);
            if ($age > self::SOIL_STALE) {
                $note = 'Bodensensor ohne aktuelle Daten, nach Zeitplan';
            } elseif ($m >= $z['moistStop']) {
                return [false, sprintf('Boden feucht genug (%d %% ≥ %d %%)', $m, $z['moistStop'])];
            } else {
                $note = sprintf('Boden %d %%', $m);
            }
        }
        if ($z['outdoor'] && $z['rainStop'] > 0) {
            $rv = $this->ReadPropertyInteger('RainVar');
            if ($rv > 0 && IPS_VariableExists($rv) && (bool) GetValue($rv)) {
                return [false, 'es regnet gerade'];
            }
            $mins = $this->RainedMinutes(24);
            if ($mins !== null && $mins >= $this->ReadPropertyFloat('RainMinutes')) {
                return [false, sprintf('hat in 24 h %d min geregnet', $mins)];
            }
            $f = $this->Forecast();
            if ($f['ok'] && $f['h24'] >= $z['rainStop']) {
                return [false, sprintf('Regen erwartet (%.1f mm in 24 h ≥ %.1f mm)', $f['h24'], $z['rainStop'])];
            }
            if ($f['ok']) {
                $note .= ($note !== '' ? ', ' : '') . sprintf('Regen 24 h %.1f mm', $f['h24']);
            }
        }
        return [true, $note];
    }

    /** Öffentlich: Entscheidung aller Bereiche als Text (Konsole „Jetzt prüfen“). */
    public function Check(): string
    {
        $out = [];
        foreach ($this->Zones() as $z) {
            [$ok, $why] = $this->Decide($z);
            $out[] = $z['name'] . ': ' . ($ok ? 'würde gießen' : 'würde nicht gießen') . ($why !== '' ? ' – ' . $why : '');
        }
        $f = $this->Forecast();
        $out[] = $f['ok'] ? sprintf('Regenprognose: 24 h %.1f mm, 48 h %.1f mm, 72 h %.1f mm (Stand %s)', $f['h24'], $f['h48'], $f['h72'], $f['issued']) : 'Regenprognose: keine Daten';
        $m = $this->RainedMinutes(24);
        $out[] = 'Regensensor: ' . ($m === null ? 'keine Daten' : $m . ' min Regen in 24 h');
        return implode("\n", $out);
    }

    /* ---------- Regen ---------- */

    /** Regenprognose aus der MOSMIX-JSON-Variable: Summen 24/48/72 h, Stundenwerte 24 h, Tageswerte. */
    private function Forecast(): array
    {
        if ($this->fc !== null) {
            return $this->fc;
        }
        $this->fc = $this->ReadForecast();
        return $this->fc;
    }

    private ?array $fc = null;
    private ?int $rained = null;
    private bool $rainedRead = false;

    private function ReadForecast(): array
    {
        $r = ['ok' => false, 'h24' => 0.0, 'h48' => 0.0, 'h72' => 0.0, 'hours' => [], 'days' => [], 'issued' => ''];
        $id = $this->ReadPropertyInteger('ForecastVar');
        if ($id <= 0 || !IPS_VariableExists($id)) {
            return $r;
        }
        $j = json_decode((string) GetValue($id), true);
        if (!is_array($j) || empty($j['hourly'])) {
            return $r;
        }
        $now = time();
        $days = [];
        foreach ($j['hourly'] as $h) {
            $ts = (int) ($h['ts'] ?? 0);
            $p = max(0.0, (float) ($h['prec'] ?? 0));
            if ($ts <= $now - 3600) {
                continue;
            }
            // „prec“ ist der Niederschlag der Stunde bis ts; die laufende Stunde zählt mit
            if ($ts <= $now + 24 * 3600) {
                $r['h24'] += $p;
                $r['hours'][] = [$ts, round($p, 1)];
            }
            if ($ts <= $now + 48 * 3600) {
                $r['h48'] += $p;
            }
            if ($ts <= $now + 72 * 3600) {
                $r['h72'] += $p;
            }
            $d = date('Y-m-d', $ts - 1);
            if (count($days) < 5 || isset($days[$d])) {
                $days[$d] ??= ['ts' => strtotime($d), 'prec' => 0.0, 'tmin' => null, 'tmax' => null];
                $days[$d]['prec'] += $p;
                $t = isset($h['temp']) ? (float) $h['temp'] : null;
                if ($t !== null) {
                    $days[$d]['tmin'] = $days[$d]['tmin'] === null ? $t : min($days[$d]['tmin'], $t);
                    $days[$d]['tmax'] = $days[$d]['tmax'] === null ? $t : max($days[$d]['tmax'], $t);
                }
            }
        }
        foreach (['h24', 'h48', 'h72'] as $k) {
            $r[$k] = round($r[$k], 1);
        }
        foreach ($days as $d) {
            $r['days'][] = ['ts' => $d['ts'], 'prec' => round($d['prec'], 1), 'tmin' => $d['tmin'] === null ? null : round($d['tmin']), 'tmax' => $d['tmax'] === null ? null : round($d['tmax'])];
        }
        $iss = strtotime((string) ($j['info']['generation_time'] ?? ''));
        $r['issued'] = $iss ? date('d.m. H:i', $iss) : date('d.m. H:i', IPS_GetVariable($id)['VariableUpdated']);
        $r['ok'] = $r['hours'] !== [];
        // Prognose älter als 12 h: nicht mehr verwenden
        if (time() - IPS_GetVariable($id)['VariableUpdated'] > 12 * 3600) {
            $r['ok'] = false;
            $r['stale'] = true;
        }
        return $r;
    }

    /** Minuten mit Regen (Regensensor = true) in den letzten $hours Stunden laut Archiv; null ohne Sensor/Archiv. */
    private function RainedMinutes(int $hours): ?int
    {
        if (!$this->rainedRead) {
            $this->rained = $this->ReadRainedMinutes($hours);
            $this->rainedRead = true;
        }
        return $this->rained;
    }

    private function ReadRainedMinutes(int $hours): ?int
    {
        $rv = $this->ReadPropertyInteger('RainVar');
        $arch = IPS_GetInstanceListByModuleID(self::ARCHIVE_GUID)[0] ?? 0;
        if ($rv <= 0 || !IPS_VariableExists($rv) || $arch <= 0 || !@AC_GetLoggingStatus($arch, $rv)) {
            return null;
        }
        $end = time();
        $start = $end - $hours * 3600;
        $vals = @AC_GetLoggedValues($arch, $rv, $start, $end, 0);
        $before = @AC_GetLoggedValues($arch, $rv, 0, $start, 1);
        if (!is_array($vals)) {
            return null;
        }
        $state = is_array($before) && isset($before[0]) ? (bool) $before[0]['Value'] : false;
        $t = $start;
        $sum = 0;
        foreach (array_reverse($vals) as $e) {   // älteste zuerst
            if ($state) {
                $sum += (int) $e['TimeStamp'] - $t;
            }
            $state = (bool) $e['Value'];
            $t = (int) $e['TimeStamp'];
        }
        if ($state) {
            $sum += $end - $t;
        }
        return (int) round($sum / 60);
    }

    /* ---------- Pumpe ---------- */

    /** Pumpe nötig (Wasser aus der Zisterne), wird nicht automatisch geschaltet und ist aus. */
    private function PumpMissing(): bool
    {
        return $this->ReadPropertyBoolean('PumpRequired') && !$this->ReadPropertyBoolean('PumpForValves')
            && $this->ReadPropertyInteger('PumpVar') > 0 && !$this->PumpOn();
    }

    private function PumpOn(): bool
    {
        $s = $this->ReadPropertyInteger('PumpStatusVar');
        if ($s <= 0 || !IPS_VariableExists($s)) {
            $s = $this->ReadPropertyInteger('PumpVar');
        }
        return $s > 0 && IPS_VariableExists($s) && (bool) GetValue($s);
    }

    private function SwitchPump(bool $on): void
    {
        $pv = $this->ReadPropertyInteger('PumpVar');
        if ($pv > 0 && IPS_VariableExists($pv)) {
            try {
                RequestAction($pv, $on);
            } catch (Throwable $e) {
                $this->AddLog('Zisterne', 'Pumpe schalten fehlgeschlagen: ' . $e->getMessage());
            }
        }
    }

    /* ---------- Bereiche ---------- */

    /** Bereiche aus der Eigenschaft „Zones“, aufbereitet. */
    private function Zones(): array
    {
        $r = [];
        foreach ((array) json_decode($this->ReadPropertyString('Zones'), true) as $i => $z) {
            $valve = (int) ($z['Valve'] ?? 0);
            $vdur = 10;
            if ($valve > 0 && IPS_InstanceExists($valve)) {
                $d = @IPS_GetObjectIDByIdent('ValveDuration', $valve);
                $vdur = is_int($d) && $d > 0 ? max(1, (int) GetValue($d)) : 10;
            }
            $days = [];
            foreach (array_map('trim', explode(',', (string) ($z['Days'] ?? implode(',', self::DAYS)))) as $d) {
                $k = array_search($d, self::DAYS, true);
                if ($k !== false) {
                    $days[] = $k + 1;
                }
            }
            $r[$i] = [
                'name'      => trim((string) ($z['Name'] ?? ('Bereich ' . ($i + 1)))),
                'valve'     => $valve > 0 && IPS_InstanceExists($valve) ? $valve : 0,
                'soil'      => (int) ($z['Soil'] ?? 0) > 0 && IPS_InstanceExists((int) $z['Soil']) ? (int) $z['Soil'] : 0,
                'climate'   => (int) ($z['Climate'] ?? 0) > 0 && IPS_InstanceExists((int) $z['Climate']) ? (int) $z['Climate'] : 0,
                'outdoor'   => (bool) ($z['Outdoor'] ?? false),
                'auto'      => (bool) ($z['Auto'] ?? false),
                'times'     => $this->ParseTimes((string) ($z['Times'] ?? ''), $vdur),
                'days'      => $days,
                'moistStop' => (int) ($z['MoistStop'] ?? 0),
                'rainStop'  => (float) ($z['RainStop'] ?? 0),
                'note'      => trim((string) ($z['Note'] ?? ''))
            ];
        }
        return $r;
    }

    /** „05:50=5, 06:00=15“ → [['m' => 350, 'd' => 5], …] (ohne „=“: Standarddauer). */
    private function ParseTimes(string $s, int $default): array
    {
        $r = [];
        foreach (preg_split('/[,;\s]+/', $s, -1, PREG_SPLIT_NO_EMPTY) as $part) {
            if (!preg_match('/^(\d{1,2})[:.](\d{2})(?:=(\d{1,3}))?$/', $part, $m)) {
                continue;
            }
            $h = (int) $m[1];
            $mi = (int) $m[2];
            if ($h > 23 || $mi > 59) {
                continue;
            }
            $r[$h * 60 + $mi] = ['m' => $h * 60 + $mi, 'd' => max(1, min(120, isset($m[3]) ? (int) $m[3] : $default))];
        }
        ksort($r);
        return array_values($r);
    }

    private function FormatTimes(array $t): string
    {
        return implode(', ', array_map(fn ($x) => $this->Hm($x['m']) . '=' . $x['d'], $t));
    }

    private function Hm(int $m): string
    {
        return sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
    }

    /** Variablen eines Bereichs nach Ident. */
    private function ZoneVars(array $z): array
    {
        $map = [];
        $find = function (int $inst, array $idents) use (&$map) {
            if ($inst <= 0) {
                return;
            }
            foreach ($idents as $key => $ident) {
                $id = @IPS_GetObjectIDByIdent($ident, $inst);
                if (is_int($id) && $id > 0) {
                    $map[$key] = $id;
                }
            }
        };
        $find($z['valve'], ['activity' => 'activity', 'duration' => 'duration', 'vstate' => 'state', 'verror' => 'lastErrorCode',
            'control' => 'ValveControl', 'vduration' => 'ValveDuration']);
        $find($z['soil'], ['moist' => 'soilHumidity', 'stemp' => 'soilTemperature', 'light' => 'lightIntensity', 'atemp' => 'ambientTemperature']);
        $find($z['climate'], ['temp' => 'temperature', 'hum' => 'humidity', 'cbat' => 'battery', 'seen' => 'last_seen']);
        // Geräteinformationen (GARDENA Common) liegen neben der Ventil- bzw. Sensor-Instanz
        $find($this->CommonOf($z['valve']), ['vlink' => 'rfLinkState', 'vbat' => 'batteryLevel', 'vbatState' => 'batteryState']);
        $find($this->CommonOf($z['soil']), ['slink' => 'rfLinkState', 'sbat' => 'batteryLevel', 'sbatState' => 'batteryState']);
        return $map;
    }

    private function CommonOf(int $inst): int
    {
        if ($inst <= 0) {
            return 0;
        }
        foreach (IPS_GetChildrenIDs(IPS_GetParent($inst)) as $c) {
            if (IPS_InstanceExists($c) && IPS_GetInstance($c)['ModuleInfo']['ModuleID'] === self::COMMON_GUID) {
                return $c;
            }
        }
        return 0;
    }

    private function Val(array $v, string $k): mixed
    {
        return isset($v[$k]) ? GetValue($v[$k]) : null;
    }

    /** Zeitpunkt der letzten Meldung des Klimasensors (Z2M last_seen, sonst letzte Aktualisierung der Temperatur). */
    private function ClimateSeen(array $v): int
    {
        if (isset($v['seen'])) {
            $s = GetValue($v['seen']);
            if (is_int($s) && $s > 1000000000) {
                return $s > 100000000000 ? intdiv($s, 1000) : $s;
            }
        }
        return isset($v['temp']) ? IPS_GetVariable($v['temp'])['VariableUpdated'] : 0;
    }

    /** Nächster geplanter Start eines Bereichs (Zeitstempel) in den nächsten 8 Tagen, 0 = keiner. */
    private function NextRun(array $z): int
    {
        if (!$z['auto'] || $z['times'] === [] || $z['days'] === []) {
            return 0;
        }
        $now = time();
        for ($d = 0; $d < 8; $d++) {
            $day = strtotime('today +' . $d . ' days', $now);
            if (!in_array((int) date('N', $day), $z['days'], true)) {
                continue;
            }
            foreach ($z['times'] as $t) {
                $ts = $day + $t['m'] * 60;
                if ($ts > $now) {
                    return $ts;
                }
            }
        }
        return 0;
    }

    /* ---------- Protokoll ---------- */

    private function AddLog(string $zone, string $text): void
    {
        $log = (array) json_decode($this->ReadAttributeString('Log'), true);
        array_unshift($log, [time(), $zone, $text]);
        $this->WriteAttributeString('Log', json_encode(array_slice($log, 0, 40), JSON_UNESCAPED_UNICODE));
        $this->SendDebug($zone, $text, 0);
    }

    /* ---------- Darstellung ---------- */

    private function DesignID(): int
    {
        if (!IPS_ModuleExists(self::DESIGN_GUID)) {
            return 0;
        }
        $ids = IPS_GetInstanceListByModuleID(self::DESIGN_GUID);
        sort($ids);
        return $ids[0] ?? 0;
    }

    /**
     * Farben und Kachel-Bausteine aus dem Modul „Visu Design“ (W5D_GetCSS + W5D_GetTileCSS, Wurzel class="w5t fill").
     * Das CSS kommt zusätzlich mit jedem Zustand mit (Feld design), damit ein Theme-Wechsel ohne Neuladen ankommt.
     */
    private function DesignCSS(): string
    {
        $id = $this->DesignID();
        if ($id <= 0 || !function_exists('W5D_GetCSS')) {
            return '';
        }
        return W5D_GetCSS($id) . (function_exists('W5D_GetTileCSS') ? W5D_GetTileCSS($id) : '');
    }

    /** Hell/Dunkel-Erkennung (w5tTheme) aus Visu Design */
    private function DesignScript(): string
    {
        $id = $this->DesignID();
        return $id > 0 && function_exists('W5D_GetTileJS') ? '<script>' . W5D_GetTileJS($id) . '</script>' : '';
    }

    private function Push(): void
    {
        $this->UpdateVisualizationValue(json_encode(['design' => $this->DesignCSS()] + $this->BuildState()));
    }

    private function BuildState(): array
    {
        $now = time();
        $run = (array) json_decode($this->ReadAttributeString('Running'), true);
        $queue = (array) json_decode($this->ReadAttributeString('Queue'), true);
        $s = ['now' => $now, 'detailId' => $this->ReadPropertyInteger('DetailID'), 'zones' => []];
        foreach ($this->Zones() as $i => $z) {
            $v = $this->ZoneVars($z);
            $g = fn (string $k) => $this->Val($v, $k);
            $soilAge = isset($v['moist']) ? $now - IPS_GetVariable($v['moist'])['VariableUpdated'] : null;
            $seen = isset($v['temp']) ? $this->ClimateSeen($v) : 0;
            $r = null;
            foreach ($run as $x) {
                if ((int) $x['z'] === $i) {
                    $r = $x;
                }
            }
            [$ok, $why] = $z['valve'] > 0 && $z['auto'] ? $this->Decide($z) : [null, ''];
            $s['zones'][] = [
                'i'         => $i,
                'name'      => $z['name'],
                'note'      => $z['note'],
                'outdoor'   => $z['outdoor'],
                'auto'      => $z['auto'],
                'times'     => $z['times'],
                'days'      => $z['days'],
                'moistStop' => $z['moistStop'],
                'rainStop'  => $z['rainStop'],
                'hasValve'  => $z['valve'] > 0,
                'activity'  => $g('activity'),
                'remaining' => $g('duration'),
                'vstate'    => $g('vstate'),
                'verror'    => $g('verror'),
                'vlink'     => $g('vlink'),
                'vupd'      => isset($v['activity']) ? IPS_GetVariable($v['activity'])['VariableUpdated'] : 0,
                'running'   => $r === null ? null : ['phase' => $r['phase'], 'until' => (int) ($r['until'] ?? 0), 'min' => (int) $r['min'], 'src' => $r['src']],
                'queued'    => count(array_filter($queue, fn ($q) => (int) $q['z'] === $i)) > 0,
                'hasSoil'   => isset($v['moist']),
                'moist'     => $g('moist'),
                'stemp'     => $g('stemp'),
                'soilAge'   => $soilAge,
                'sbat'      => $g('sbat'),
                'slink'     => $g('slink'),
                'hasClimate' => isset($v['temp']),
                'temp'      => $g('temp'),
                'hum'       => $g('hum'),
                'cbat'      => $g('cbat'),
                'climateAge' => $seen > 0 ? $now - $seen : null,
                'next'      => $this->NextRun($z),
                'decision'  => $ok,
                'why'       => $why
            ];
        }
        $f = $this->Forecast();
        $rv = $this->ReadPropertyInteger('RainVar');
        $s['rain'] = $f + ['raining' => $rv > 0 && IPS_VariableExists($rv) ? (bool) GetValue($rv) : null, 'rained' => $this->RainedMinutes(24)];
        $ot = $this->ReadPropertyInteger('OutdoorTempVar');
        $s['outTemp'] = $ot > 0 && IPS_VariableExists($ot) ? round((float) GetValue($ot), 1) : null;
        $pv = $this->ReadPropertyInteger('PumpVar');
        $s['pump'] = $pv > 0 && IPS_VariableExists($pv) ? ['on' => $this->PumpOn(), 'auto' => $this->ReadPropertyBoolean('PumpForValves'), 'missing' => $this->PumpMissing(), 'byUs' => $this->ReadAttributeBoolean('PumpByUs')] : null;
        $s['queue'] = count($queue);
        $s['log'] = array_slice((array) json_decode($this->ReadAttributeString('Log'), true), 0, 12);
        return $s;
    }
}
