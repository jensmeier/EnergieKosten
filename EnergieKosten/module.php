<?php

declare(strict_types=1);

class EnergieKosten extends IPSModuleStrict
{
    private const ARCHIVE_MODULE_GUID = '{43192F0B-135B-4CE7-A0A7-1475603F3060}';
    private const FRONIUS_WH_PER_KWH = 1000.0;
    private const HEATER_MIN_POWER_W = 50.0;
    private const TIMER_MS = 60000;

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

        $heaterTotalID = $this->GetIDForIdent('HeaterTotalKWh');

        if ($valid && $this->ReadPropertyBoolean('AutoArchive')) {
            $archiveID = $this->GetArchiveID();
            if ($archiveID > 0) {
                $this->EnsureCounterArchive($archiveID, $gridID);
                $this->EnsureCounterArchive($archiveID, $feedID);
                $this->EnsureCounterArchive($archiveID, $heaterTotalID);
                $this->WriteAttributeInteger('ArchiveConfiguredAt', time());
            } else {
                $valid = false;
            }
        }

        // Nach Konfigurationsänderungen nie rückwirkend Heizleistung hochrechnen.
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
            // IP-Symcon 9.0 kann über UpdateVisualizationValue einfache Werte
            // direkt übertragen. Komplexe Arrays/Objekte werden für die
            // HTML-SDK-Nachricht als JSON-String codiert.
            $payload = json_encode(
                $this->BuildPayload(),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
            );

            if ($payload === false) {
                throw new Exception('Visualisierungsdaten konnten nicht als JSON codiert werden.');
            }

