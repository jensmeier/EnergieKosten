<?php

declare(strict_types=1);

class EnergieKosten extends IPSModuleStrict
{
    private const ARCHIVE_MODULE_GUID = '{43192F0B-135B-4CE7-A0A7-1475603F3060}';
    private const FRONIUS_WH_PER_KWH = 1000.0;
    private const HEATER_MIN_POWER_W = 50.0;
    private const TIMER_MS = 60000;

    // Vorhandene Bestandsvariablen aus den bisherigen Skripten.
    // Sie werden nur gelesen, nicht verändert oder gelöscht.
    private const GRID_KWH_DAY   = 15785;
    private const GRID_KWH_WEEK  = 41184;
    private const GRID_KWH_MONTH = 26464;
    private const GRID_KWH_YEAR  = 28371;
    private const GRID_EUR_DAY   = 24118;
    private const GRID_EUR_WEEK  = 16333;
    private const GRID_EUR_MONTH = 46005;
    private const GRID_EUR_YEAR  = 24821;

    private const FEED_KWH_DAY   = 56673;
    private const FEED_KWH_WEEK  = 13694;
    private const FEED_KWH_MONTH = 29885;
    private const FEED_KWH_YEAR  = 37602;
    private const FEED_EUR_DAY   = 44972;
    private const FEED_EUR_WEEK  = 53087;
    private const FEED_EUR_MONTH = 52169;
    private const FEED_EUR_YEAR  = 13502;

    private const HEAT_KWH_DAY   = 38341;
    private const HEAT_KWH_WEEK  = 48338;
    private const HEAT_KWH_MONTH = 32764;
    private const HEAT_KWH_YEAR  = 39736;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyInteger('GridImportVariable', 46689);
        $this->RegisterPropertyInteger('FeedInVariable', 49541);
        $this->RegisterPropertyInteger('HeaterPowerVariable', 47596);

        $this->RegisterPropertyFloat('GridPrice', 0.2788);
        $this->RegisterPropertyFloat('FeedInPrice', 0.0688);
        $this->RegisterPropertyFloat('OilEnergyKWhPerLiter', 10.0);
        $this->RegisterPropertyFloat('BoilerEfficiencyPercent', 90.0);
        $this->RegisterPropertyInteger('CycleSeconds', 8);
        $this->RegisterPropertyBoolean('AutoArchive', true);

        $created = $this->RegisterVariableFloat(
            'HeaterTotalKWh',
            'Heizstab Gesamt',
            [
                'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                'SUFFIX' => ' kWh',
                'DIGITS' => 3
            ],
            10
        );
        if ($created) {
            $this->SetValue('HeaterTotalKWh', 0.0);
        }

        $detailCreated = $this->RegisterVariableString(
            'DetailHTML',
            'Energie Detail',
            [
                'PRESENTATION' => VARIABLE_PRESENTATION_WEB_CONTENT,
                'HTML_TYPE' => 0,
                'PADDING' => false
            ],
            20
        );
        if ($detailCreated) {
            $this->SetValue('DetailHTML', '<div style="padding:16px">Energie-Details werden geladen …</div>');
        }

        $this->RegisterAttributeInteger('LastHeaterTimestamp', 0);
        $this->RegisterAttributeInteger('ArchiveConfiguredAt', 0);

        $this->RegisterTimer('Tick', self::TIMER_MS, 'EK_Tick($_IPS["TARGET"]);');
        $this->SetVisualizationType(1);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        foreach ($this->GetReferenceList() as $referenceID) {
            $this->UnregisterReference($referenceID);
        }

        $gridID = $this->ReadPropertyInteger('GridImportVariable');
        $feedID = $this->ReadPropertyInteger('FeedInVariable');
        $heaterPowerID = $this->ReadPropertyInteger('HeaterPowerVariable');

        $valid = true;
        foreach ([$gridID, $feedID, $heaterPowerID] as $id) {
            if ($this->IsNumericVariable($id)) {
                $this->RegisterReference($id);
            } else {
                $valid = false;
            }
        }

        // Bestehende Hilfswerte nur referenzieren, falls vorhanden.
        foreach ($this->GetLegacyIDs() as $id) {
            if ($this->IsNumericVariable($id)) {
                $this->RegisterReference($id);
            }
        }

        $heaterTotalID = $this->GetIDForIdent('HeaterTotalKWh');

        // BUILD8: Webinhalt ohne zusätzlichen Symcon-Innenabstand, damit die
        // 10-Zoll-Detailansicht die verfügbare Höhe vollständig nutzen kann.
        $detailID = $this->GetIDForIdent('DetailHTML');
        if ($detailID > 0 && IPS_VariableExists($detailID)) {
            @IPS_SetVariableCustomPresentation($detailID, [
                'PRESENTATION' => VARIABLE_PRESENTATION_WEB_CONTENT,
                'HTML_TYPE' => 0,
                'PADDING' => false
            ]);
        }

        if ($valid && $this->ReadPropertyBoolean('AutoArchive')) {
            $archiveID = $this->GetArchiveID();
            if ($archiveID > 0) {
                // Bereits vorhandenes Logging nicht umkonfigurieren. BUILD2 hatte bei
                // einem neu begonnenen Zählerarchiv den vorhandenen Gesamtstand als
                // erste positive Differenz interpretiert. BUILD3 vermeidet das.
                $this->EnsureLifetimeArchive($archiveID, $gridID);
                $this->EnsureLifetimeArchive($archiveID, $feedID);
                $this->EnsureCounterArchive($archiveID, $heaterTotalID);

                // Für Diagramm / saisonale Historie reichen die bestehenden Tageszähler.
                // Aggregationstyp dieser Reset-Variablen bleibt unverändert.
                foreach ([self::GRID_KWH_DAY, self::FEED_KWH_DAY, self::HEAT_KWH_DAY] as $id) {
                    if ($this->IsNumericVariable($id)) {
                        $this->EnsureLoggingOnly($archiveID, $id);
                    }
                }
                $this->WriteAttributeInteger('ArchiveConfiguredAt', time());
            } else {
                $valid = false;
            }
        }