            $this->UpdateVisualizationValue($payload);
        } catch (Throwable $e) {
            $this->SendDebug('Refresh', $e->getMessage(), 0);
        }
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        switch ($Ident) {
            case 'Refresh':
                $this->Refresh();
                break;
            default:
                throw new Exception('Invalid Ident: ' . $Ident);
        }
    }

    public function GetVisualizationTile(): string
    {
        $html = file_get_contents(__DIR__ . '/module.html');
        if ($html === false) {
            return '<div>module.html konnte nicht geladen werden.</div>';
        }

        $payload = $this->BuildPayload();
        $json = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
        if ($json === false) {
            $json = '{}';
        }

        $html = str_replace('__INSTANCE_ID__', (string) $this->InstanceID, $html);
        $html = str_replace('__INITIAL_DATA__', $json, $html);
        return $html;
    }

    private function IsNumericVariable(int $id): bool
    {
        if ($id <= 0 || !IPS_VariableExists($id)) {
            return false;
        }
        $variable = IPS_GetVariable($id);
        return in_array((int) $variable['VariableType'], [VARIABLETYPE_INTEGER, VARIABLETYPE_FLOAT], true);
    }

    private function GetArchiveID(): int
    {
        $ids = IPS_GetInstanceListByModuleID(self::ARCHIVE_MODULE_GUID);
        return count($ids) > 0 ? (int) $ids[0] : 0;
    }

    private function EnsureCounterArchive(int $archiveID, int $variableID): void
    {
        if (!AC_GetLoggingStatus($archiveID, $variableID)) {
            AC_SetLoggingStatus($archiveID, $variableID, true);
        }

        if (AC_GetAggregationType($archiveID, $variableID) !== 1) {
            AC_SetAggregationType($archiveID, $variableID, 1);
            // Laut Symcon-Dokumentation ist nach Änderung des Aggregationstyps eine Reaggregation erforderlich.
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
        // Keine Nachberechnung über längere Dienst-/Netzausfälle mit der aktuellen Leistung.
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
        $heaterID = $this->GetIDForIdent('HeaterTotalKWh');

        $now = time();
        $todayStart = strtotime('today 00:00:00', $now);
        $weekStart = strtotime('monday this week 00:00:00', $now);
        $monthStart = strtotime(date('Y-m-01 00:00:00', $now));
        $yearStart = strtotime(date('Y-01-01 00:00:00', $now));
        $last30Start = strtotime('today -29 days', $now);
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
        $coverage = [
            'grid' => $this->FirstTimeFor($meta, $gridID),
            'feed' => $this->FirstTimeFor($meta, $feedID),
            'heater' => $this->FirstTimeFor($meta, $heaterID)
        ];

        $hourGrid = $this->GetEnergySeries($archiveID, $gridID, 0, $todayStart, $now, self::FRONIUS_WH_PER_KWH, $coverage['grid']);
        $hourFeed = $this->GetEnergySeries($archiveID, $feedID, 0, $todayStart, $now, self::FRONIUS_WH_PER_KWH, $coverage['feed']);
        $hourHeat = $this->GetEnergySeries($archiveID, $heaterID, 0, $todayStart, $now, 1.0, $coverage['heater']);

        $dayGrid = $this->GetEnergySeries($archiveID, $gridID, 1, $last30Start, $now, self::FRONIUS_WH_PER_KWH, $coverage['grid']);
        $dayFeed = $this->GetEnergySeries($archiveID, $feedID, 1, $last30Start, $now, self::FRONIUS_WH_PER_KWH, $coverage['feed']);
        $dayHeat = $this->GetEnergySeries($archiveID, $heaterID, 1, $last30Start, $now, 1.0, $coverage['heater']);

        $monthGrid = $this->GetEnergySeries($archiveID, $gridID, 3, $historyStart, $now, self::FRONIUS_WH_PER_KWH, $coverage['grid']);
        $monthFeed = $this->GetEnergySeries($archiveID, $feedID, 3, $historyStart, $now, self::FRONIUS_WH_PER_KWH, $coverage['feed']);
        $monthHeat = $this->GetEnergySeries($archiveID, $heaterID, 3, $historyStart, $now, 1.0, $coverage['heater']);

        $today = [
            'grid' => $this->SumSeries($hourGrid),
            'feed' => $this->SumSeries($hourFeed),
            'heater' => $this->SumSeries($hourHeat)
        ];
        $week = [
            'grid' => $this->SumSeriesFrom($dayGrid, $weekStart),
            'feed' => $this->SumSeriesFrom($dayFeed, $weekStart),
            'heater' => $this->SumSeriesFrom($dayHeat, $weekStart)
        ];
        $last30 = [
            'grid' => $this->SumSeries($dayGrid),
            'feed' => $this->SumSeries($dayFeed),
            'heater' => $this->SumSeries($dayHeat)
        ];
        $month = [
            'grid' => $this->ValueForMonth($monthGrid, (int) date('Y', $now), (int) date('n', $now)),
            'feed' => $this->ValueForMonth($monthFeed, (int) date('Y', $now), (int) date('n', $now)),
            'heater' => $this->ValueForMonth($monthHeat, (int) date('Y', $now), (int) date('n', $now))
        ];
        $year = [
            'grid' => $this->SumSeriesFrom($monthGrid, $yearStart),
            'feed' => $this->SumSeriesFrom($monthFeed, $yearStart),
            'heater' => $this->SumSeriesFrom($monthHeat, $yearStart)
        ];

        $summary = [
            'today' => $this->DecoratePeriod($today, $gridPrice, $feedPrice, $oilKWh, $efficiency),
            'week' => $this->DecoratePeriod($week, $gridPrice, $feedPrice, $oilKWh, $efficiency),
            'month' => $this->DecoratePeriod($month, $gridPrice, $feedPrice, $oilKWh, $efficiency),
            'last30' => $this->DecoratePeriod($last30, $gridPrice, $feedPrice, $oilKWh, $efficiency),
            'year' => $this->DecoratePeriod($year, $gridPrice, $feedPrice, $oilKWh, $efficiency)
        ];

        $charts = [
            'day' => $this->CombineChartSeries($hourGrid, $hourFeed, 'day'),
            'week' => $this->CombineChartSeries(
                $this->FilterSeriesFrom($dayGrid, $weekStart),
                $this->FilterSeriesFrom($dayFeed, $weekStart),
                'week'
            ),
            'month' => $this->CombineChartSeries($dayGrid, $dayFeed, 'month'),
            'year' => $this->CombineChartSeries(
                $this->FilterSeriesFrom($monthGrid, $yearStart),
                $this->FilterSeriesFrom($monthFeed, $yearStart),
                'year'
            )
        ];

        $forecastGrid = $this->SeasonalAnnualForecast($monthGrid, $coverage['grid'], $now);
        $forecastFeed = $this->SeasonalAnnualForecast($monthFeed, $coverage['feed'], $now);
        $forecastHeat = $this->SeasonalAnnualForecast($monthHeat, $coverage['heater'], $now);

        $forecast = $this->BuildForecastResult(
            $forecastGrid,
            $forecastFeed,
            $forecastHeat,
            $gridPrice,
            $feedPrice,
            $oilKWh,
            $efficiency
        );

        $actualEnergyCoverage = $year['grid'] > 0.0 ? ($year['feed'] / $year['grid']) * 100.0 : null;
        $actualGridEuro = $year['grid'] * $gridPrice;
        $actualFeedEuro = $year['feed'] * $feedPrice;
        $actualFinancialCoverage = $actualGridEuro > 0.0 ? ($actualFeedEuro / $actualGridEuro) * 100.0 : null;

        return [
            'ok' => true,
            'instanceID' => $this->InstanceID,
            'cycleSeconds' => $cycle,
            'updated' => $now,
            'prices' => [
                'grid' => $gridPrice,
                'feed' => $feedPrice,
                'oilKWhPerLiter' => $oilKWh,
                'efficiencyPercent' => $efficiency * 100.0
            ],
            'summary' => $summary,
            'charts' => $charts,
            'coverage' => [
                'grid' => $coverage['grid'],
                'feed' => $coverage['feed'],
                'heater' => $coverage['heater'],
                'todayComplete' => $this->CoverageComplete($coverage, $todayStart),
                'monthComplete' => $this->CoverageComplete($coverage, $monthStart),
                'yearComplete' => $this->CoverageComplete($coverage, $yearStart),
                'last30Complete' => $this->CoverageComplete($coverage, $last30Start)
            ],
            'actualCoverage' => [
                'energyPercent' => $actualEnergyCoverage,
                'financialPercent' => $actualFinancialCoverage,
                'balanceEuro' => $actualFeedEuro - $actualGridEuro
            ],
            'forecast' => $forecast
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

    private function GetEnergySeries(
        int $archiveID,
        int $variableID,
        int $level,
        int $start,
        int $end,
        float $divisor,
        int $firstTime
    ): array {
        if ($variableID <= 0 || !$this->IsNumericVariable($variableID)) {
            return [];
        }

        $rows = AC_GetAggregatedValues($archiveID, $variableID, $level, $start, $end, 0);
        $series = [];
        foreach ($rows as $row) {
            $ts = (int) $row['TimeStamp'];
            $duration = isset($row['Duration']) ? (int) $row['Duration'] : 0;
            $periodEnd = $duration > 0 ? $ts + $duration : $ts;

            // Perioden vollständig vor Beginn des Archivs nicht als echte Nullwerte ausgeben.
            if ($firstTime > 0 && $periodEnd <= $firstTime) {
                continue;
            }

            $series[] = [
                'ts' => $ts,
                'value' => ((float) $row['Avg']) / $divisor
            ];
        }

        usort($series, static fn(array $a, array $b): int => $a['ts'] <=> $b['ts']);
        return $series;
    }

    private function SumSeries(array $series): float
    {
        $sum = 0.0;
        foreach ($series as $row) {
            $sum += (float) $row['value'];
        }
        return $sum;
    }

    private function SumSeriesFrom(array $series, int $start): float
    {
        $sum = 0.0;
        foreach ($series as $row) {
            if ((int) $row['ts'] >= $start) {
                $sum += (float) $row['value'];
            }
        }
        return $sum;
    }

    private function FilterSeriesFrom(array $series, int $start): array
    {
        return array_values(array_filter(
            $series,
            static fn(array $row): bool => (int) $row['ts'] >= $start
        ));
    }

    private function ValueForMonth(array $series, int $year, int $month): float
    {
        foreach ($series as $row) {
            if ((int) date('Y', (int) $row['ts']) === $year && (int) date('n', (int) $row['ts']) === $month) {
                return (float) $row['value'];
            }
        }
        return 0.0;
    }

    private function DecoratePeriod(array $period, float $gridPrice, float $feedPrice, float $oilKWh, float $efficiency): array
    {
        $grid = max(0.0, (float) ($period['grid'] ?? 0.0));
        $feed = max(0.0, (float) ($period['feed'] ?? 0.0));
        $heater = max(0.0, (float) ($period['heater'] ?? 0.0));
        $oil = $heater / ($oilKWh * $efficiency);

        return [
            'grid' => ['kwh' => $grid, 'euro' => $grid * $gridPrice],
            'feed' => ['kwh' => $feed, 'euro' => $feed * $feedPrice],
            'heater' => ['kwh' => $heater, 'oilLiter' => $oil]
        ];
    }

    private function CombineChartSeries(array $grid, array $feed, string $mode): array
    {
        $gridMap = [];
        foreach ($grid as $row) {
            $gridMap[(int) $row['ts']] = (float) $row['value'];
        }
        $feedMap = [];
        foreach ($feed as $row) {
            $feedMap[(int) $row['ts']] = (float) $row['value'];
        }

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
        if ($mode === 'day') {
            return date('H:i', $ts);
        }
        if ($mode === 'week') {
            $days = [1 => 'Mo', 2 => 'Di', 3 => 'Mi', 4 => 'Do', 5 => 'Fr', 6 => 'Sa', 7 => 'So'];
            return $days[(int) date('N', $ts)] . ' ' . date('d.m.', $ts);
        }
        if ($mode === 'month') {
            return date('d.m.', $ts);
        }
        $months = [1 => 'Jan', 2 => 'Feb', 3 => 'Mär', 4 => 'Apr', 5 => 'Mai', 6 => 'Jun', 7 => 'Jul', 8 => 'Aug', 9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Dez'];
        return $months[(int) date('n', $ts)];
    }

    private function SeasonalAnnualForecast(array $monthlySeries, int $coverageStart, int $now): array
    {
        $currentYear = (int) date('Y', $now);
        $currentMonth = (int) date('n', $now);
        $today = (int) date('j', $now);
        $currentMonthStart = strtotime(date('Y-m-01 00:00:00', $now));

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
                if ((int) $year >= $currentYear || !isset($months[$m])) {
                    continue;
                }
                $monthStart = strtotime(sprintf('%04d-%02d-01 00:00:00', (int) $year, $m));
                $monthEnd = strtotime('+1 month', $monthStart);
                if ($coverageStart > 0 && $coverageStart <= $monthStart && $monthEnd <= $now) {
                    $samples[] = (float) $months[$m];
                }
            }
            if (count($samples) > 0) {
                $historical[$m] = [
                    'avg' => array_sum($samples) / count($samples),
                    'samples' => count($samples)
                ];
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
        if ($complete) {
            if ($minSamples >= 2) {
                $quality = 'hoch';
            } else {
                $quality = 'mittel';
            }
        }

        return [
            'complete' => $complete,
            'value' => $complete ? $total : null,
            'quality' => $quality,
            'missingMonths' => $missing,
            'forecastMonths' => $usedForecastMonths
        ];
    }

    private function BuildForecastResult(
        array $grid,
        array $feed,
        array $heater,
        float $gridPrice,
        float $feedPrice,
        float $oilKWh,
        float $efficiency
    ): array {
        $complete = (bool) $grid['complete'] && (bool) $feed['complete'];
        $months = array_values(array_unique(array_merge($grid['missingMonths'], $feed['missingMonths'])));
        sort($months);

        $quality = 'niedrig';
        if ($complete) {
            $quality = ($grid['quality'] === 'hoch' && $feed['quality'] === 'hoch') ? 'hoch' : 'mittel';
        }

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

    private function CoverageComplete(array $coverage, int $periodStart): bool
    {
        foreach ($coverage as $firstTime) {
            if ((int) $firstTime <= 0 || (int) $firstTime > $periodStart) {
                return false;
            }
        }
        return true;
    }
}