        $this->WriteAttributeInteger('LastHeaterTimestamp', time());
        $this->SetTimerInterval('Tick', self::TIMER_MS);
        $this->SetStatus($valid ? 102 : 104);

        if ($valid) {
            $this->Refresh();
        }
    }

    public function Tick(): void
    {
        $this->IntegrateHeater();
        $this->Refresh();
    }

    public function Refresh(): void
    {
        try {
            $data = $this->BuildPayload();
            $payload = json_encode(
                $data,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
            );
            if ($payload === false) {
                throw new Exception('Visualisierungsdaten konnten nicht als JSON codiert werden.');
            }

            // Kleine Modul-Kachel aktualisieren.
            $this->UpdateVisualizationValue($payload);

            // Separate Webinhalt-Variable für die große Diagramm-/Prognoseansicht.
            // Dadurch öffnet die kleine Kachel nicht mehr die Instanz mit "Heizstab Gesamt",
            // sondern genau die von uns erzeugte Detailseite.
            $this->SetValue('DetailHTML', $this->RenderDetailHTML($data));
        } catch (Throwable $e) {
            $this->SendDebug('Refresh', $e->getMessage(), 0);
        }
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        if ($Ident === 'Refresh') {
            $this->Refresh();
            return;
        }
        throw new Exception('Invalid Ident: ' . $Ident);
    }

    public function GetVisualizationTile(): string
    {
        $html = file_get_contents(__DIR__ . '/module.html');
        if ($html === false) {
            return '<div>module.html konnte nicht geladen werden.</div>';
        }

        $data = $this->BuildPayload();
        $json = json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
        if ($json === false) {
            $json = '{}';
        }

        $html = str_replace('__DETAIL_ID__', (string) $this->GetIDForIdent('DetailHTML'), $html);
        return str_replace('__INITIAL_DATA__', $json, $html);
    }

    private function RenderDetailHTML(array $data): string
    {
        $html = file_get_contents(__DIR__ . '/detail.html');
        if ($html === false) {
            return '<div style="padding:16px">detail.html konnte nicht geladen werden.</div>';
        }

        $json = json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
        if ($json === false) {
            $json = '{}';
        }

        return str_replace('__DETAIL_DATA__', $json, $html);
    }

    private function GetLegacyIDs(): array
    {
        return [
            self::GRID_KWH_DAY, self::GRID_KWH_WEEK, self::GRID_KWH_MONTH, self::GRID_KWH_YEAR,
            self::GRID_EUR_DAY, self::GRID_EUR_WEEK, self::GRID_EUR_MONTH, self::GRID_EUR_YEAR,
            self::FEED_KWH_DAY, self::FEED_KWH_WEEK, self::FEED_KWH_MONTH, self::FEED_KWH_YEAR,
            self::FEED_EUR_DAY, self::FEED_EUR_WEEK, self::FEED_EUR_MONTH, self::FEED_EUR_YEAR,
            self::HEAT_KWH_DAY, self::HEAT_KWH_WEEK, self::HEAT_KWH_MONTH, self::HEAT_KWH_YEAR
        ];
    }

    private function IsNumericVariable(int $id): bool
    {
        if ($id <= 0 || !IPS_VariableExists($id)) {
            return false;
        }
        $variable = IPS_GetVariable($id);
        return in_array((int) $variable['VariableType'], [VARIABLETYPE_INTEGER, VARIABLETYPE_FLOAT], true);
    }

    private function SafeValue(int $id, float $fallback = 0.0): float
    {
        if (!$this->IsNumericVariable($id)) {
            return $fallback;
        }
        return max(0.0, (float) GetValue($id));
    }

    private function GetArchiveID(): int
    {
        $ids = IPS_GetInstanceListByModuleID(self::ARCHIVE_MODULE_GUID);
        return count($ids) > 0 ? (int) $ids[0] : 0;
    }

    private function EnsureLoggingOnly(int $archiveID, int $variableID): void
    {
        if (!AC_GetLoggingStatus($archiveID, $variableID)) {
            AC_SetLoggingStatus($archiveID, $variableID, true);
        }
    }

    private function EnsureLifetimeArchive(int $archiveID, int $variableID): void
    {
        $wasLogging = AC_GetLoggingStatus($archiveID, $variableID);
        if (!$wasLogging) {
            AC_SetLoggingStatus($archiveID, $variableID, true);
            AC_SetAggregationType($archiveID, $variableID, 1);
            AC_ReAggregateVariable($archiveID, $variableID);
        }
        // Wenn schon geloggt wurde, vorhandenen Aggregationstyp bewusst erhalten.
    }

    private function EnsureCounterArchive(int $archiveID, int $variableID): void
    {
        if (!AC_GetLoggingStatus($archiveID, $variableID)) {
            AC_SetLoggingStatus($archiveID, $variableID, true);
        }
        if (AC_GetAggregationType($archiveID, $variableID) !== 1) {
            AC_SetAggregationType($archiveID, $variableID, 1);
            AC_ReAggregateVariable($archiveID, $variableID);
        }
    }

    private function IntegrateHeater(): void
    {
        $heaterPowerID = $this->ReadPropertyInteger('HeaterPowerVariable');
        if (!$this->IsNumericVariable($heaterPowerID)) {
            return;
        }

        $now = time();
        $last = $this->ReadAttributeInteger('LastHeaterTimestamp');
        if ($last <= 0) {
            $this->WriteAttributeInteger('LastHeaterTimestamp', $now);
            return;
        }

        $elapsed = $now - $last;
        $this->WriteAttributeInteger('LastHeaterTimestamp', $now);
        if ($elapsed < 1) {
            return;
        }
        if ($elapsed > 300) {
            $elapsed = 60;
        }

        $powerW = max(0.0, (float) GetValue($heaterPowerID));
        if ($powerW < self::HEATER_MIN_POWER_W) {
            return;
        }

        $deltaKWh = ($powerW * $elapsed) / 3600000.0;
        if ($deltaKWh <= 0.0) {
            return;
        }

        $current = (float) $this->GetValue('HeaterTotalKWh');
        $this->SetValue('HeaterTotalKWh', round($current + $deltaKWh, 6));
    }

    private function BuildPayload(): array
    {
        $archiveID = $this->GetArchiveID();
        $gridID = $this->ReadPropertyInteger('GridImportVariable');
        $feedID = $this->ReadPropertyInteger('FeedInVariable');
        $heaterTotalID = $this->GetIDForIdent('HeaterTotalKWh');

        $now = time();
        $todayStart = strtotime('today 00:00:00', $now);
        $weekStart = strtotime('monday this week 00:00:00', $now);
        $monthStart = strtotime(date('Y-m-01 00:00:00', $now));
        $yearStart = strtotime(date('Y-01-01 00:00:00', $now));
        $last30Start = strtotime('today -29 days', $now);
        $dayHistoryStart = strtotime('today -7 days', $now);
        $chartDailyStart = strtotime('today -400 days', $now);
        $historyStart = strtotime('-3 years', $yearStart);

        $gridPrice = max(0.0, $this->ReadPropertyFloat('GridPrice'));
        $feedPrice = max(0.0, $this->ReadPropertyFloat('FeedInPrice'));
        $oilKWh = max(0.001, $this->ReadPropertyFloat('OilEnergyKWhPerLiter'));
        $efficiency = max(0.01, min(1.0, $this->ReadPropertyFloat('BoilerEfficiencyPercent') / 100.0));
        $cycle = max(3, min(60, $this->ReadPropertyInteger('CycleSeconds')));

        if ($archiveID <= 0 || !$this->IsNumericVariable($gridID) || !$this->IsNumericVariable($feedID)) {
            return [
                'ok' => false,
                'error' => 'Archiv oder Quellvariablen nicht verfügbar.',
                'instanceID' => $this->InstanceID,
                'cycleSeconds' => $cycle,
                'updated' => $now
            ];
        }

        $meta = $this->GetArchiveMetaMap($archiveID);
        $legacy = $this->HasLegacySummary();
        $legacyGridFirst = $this->FirstTimeFor($meta, self::GRID_KWH_DAY);
        $legacyFeedFirst = $this->FirstTimeFor($meta, self::FEED_KWH_DAY);
        $legacyHeatFirst = $this->FirstTimeFor($meta, self::HEAT_KWH_DAY);

        // Historie bevorzugt aus den vorhandenen Tageszählern. Deren Tages-Maximum
        // ist der echte Tagesverbrauch / die echte Tageseinspeisung vor dem Reset.
        $dayGrid = $this->GetDailyTotalsFromResetVariable($archiveID, self::GRID_KWH_DAY, $last30Start, $now, $legacyGridFirst);
        $dayFeed = $this->GetDailyTotalsFromResetVariable($archiveID, self::FEED_KWH_DAY, $last30Start, $now, $legacyFeedFirst);
        $dayHeat = $this->GetDailyTotalsFromResetVariable($archiveID, self::HEAT_KWH_DAY, $last30Start, $now, $legacyHeatFirst);

        $historyGridDaily = $this->GetDailyTotalsFromResetVariable($archiveID, self::GRID_KWH_DAY, $historyStart, $now, $legacyGridFirst);
        $historyFeedDaily = $this->GetDailyTotalsFromResetVariable($archiveID, self::FEED_KWH_DAY, $historyStart, $now, $legacyFeedFirst);
        $historyHeatDaily = $this->GetDailyTotalsFromResetVariable($archiveID, self::HEAT_KWH_DAY, $historyStart, $now, $legacyHeatFirst);

        // Falls die bisherigen Tagesvariablen wider Erwarten nicht geloggt sind,
        // auf Fronius-Gesamtzähler zurückfallen; erste Archivperiode mit Initialwert
        // wird dabei bewusst übersprungen, damit kein 1.515/10.258-kWh-Sprung entsteht.
        if (count($dayGrid) === 0) {
            $dayGrid = $this->GetCounterAggregates($archiveID, $gridID, 1, $last30Start, $now, self::FRONIUS_WH_PER_KWH, $this->FirstTimeFor($meta, $gridID));
        }
        if (count($dayFeed) === 0) {
            $dayFeed = $this->GetCounterAggregates($archiveID, $feedID, 1, $last30Start, $now, self::FRONIUS_WH_PER_KWH, $this->FirstTimeFor($meta, $feedID));
        }

        if (count($historyGridDaily) === 0) {
            $historyGridDaily = $this->GetCounterAggregates($archiveID, $gridID, 1, $historyStart, $now, self::FRONIUS_WH_PER_KWH, $this->FirstTimeFor($meta, $gridID));
        }
        if (count($historyFeedDaily) === 0) {
            $historyFeedDaily = $this->GetCounterAggregates($archiveID, $feedID, 1, $historyStart, $now, self::FRONIUS_WH_PER_KWH, $this->FirstTimeFor($meta, $feedID));
        }
        if (count($historyHeatDaily) === 0) {
            $historyHeatDaily = $this->GetCounterAggregates($archiveID, $heaterTotalID, 1, $historyStart, $now, 1.0, $this->FirstTimeFor($meta, $heaterTotalID));
        }

        // BUILD7: Für die Tagesansicht werden auch die letzten 7 Tage stündlich
        // mitgegeben. Damit kann die Detailseite auf dem Tablet horizontal in die
        // Vergangenheit gescrollt werden, ohne eine zusätzliche ID oder Anfrage.
        $hourGrid = $this->GetHourlyPositiveDeltasFromResetVariable($archiveID, self::GRID_KWH_DAY, $dayHistoryStart, $now);
        $hourFeed = $this->GetHourlyPositiveDeltasFromResetVariable($archiveID, self::FEED_KWH_DAY, $dayHistoryStart, $now);
        if (count($hourGrid) === 0) {
            $hourGrid = $this->GetCounterAggregates($archiveID, $gridID, 0, $dayHistoryStart, $now, self::FRONIUS_WH_PER_KWH, $this->FirstTimeFor($meta, $gridID));
        }
        if (count($hourFeed) === 0) {
            $hourFeed = $this->GetCounterAggregates($archiveID, $feedID, 0, $dayHistoryStart, $now, self::FRONIUS_WH_PER_KWH, $this->FirstTimeFor($meta, $feedID));
        }

        if ($legacy) {
            $summary = $this->BuildLegacySummary($dayGrid, $dayFeed, $dayHeat, $gridPrice, $feedPrice, $oilKWh, $efficiency);
        } else {
            $summary = $this->BuildArchiveSummary($archiveID, $gridID, $feedID, $heaterTotalID, $dayGrid, $dayFeed, $dayHeat, $gridPrice, $feedPrice, $oilKWh, $efficiency, $todayStart, $weekStart, $monthStart, $yearStart, $now);
        }

        $yearGridMonthly = $this->GroupDailySeriesByMonth($historyGridDaily);
        $yearFeedMonthly = $this->GroupDailySeriesByMonth($historyFeedDaily);

        $chartGridDaily = $this->FilterSeriesFrom($historyGridDaily, $chartDailyStart);
        $chartFeedDaily = $this->FilterSeriesFrom($historyFeedDaily, $chartDailyStart);
        $charts = [
            'day' => $this->CombineChartSeries($hourGrid, $hourFeed, 'day'),
            // Woche und Monat enthalten bewusst mehr Historie als das zunächst
            // sichtbare Fenster. Die Webansicht startet ganz rechts bei heute und
            // kann nach links zu älteren geloggten Werten gescrollt werden.
            'week' => $this->CombineChartSeries($chartGridDaily, $chartFeedDaily, 'week'),
            'month' => $this->CombineChartSeries($chartGridDaily, $chartFeedDaily, 'month'),
            'year' => $this->CombineChartSeries($yearGridMonthly, $yearFeedMonthly, 'year')
        ];

        $monthlyGridHistory = $this->GroupDailySeriesByMonthYear($historyGridDaily);
        $monthlyFeedHistory = $this->GroupDailySeriesByMonthYear($historyFeedDaily);
        $monthlyHeatHistory = $this->GroupDailySeriesByMonthYear($historyHeatDaily);

        $coverageGrid = $this->FirstTimeFor($meta, self::GRID_KWH_DAY);
        $coverageFeed = $this->FirstTimeFor($meta, self::FEED_KWH_DAY);
        $coverageHeat = $this->FirstTimeFor($meta, self::HEAT_KWH_DAY);
        if ($coverageGrid <= 0) $coverageGrid = $this->FirstTimeFor($meta, $gridID);
        if ($coverageFeed <= 0) $coverageFeed = $this->FirstTimeFor($meta, $feedID);
        if ($coverageHeat <= 0) $coverageHeat = $this->FirstTimeFor($meta, $heaterTotalID);

        $forecastGrid = $this->SeasonalAnnualForecast($monthlyGridHistory, $coverageGrid, $now);
        $forecastFeed = $this->SeasonalAnnualForecast($monthlyFeedHistory, $coverageFeed, $now);
        $forecastHeat = $this->SeasonalAnnualForecast($monthlyHeatHistory, $coverageHeat, $now);

        $forecast = $this->BuildForecastResult($forecastGrid, $forecastFeed, $forecastHeat, $gridPrice, $feedPrice, $oilKWh, $efficiency);

        $yearGridKWh = (float) $summary['year']['grid']['kwh'];
        $yearFeedKWh = (float) $summary['year']['feed']['kwh'];
        $yearGridEuro = (float) $summary['year']['grid']['euro'];
        $yearFeedEuro = (float) $summary['year']['feed']['euro'];

        // BUILD7: verständliche Jahreshochrechnung statt Deckungs-Prozentwerten.
        // Wenn eine vollständige saisonale Prognose vorhanden ist, wird sie genutzt.
        // Fehlen Vergleichsmonate, gibt es trotzdem eine klar als "vorläufig"
        // gekennzeichnete lineare Hochrechnung auf Basis des tatsächlich erfassten
        // Zeitraums. Heizstab ist bewusst nicht Bestandteil der Jahresrechnung.
        $daysInYear = ((int) date('L', $now) === 1) ? 366.0 : 365.0;
        $gridBasisStart = ($coverageGrid > $yearStart && $coverageGrid < $now) ? $coverageGrid : $yearStart;
        $feedBasisStart = ($coverageFeed > $yearStart && $coverageFeed < $now) ? $coverageFeed : $yearStart;
        $gridObservedDays = max(1.0, min($daysInYear, (($now - $gridBasisStart) / 86400.0) + 1.0));
        $feedObservedDays = max(1.0, min($daysInYear, (($now - $feedBasisStart) / 86400.0) + 1.0));

        if ((bool) ($forecast['complete'] ?? false)) {
            $projectionGridKWh = (float) ($forecast['gridKWh'] ?? 0.0);
            $projectionFeedKWh = (float) ($forecast['feedKWh'] ?? 0.0);
            $projectionGridEuro = (float) ($forecast['gridEuro'] ?? 0.0);
            $projectionFeedEuro = (float) ($forecast['feedEuro'] ?? 0.0);
            $projectionMethod = 'saisonal';
            $projectionProvisional = false;
        } else {
            $projectionGridKWh = $yearGridKWh * ($daysInYear / $gridObservedDays);
            $projectionFeedKWh = $yearFeedKWh * ($daysInYear / $feedObservedDays);
            // Bei der vorläufigen Hochrechnung werden die tatsächlich aufgelaufenen
            // Eurobeträge hochgerechnet. So bleiben frühere Preisstände erhalten.
            $projectionGridEuro = $yearGridEuro * ($daysInYear / $gridObservedDays);
            $projectionFeedEuro = $yearFeedEuro * ($daysInYear / $feedObservedDays);
            $projectionMethod = 'linear';
            $projectionProvisional = true;
        }

        $yearProjection = [
            'gridKWh' => $projectionGridKWh,
            'feedKWh' => $projectionFeedKWh,
            'gridEuro' => $projectionGridEuro,
            'feedEuro' => $projectionFeedEuro,
            'balanceEuro' => $projectionFeedEuro - $projectionGridEuro,
            'method' => $projectionMethod,
            'provisional' => $projectionProvisional,
            'gridBasisStart' => $gridBasisStart,
            'feedBasisStart' => $feedBasisStart,
            'missingMonths' => $forecast['missingMonths'] ?? []
        ];

        return [
            'ok' => true,
            'instanceID' => $this->InstanceID,
            'cycleSeconds' => $cycle,
            'updated' => $now,
            'sourceMode' => $legacy ? 'Bestandszähler' : 'Archiv',
            'prices' => [
                'grid' => $gridPrice,
                'feed' => $feedPrice,
                'oilKWhPerLiter' => $oilKWh,
                'efficiencyPercent' => $efficiency * 100.0
            ],
            'summary' => $summary,
            'charts' => $charts,
            'coverage' => [
                'grid' => $coverageGrid,
                'feed' => $coverageFeed,
                'heater' => $coverageHeat,
                'todayComplete' => $coverageGrid > 0 && $coverageGrid <= $todayStart && $coverageFeed > 0 && $coverageFeed <= $todayStart,
                'monthComplete' => $coverageGrid > 0 && $coverageGrid <= $monthStart && $coverageFeed > 0 && $coverageFeed <= $monthStart,
                'yearComplete' => $coverageGrid > 0 && $coverageGrid <= $yearStart && $coverageFeed > 0 && $coverageFeed <= $yearStart,
                'last30Complete' => $coverageGrid > 0 && $coverageGrid <= $last30Start && $coverageFeed > 0 && $coverageFeed <= $last30Start
            ],
            'historySources' => [
                'grid' => [
                    'id' => self::GRID_KWH_DAY,
                    'logged' => $this->IsNumericVariable(self::GRID_KWH_DAY) && AC_GetLoggingStatus($archiveID, self::GRID_KWH_DAY),
                    'first' => $this->FirstTimeFor($meta, self::GRID_KWH_DAY)
                ],
                'feed' => [
                    'id' => self::FEED_KWH_DAY,
                    'logged' => $this->IsNumericVariable(self::FEED_KWH_DAY) && AC_GetLoggingStatus($archiveID, self::FEED_KWH_DAY),
                    'first' => $this->FirstTimeFor($meta, self::FEED_KWH_DAY)
                ],
                'heater' => [
                    'id' => self::HEAT_KWH_DAY,
                    'logged' => $this->IsNumericVariable(self::HEAT_KWH_DAY) && AC_GetLoggingStatus($archiveID, self::HEAT_KWH_DAY),
                    'first' => $this->FirstTimeFor($meta, self::HEAT_KWH_DAY)
                ]
            ],
            'actualCoverage' => [
                'energyPercent' => $yearGridKWh > 0.0 ? ($yearFeedKWh / $yearGridKWh) * 100.0 : null,
                'energyMultiple' => $yearGridKWh > 0.0 ? ($yearFeedKWh / $yearGridKWh) : null,
                'energyBalanceKWh' => $yearFeedKWh - $yearGridKWh,
                'financialPercent' => $yearGridEuro > 0.0 ? ($yearFeedEuro / $yearGridEuro) * 100.0 : null,
                'balanceEuro' => $yearFeedEuro - $yearGridEuro
            ],
            'yearProjection' => $yearProjection,
            'forecast' => $forecast
        ];
    }

    private function HasLegacySummary(): bool
    {
        foreach ([
            self::GRID_KWH_DAY, self::GRID_KWH_WEEK, self::GRID_KWH_MONTH, self::GRID_KWH_YEAR,
            self::FEED_KWH_DAY, self::FEED_KWH_WEEK, self::FEED_KWH_MONTH, self::FEED_KWH_YEAR,
            self::HEAT_KWH_DAY, self::HEAT_KWH_WEEK, self::HEAT_KWH_MONTH, self::HEAT_KWH_YEAR
        ] as $id) {
            if (!$this->IsNumericVariable($id)) {
                return false;
            }
        }
        return true;
    }

    private function BuildLegacySummary(array $dayGrid, array $dayFeed, array $dayHeat, float $gridPrice, float $feedPrice, float $oilKWh, float $efficiency): array
    {
        $make = function (int $gK, int $gE, int $fK, int $fE, int $hK) use ($gridPrice, $feedPrice, $oilKWh, $efficiency): array {
            $grid = $this->SafeValue($gK);
            $feed = $this->SafeValue($fK);
            $heat = $this->SafeValue($hK);
            $gridEuro = $this->IsNumericVariable($gE) ? $this->SafeValue($gE) : $grid * $gridPrice;
            $feedEuro = $this->IsNumericVariable($fE) ? $this->SafeValue($fE) : $feed * $feedPrice;
            return [
                'grid' => ['kwh' => $grid, 'euro' => $gridEuro],
                'feed' => ['kwh' => $feed, 'euro' => $feedEuro],
                'heater' => ['kwh' => $heat, 'oilLiter' => $heat / ($oilKWh * $efficiency)]
            ];
        };

        $last30 = $this->DecoratePeriod([
            'grid' => $this->SumSeries($dayGrid),
            'feed' => $this->SumSeries($dayFeed),
            'heater' => $this->SumSeries($dayHeat)
        ], $gridPrice, $feedPrice, $oilKWh, $efficiency);

        return [
            'today' => $make(self::GRID_KWH_DAY, self::GRID_EUR_DAY, self::FEED_KWH_DAY, self::FEED_EUR_DAY, self::HEAT_KWH_DAY),
            'week' => $make(self::GRID_KWH_WEEK, self::GRID_EUR_WEEK, self::FEED_KWH_WEEK, self::FEED_EUR_WEEK, self::HEAT_KWH_WEEK),
            'month' => $make(self::GRID_KWH_MONTH, self::GRID_EUR_MONTH, self::FEED_KWH_MONTH, self::FEED_EUR_MONTH, self::HEAT_KWH_MONTH),
            'last30' => $last30,
            'year' => $make(self::GRID_KWH_YEAR, self::GRID_EUR_YEAR, self::FEED_KWH_YEAR, self::FEED_EUR_YEAR, self::HEAT_KWH_YEAR)
        ];
    }

    private function BuildArchiveSummary(int $archiveID, int $gridID, int $feedID, int $heaterID, array $dayGrid, array $dayFeed, array $dayHeat, float $gridPrice, float $feedPrice, float $oilKWh, float $efficiency, int $todayStart, int $weekStart, int $monthStart, int $yearStart, int $now): array
    {
        $today = [
            'grid' => $this->CounterDeltaRaw($archiveID, $gridID, $todayStart, $now, self::FRONIUS_WH_PER_KWH),
            'feed' => $this->CounterDeltaRaw($archiveID, $feedID, $todayStart, $now, self::FRONIUS_WH_PER_KWH),
            'heater' => $this->CounterDeltaRaw($archiveID, $heaterID, $todayStart, $now, 1.0)
        ];
        $week = [
            'grid' => $this->CounterDeltaRaw($archiveID, $gridID, $weekStart, $now, self::FRONIUS_WH_PER_KWH),
            'feed' => $this->CounterDeltaRaw($archiveID, $feedID, $weekStart, $now, self::FRONIUS_WH_PER_KWH),
            'heater' => $this->CounterDeltaRaw($archiveID, $heaterID, $weekStart, $now, 1.0)
        ];
        $month = [
            'grid' => $this->CounterDeltaRaw($archiveID, $gridID, $monthStart, $now, self::FRONIUS_WH_PER_KWH),
            'feed' => $this->CounterDeltaRaw($archiveID, $feedID, $monthStart, $now, self::FRONIUS_WH_PER_KWH),
            'heater' => $this->CounterDeltaRaw($archiveID, $heaterID, $monthStart, $now, 1.0)
        ];
        $year = [
            'grid' => $this->CounterDeltaRaw($archiveID, $gridID, $yearStart, $now, self::FRONIUS_WH_PER_KWH),
            'feed' => $this->CounterDeltaRaw($archiveID, $feedID, $yearStart, $now, self::FRONIUS_WH_PER_KWH),
            'heater' => $this->CounterDeltaRaw($archiveID, $heaterID, $yearStart, $now, 1.0)
        ];
        $last30 = [
            'grid' => $this->SumSeries($dayGrid),
            'feed' => $this->SumSeries($dayFeed),
            'heater' => $this->SumSeries($dayHeat)
        ];

        return [
            'today' => $this->DecoratePeriod($today, $gridPrice, $feedPrice, $oilKWh, $efficiency),
            'week' => $this->DecoratePeriod($week, $gridPrice, $feedPrice, $oilKWh, $efficiency),
            'month' => $this->DecoratePeriod($month, $gridPrice, $feedPrice, $oilKWh, $efficiency),
            'last30' => $this->DecoratePeriod($last30, $gridPrice, $feedPrice, $oilKWh, $efficiency),
            'year' => $this->DecoratePeriod($year, $gridPrice, $feedPrice, $oilKWh, $efficiency)
        ];
    }

    private function GetArchiveMetaMap(int $archiveID): array
    {
        $map = [];
        foreach (AC_GetAggregationVariables($archiveID, false) as $row) {
            $map[(int) $row['VariableID']] = $row;
        }
        return $map;
    }

    private function FirstTimeFor(array $meta, int $variableID): int
    {
        if (!isset($meta[$variableID])) {
            return 0;
        }
        return isset($meta[$variableID]['FirstTime']) ? (int) $meta[$variableID]['FirstTime'] : 0;
    }

    private function GetDailyTotalsFromResetVariable(int $archiveID, int $variableID, int $start, int $end, int $firstTime): array
    {
        if (!$this->IsNumericVariable($variableID) || !AC_GetLoggingStatus($archiveID, $variableID)) {
            return [];
        }

        $type = AC_GetAggregationType($archiveID, $variableID);
        $rows = AC_GetAggregatedValues($archiveID, $variableID, 1, $start, $end, 0);
        $series = [];
        foreach ($rows as $row) {
            $ts = (int) $row['TimeStamp'];
            $duration = (int) ($row['Duration'] ?? 86400);
            $periodEnd = $ts + max(1, $duration);
            if ($firstTime > 0 && $periodEnd <= $firstTime) {
                continue;
            }
            $value = $type === 1 ? (float) ($row['Avg'] ?? 0.0) : (float) ($row['Max'] ?? 0.0);
            $series[] = ['ts' => $ts, 'value' => max(0.0, $value)];
        }
        usort($series, static fn(array $a, array $b): int => $a['ts'] <=> $b['ts']);
        return $series;
    }

    private function GetHourlyPositiveDeltasFromResetVariable(int $archiveID, int $variableID, int $start, int $end): array
    {
        if (!$this->IsNumericVariable($variableID) || !AC_GetLoggingStatus($archiveID, $variableID)) {
            return [];
        }
        $rows = AC_GetLoggedValues($archiveID, $variableID, $start, $end, 0);
        if (count($rows) === 0) {
            return [];
        }
        usort($rows, static fn(array $a, array $b): int => ((int) $a['TimeStamp']) <=> ((int) $b['TimeStamp']));

        $hourStart = strtotime(date('Y-m-d H:00:00', $start));
        $lastHour = strtotime(date('Y-m-d H:00:00', $end));
        $buckets = [];
        for ($ts = $hourStart; $ts <= $lastHour; $ts += 3600) {
            $buckets[$ts] = 0.0;
        }

        $prev = null;
        foreach ($rows as $row) {
            $v = max(0.0, (float) $row['Value']);
            if ($prev !== null) {
                $delta = $v - $prev;
                if ($delta > 0.0) {
                    $bucket = strtotime(date('Y-m-d H:00:00', (int) $row['TimeStamp']));
                    if (isset($buckets[$bucket])) {
                        $buckets[$bucket] += $delta;
                    }
                }
            }
            $prev = $v;
        }

        $out = [];
        foreach ($buckets as $ts => $value) {
            $out[] = ['ts' => (int) $ts, 'value' => $value];
        }
        return $out;
    }

    private function GetCounterAggregates(int $archiveID, int $variableID, int $level, int $start, int $end, float $divisor, int $firstTime): array
    {
        if (!$this->IsNumericVariable($variableID) || !AC_GetLoggingStatus($archiveID, $variableID)) {
            return [];
        }
        $type = AC_GetAggregationType($archiveID, $variableID);
        $rows = AC_GetAggregatedValues($archiveID, $variableID, $level, $start, $end, 0);
        $series = [];
        foreach ($rows as $row) {
            $ts = (int) $row['TimeStamp'];
            $duration = (int) ($row['Duration'] ?? 0);
            $periodEnd = $duration > 0 ? $ts + $duration : $ts;

            // Wenn das Logging innerhalb dieser Periode begann, kann bei einem
            // Zähler der vorhandene Startwert als erste positive Differenz auftauchen.
            if ($firstTime > 0 && $firstTime >= $ts && $firstTime < $periodEnd) {
                continue;
            }

            if ($type === 1) {
                $value = (float) ($row['Avg'] ?? 0.0);
            } else {
                $value = max(0.0, (float) ($row['Max'] ?? 0.0) - (float) ($row['Min'] ?? 0.0));
            }
            $series[] = ['ts' => $ts, 'value' => max(0.0, $value / $divisor)];
        }
        usort($series, static fn(array $a, array $b): int => $a['ts'] <=> $b['ts']);
        return $series;
    }

    private function CounterDeltaRaw(int $archiveID, int $variableID, int $start, int $end, float $divisor): float
    {
        if (!$this->IsNumericVariable($variableID) || !AC_GetLoggingStatus($archiveID, $variableID)) {
            return 0.0;
        }
        $prev = AC_GetLoggedValues($archiveID, $variableID, 0, max(0, $start - 1), 1);
        $inside = AC_GetLoggedValues($archiveID, $variableID, $start, $end, 1);
        if (count($prev) === 0 || count($inside) === 0) {
            return 0.0;
        }
        $base = (float) $prev[0]['Value'];
        $last = (float) $inside[0]['Value'];
        return max(0.0, ($last - $base) / $divisor);
    }

    private function SumSeries(array $series): float
    {
        $sum = 0.0;
        foreach ($series as $row) {
            $sum += (float) $row['value'];
        }
        return $sum;
    }

    private function FilterSeriesFrom(array $series, int $start): array
    {
        return array_values(array_filter($series, static fn(array $row): bool => (int) $row['ts'] >= $start));
    }

    private function GroupDailySeriesByMonth(array $daily): array
    {
        $map = [];
        foreach ($daily as $row) {
            $ts = strtotime(date('Y-m-01 00:00:00', (int) $row['ts']));
            if (!isset($map[$ts])) $map[$ts] = 0.0;
            $map[$ts] += (float) $row['value'];
        }
        ksort($map, SORT_NUMERIC);
        $out = [];
        foreach ($map as $ts => $value) {
            $out[] = ['ts' => (int) $ts, 'value' => $value];
        }
        return $out;
    }

    private function GroupDailySeriesByMonthYear(array $daily): array
    {
        // Gleiche Struktur wie monatliche Archive-Serie: ein Datensatz je Jahr/Monat.
        return $this->GroupDailySeriesByMonth($daily);
    }

    private function DecoratePeriod(array $period, float $gridPrice, float $feedPrice, float $oilKWh, float $efficiency): array
    {
        $grid = max(0.0, (float) ($period['grid'] ?? 0.0));
        $feed = max(0.0, (float) ($period['feed'] ?? 0.0));
        $heater = max(0.0, (float) ($period['heater'] ?? 0.0));
        return [
            'grid' => ['kwh' => $grid, 'euro' => $grid * $gridPrice],
            'feed' => ['kwh' => $feed, 'euro' => $feed * $feedPrice],
            'heater' => ['kwh' => $heater, 'oilLiter' => $heater / ($oilKWh * $efficiency)]
        ];
    }

    private function CombineChartSeries(array $grid, array $feed, string $mode): array
    {
        $gridMap = [];
        foreach ($grid as $row) $gridMap[(int) $row['ts']] = (float) $row['value'];
        $feedMap = [];
        foreach ($feed as $row) $feedMap[(int) $row['ts']] = (float) $row['value'];

        $timestamps = array_unique(array_merge(array_keys($gridMap), array_keys($feedMap)));
        sort($timestamps, SORT_NUMERIC);
        $out = [];
        foreach ($timestamps as $ts) {
            $out[] = [
                'ts' => (int) $ts,
                'label' => $this->ChartLabel((int) $ts, $mode),
                'grid' => $gridMap[(int) $ts] ?? 0.0,
                'feed' => $feedMap[(int) $ts] ?? 0.0
            ];
        }
        return $out;
    }

    private function ChartLabel(int $ts, string $mode): string
    {
        if ($mode === 'day') return date('H:i', $ts);
        if ($mode === 'week') {
            $days = [1 => 'Mo', 2 => 'Di', 3 => 'Mi', 4 => 'Do', 5 => 'Fr', 6 => 'Sa', 7 => 'So'];
            return $days[(int) date('N', $ts)] . ' ' . date('d.m.', $ts);
        }
        if ($mode === 'month') return date('d.m.', $ts);
        $months = [1 => 'Jan', 2 => 'Feb', 3 => 'Mär', 4 => 'Apr', 5 => 'Mai', 6 => 'Jun', 7 => 'Jul', 8 => 'Aug', 9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Dez'];
        return $months[(int) date('n', $ts)];
    }

    private function SeasonalAnnualForecast(array $monthlySeries, int $coverageStart, int $now): array
    {
        $currentYear = (int) date('Y', $now);
        $currentMonth = (int) date('n', $now);
        $today = (int) date('j', $now);

        $byYearMonth = [];
        foreach ($monthlySeries as $row) {
            $ts = (int) $row['ts'];
            $y = (int) date('Y', $ts);
            $m = (int) date('n', $ts);
            $byYearMonth[$y][$m] = (float) $row['value'];
        }

        $historical = [];
        for ($m = 1; $m <= 12; $m++) {
            $samples = [];
            foreach ($byYearMonth as $year => $months) {
                if ((int) $year >= $currentYear || !isset($months[$m])) continue;
                $monthStart = strtotime(sprintf('%04d-%02d-01 00:00:00', (int) $year, $m));
                $monthEnd = strtotime('+1 month', $monthStart);
                if ($coverageStart > 0 && $coverageStart <= $monthStart && $monthEnd <= $now) {
                    $samples[] = (float) $months[$m];
                }
            }
            if (count($samples) > 0) {
                $historical[$m] = ['avg' => array_sum($samples) / count($samples), 'samples' => count($samples)];
            }
        }

        $total = 0.0;
        $missing = [];
        $minSamples = PHP_INT_MAX;
        $usedForecastMonths = 0;

        for ($m = 1; $m <= 12; $m++) {
            $monthStart = strtotime(sprintf('%04d-%02d-01 00:00:00', $currentYear, $m));
            $hasFullCurrentCoverage = $coverageStart > 0 && $coverageStart <= $monthStart;

            if ($m < $currentMonth && $hasFullCurrentCoverage && isset($byYearMonth[$currentYear][$m])) {
                $total += (float) $byYearMonth[$currentYear][$m];
                continue;
            }
            if ($m === $currentMonth && $hasFullCurrentCoverage && isset($byYearMonth[$currentYear][$m]) && $today >= 7) {
                $daysInMonth = (int) date('t', $now);
                $mtd = (float) $byYearMonth[$currentYear][$m];
                $total += $mtd * ($daysInMonth / max(1, $today));
                $usedForecastMonths++;
                $minSamples = min($minSamples, 1);
                continue;
            }
            if (isset($historical[$m])) {
                $total += (float) $historical[$m]['avg'];
                $usedForecastMonths++;
                $minSamples = min($minSamples, (int) $historical[$m]['samples']);
                continue;
            }
            $missing[] = $m;
        }

        $complete = count($missing) === 0;
        $quality = 'niedrig';
        if ($complete) $quality = $minSamples >= 2 ? 'hoch' : 'mittel';

        return [
            'complete' => $complete,
            'value' => $complete ? $total : null,
            'quality' => $quality,
            'missingMonths' => $missing,
            'forecastMonths' => $usedForecastMonths
        ];
    }

    private function BuildForecastResult(array $grid, array $feed, array $heater, float $gridPrice, float $feedPrice, float $oilKWh, float $efficiency): array
    {
        $complete = (bool) $grid['complete'] && (bool) $feed['complete'];
        $months = array_values(array_unique(array_merge($grid['missingMonths'], $feed['missingMonths'])));
        sort($months);

        $quality = 'niedrig';
        if ($complete) $quality = ($grid['quality'] === 'hoch' && $feed['quality'] === 'hoch') ? 'hoch' : 'mittel';

        $result = [
            'complete' => $complete,
            'quality' => $quality,
            'missingMonths' => $months,
            'gridKWh' => null,
            'feedKWh' => null,
            'energyCoveragePercent' => null,
            'gridEuro' => null,
            'feedEuro' => null,
            'financialCoveragePercent' => null,
            'balanceEuro' => null,
            'heaterKWh' => null,
            'oilLiter' => null
        ];

        if ($complete) {
            $gridKWh = (float) $grid['value'];
            $feedKWh = (float) $feed['value'];
            $gridEuro = $gridKWh * $gridPrice;
            $feedEuro = $feedKWh * $feedPrice;
            $result['gridKWh'] = $gridKWh;
            $result['feedKWh'] = $feedKWh;
            $result['energyCoveragePercent'] = $gridKWh > 0.0 ? ($feedKWh / $gridKWh) * 100.0 : null;
            $result['gridEuro'] = $gridEuro;
            $result['feedEuro'] = $feedEuro;
            $result['financialCoveragePercent'] = $gridEuro > 0.0 ? ($feedEuro / $gridEuro) * 100.0 : null;
            $result['balanceEuro'] = $feedEuro - $gridEuro;
        }

        if ((bool) $heater['complete'] && $heater['value'] !== null) {
            $heaterKWh = (float) $heater['value'];
            $result['heaterKWh'] = $heaterKWh;
            $result['oilLiter'] = $heaterKWh / ($oilKWh * $efficiency);
        }
        return $result;
    }
}
