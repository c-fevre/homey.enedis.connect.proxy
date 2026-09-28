<?php
namespace App\Tests\Unit;

use App\Util\EnedisTranslator;
use PHPUnit\Framework\TestCase;

class EnedisTranslatorTest extends TestCase
{
    const PRM = '12345678901234';

    private function plan(string $path, array $query = []): ?array
    {
        return EnedisTranslator::plan($path, $query + ['usage_point_id' => self::PRM, 'start' => '2026-09-26', 'end' => '2026-09-27']);
    }

    // ------------------------------------------------------------ plan()

    /** @dataProvider measurePaths */
    public function testPlanMapsLegacyMeasurePaths(string $legacy, string $expectedPath, string $metier, string $type): void
    {
        $plan = $this->plan($legacy);
        $this->assertNotNull($plan);
        $this->assertSame('measure', $plan['kind']);
        $this->assertSame($expectedPath, $plan['path']);
        $this->assertSame($metier, $plan['metier']);
        $this->assertSame($type, $plan['type']);
        $this->assertSame(self::PRM, $plan['query']['pointId']);
        $this->assertSame('2026-09-26', $plan['query']['dateDebut']);
        $this->assertSame('2026-09-27', $plan['query']['dateFin']);
        $this->assertArrayNotHasKey('usage_point_id', $plan['query']);
        $this->assertArrayNotHasKey('start', $plan['query']);
    }

    public static function measurePaths(): array
    {
        return [
            ['metering_data_dc/v5/daily_consumption', 'mesure_synchrone_auto/v2/consommation_quotidienne', 'CONS', 'daily'],
            ['metering_data_clc/v5/consumption_load_curve', 'mesure_synchrone_auto/v2/courbe_de_charge_consommation', 'CONS', 'load_curve'],
            ['metering_data_dp/v5/daily_production', 'mesure_synchrone_auto/v2/production_quotidienne', 'PROD', 'daily'],
            ['metering_data_plc/v5/production_load_curve', 'mesure_synchrone_auto/v2/courbe_de_charge_production', 'PROD', 'load_curve'],
            ['/metering_data_dc/v5/daily_consumption/', 'mesure_synchrone_auto/v2/consommation_quotidienne', 'CONS', 'daily'],
        ];
    }

    public function testPlanMaxPowerAddsRequiredParameters(): void
    {
        $plan = $this->plan('metering_data_dcmp/v5/daily_consumption_max_power');
        $this->assertSame('mesure_synchrone_auto/v2/puissance_conso_max_quotidienne', $plan['path']);
        $this->assertSame('P1D', $plan['query']['mesuresPas']);
        $this->assertSame('PMA', $plan['query']['grandeurPhysique']);
    }

    public function testPlanContract(): void
    {
        $plan = $this->plan('customers_upc/v5/usage_points/contracts');
        $this->assertSame('contract', $plan['kind']);
        $this->assertSame('situation_contrat_auto/v1/' . self::PRM, $plan['contract_path']);
        $this->assertSame('comptage_auto/v1/' . self::PRM, $plan['metering_situation_path']);
    }

    public function testPlanReturnsNullForUnknownPaths(): void
    {
        $this->assertNull($this->plan('customers_i/v5/identity'));
        $this->assertNull($this->plan('metering_data_xx/v5/unknown_resource'));
        $this->assertNull($this->plan('mesure_synchrone_auto/v2/consommation_quotidienne'));
        $this->assertNull($this->plan(''));
    }

    public function testPlanExtendsSingleDayRange(): void
    {
        $plan = EnedisTranslator::plan('metering_data_plc/v5/production_load_curve', [
            'usage_point_id' => self::PRM, 'start' => '2026-02-28', 'end' => '2026-02-28',
        ]);
        $this->assertSame('2026-02-28', $plan['query']['dateDebut']);
        $this->assertSame('2026-03-01', $plan['query']['dateFin']);
    }

    public function testPlanKeepsInvalidDatesUntouched(): void
    {
        $plan = EnedisTranslator::plan('metering_data_dc/v5/daily_consumption', [
            'usage_point_id' => self::PRM, 'start' => 'oops', 'end' => 'oops',
        ]);
        $this->assertSame('oops', $plan['query']['dateFin']);
    }

    // ------------------------------------------------- measureToLegacy()

    private function loadCurveBody(array $points, string $unit = 'W'): array
    {
        return [
            'idPrm' => self::PRM,
            'etapeMetier' => 'BRUT',
            'periode' => ['dateDebut' => '2026-09-26', 'dateFin' => '2026-09-27'],
            'modeCalcul' => 'MESURE',
            'grandeur' => [[
                'grandeurMetier' => 'CONS',
                'grandeurPhysique' => 'PA',
                'unite' => $unit,
                'points' => $points,
                'calendrier' => [],
            ]],
            'contexte' => [],
        ];
    }

    public function testLoadCurveEndOfIntervalIsKeptAsIs(): void
    {
        $body = $this->loadCurveBody([
            ['v' => '1200', 'd' => '2026-09-26 01:00:00', 'p' => 'PT30M', 'n' => 'B'],
            ['v' => '800', 'd' => '2026-09-26 00:30:00', 'p' => 'PT30M', 'n' => 'B'],
            ['v' => '500', 'd' => '2026-09-27 00:00:00', 'p' => 'PT30M', 'n' => 'B'],
        ]);
        $legacy = EnedisTranslator::measureToLegacy($body, $this->plan('metering_data_clc/v5/consumption_load_curve'));

        $reading = $legacy['meter_reading'];
        $this->assertSame(self::PRM, $reading['usage_point_id']);
        $this->assertSame('2026-09-26', $reading['start']);
        $this->assertSame('2026-09-27', $reading['end']);
        $this->assertSame('BRUT', $reading['quality']);
        $this->assertSame(['unit' => 'W', 'measurement_kind' => 'power', 'aggregate' => 'average'], $reading['reading_type']);
        $this->assertSame([
            ['value' => '800', 'date' => '2026-09-26 00:30:00', 'interval_length' => 'PT30M', 'measure_type' => 'B'],
            ['value' => '1200', 'date' => '2026-09-26 01:00:00', 'interval_length' => 'PT30M', 'measure_type' => 'B'],
            ['value' => '500', 'date' => '2026-09-27 00:00:00', 'interval_length' => 'PT30M', 'measure_type' => 'B'],
        ], $reading['interval_reading']);
    }

    public function testLoadCurveStartOfIntervalIsShiftedInAutoMode(): void
    {
        $body = $this->loadCurveBody([
            ['v' => '800', 'd' => '2026-09-26 00:00:00', 'p' => 'PT30M'],
            ['v' => '1200', 'd' => '2026-09-26 00:30:00', 'p' => 'PT30M'],
            ['v' => '500', 'd' => '2026-09-26 23:30:00', 'p' => 'PT30M'],
        ]);
        $legacy = EnedisTranslator::measureToLegacy($body, $this->plan('metering_data_clc/v5/consumption_load_curve'), 'auto');
        $dates = array_column($legacy['meter_reading']['interval_reading'], 'date');
        $this->assertSame(['2026-09-26 00:30:00', '2026-09-26 01:00:00', '2026-09-27 00:00:00'], $dates);
    }

    public function testLoadCurveTimestampModeCanBeForced(): void
    {
        $plan = $this->plan('metering_data_clc/v5/consumption_load_curve');
        $startStyle = $this->loadCurveBody([['v' => '800', 'd' => '2026-09-26 00:00:00', 'p' => 'PT30M']]);
        $endStyle = $this->loadCurveBody([['v' => '800', 'd' => '2026-09-26 00:30:00', 'p' => 'PT10M']]);

        $kept = EnedisTranslator::measureToLegacy($startStyle, $plan, 'end');
        $this->assertSame('2026-09-26 00:00:00', $kept['meter_reading']['interval_reading'][0]['date']);

        $shifted = EnedisTranslator::measureToLegacy($endStyle, $plan, 'start');
        $this->assertSame('2026-09-26 00:40:00', $shifted['meter_reading']['interval_reading'][0]['date']);
    }

    public function testIsoDatesAndUnitsAreNormalized(): void
    {
        $body = $this->loadCurveBody([
            ['v' => '1.234', 'd' => '2026-09-26T00:30:00+02:00'],
            ['v' => '0.5', 'd' => '2026-09-26T01:00:00.000+0200'],
            ['v' => '2', 'd' => '2026-09-25T23:30:00Z'],
        ], 'kW');
        $legacy = EnedisTranslator::measureToLegacy($body, $this->plan('metering_data_clc/v5/consumption_load_curve'));
        $this->assertSame([
            ['value' => '1234', 'date' => '2026-09-26 00:30:00', 'interval_length' => 'PT30M', 'measure_type' => 'B'],
            ['value' => '500', 'date' => '2026-09-26 01:00:00', 'interval_length' => 'PT30M', 'measure_type' => 'B'],
            // 23:30 UTC = 01:30 heure de Paris (heure d'été)
            ['value' => '2000', 'date' => '2026-09-26 01:30:00', 'interval_length' => 'PT30M', 'measure_type' => 'B'],
        ], $legacy['meter_reading']['interval_reading']);
    }

    public function testDailyConsumption(): void
    {
        $body = [
            'idPrm' => self::PRM,
            'etapeMetier' => 'BEST',
            'periode' => ['dateDebut' => '2026-09-24', 'dateFin' => '2026-09-27'],
            'modeCalcul' => 'DIFF.INDEX',
            'pas' => 'P1D',
            'grandeur' => [
                ['grandeurMetier' => 'PROD', 'grandeurPhysique' => 'EA', 'unite' => 'Wh', 'points' => [['v' => '1', 'd' => '2026-09-24']]],
                ['grandeurMetier' => 'CONS', 'grandeurPhysique' => 'EA', 'unite' => 'Wh', 'points' => [
                    ['v' => '15321', 'd' => '2026-09-25 00:00:00'],
                    ['v' => '12000', 'd' => '2026-09-24'],
                    ['v' => 9876, 'd' => '2026-09-26T00:00:00+02:00'],
                ]],
            ],
        ];
        $plan = EnedisTranslator::plan('metering_data_dc/v5/daily_consumption', ['usage_point_id' => self::PRM, 'start' => '2026-09-24', 'end' => '2026-09-27']);
        $legacy = EnedisTranslator::measureToLegacy($body, $plan);

        $this->assertSame('BEST', $legacy['meter_reading']['quality']);
        $this->assertSame('Wh', $legacy['meter_reading']['reading_type']['unit']);
        $this->assertSame('P1D', $legacy['meter_reading']['reading_type']['measuring_period']);
        $this->assertSame([
            ['value' => '12000', 'date' => '2026-09-24'],
            ['value' => '15321', 'date' => '2026-09-25'],
            ['value' => '9876', 'date' => '2026-09-26'],
        ], $legacy['meter_reading']['interval_reading']);
    }

    public function testDailyInKilowattHoursIsConvertedToWattHours(): void
    {
        $body = ['grandeur' => [['grandeurMetier' => 'CONS', 'unite' => 'kWh', 'points' => [['v' => '15.321', 'd' => '2026-09-25']]]]];
        $legacy = EnedisTranslator::measureToLegacy($body, $this->plan('metering_data_dc/v5/daily_consumption'));
        $this->assertSame('15321', $legacy['meter_reading']['interval_reading'][0]['value']);
        // Champs absents de la réponse : valeurs reprises de la requête
        $this->assertSame(self::PRM, $legacy['meter_reading']['usage_point_id']);
        $this->assertSame('2026-09-26', $legacy['meter_reading']['start']);
    }

    public function testInvalidPointsAreIgnoredAndEmptyResponseIsSupported(): void
    {
        $body = $this->loadCurveBody([
            ['v' => null, 'd' => '2026-09-26 00:30:00'],
            ['v' => '', 'd' => '2026-09-26 01:00:00'],
            ['v' => 'abc', 'd' => '2026-09-26 01:30:00'],
            ['v' => '10', 'd' => 'pas une date'],
            ['v' => '10'],
            'texte',
            ['v' => '42', 'd' => '2026-09-26 02:00:00'],
        ]);
        $plan = $this->plan('metering_data_clc/v5/consumption_load_curve');
        $legacy = EnedisTranslator::measureToLegacy($body, $plan);
        $this->assertCount(1, $legacy['meter_reading']['interval_reading']);
        $this->assertSame('42', $legacy['meter_reading']['interval_reading'][0]['value']);

        $empty = EnedisTranslator::measureToLegacy(['grandeur' => []], $plan);
        $this->assertSame([], $empty['meter_reading']['interval_reading']);
        $this->assertTrue(EnedisTranslator::isMeasureResponse(['grandeur' => []]));
        $this->assertFalse(EnedisTranslator::isMeasureResponse(['code' => 'x']));
        $this->assertFalse(EnedisTranslator::isMeasureResponse(null));
    }

    public function testLegacyResponseIsJsonEncodableAsObjects(): void
    {
        $legacy = EnedisTranslator::measureToLegacy($this->loadCurveBody([['v' => '1', 'd' => '2026-09-26 00:30:00']]), $this->plan('metering_data_clc/v5/consumption_load_curve'));
        $json = json_decode(json_encode($legacy));
        $this->assertIsArray($json->meter_reading->interval_reading);
        $this->assertSame('1', $json->meter_reading->interval_reading[0]->value);
    }

    // ------------------------------------------------ contractToLegacy()

    public function testContractFromBothApis(): void
    {
        $contract = ['contracts' => [[
            'usage_point_id' => self::PRM,
            'contract_start' => '2021-01-01T00:00:00+0100',
            'contract_type' => 'Contrat GRD-F',
            'subscribed_power' => ['unit' => 'kVA', 'value' => '9'],
            'segment' => 'C5',
            'distribution_tariff' => 'BTINFCUST',
            'customer' => ['person' => ['lastname' => 'X']],
        ]]];
        $metering = [
            'dispositifComptage' => ['typeComptage' => ['code' => 'AMM', 'libelle' => 'Linky']],
            'compteurs' => [['numeroSerie' => '123']],
            'relais' => ['plageHeuresCreuses' => ' HC (22H00-6H00) '],
        ];
        $legacy = EnedisTranslator::contractToLegacy($contract, $metering, self::PRM);
        $up = $legacy['customer']['usage_points'][0];

        $this->assertSame(self::PRM, $up['usage_point']['usage_point_id']);
        $this->assertSame('AMM', $up['usage_point']['meter_type']);
        $this->assertSame('com', $up['usage_point']['usage_point_status']);
        $this->assertSame('9 kVA', $up['contracts']['subscribed_power']);
        $this->assertSame('C5', $up['contracts']['segment']);
        $this->assertSame('BTINFCUST', $up['contracts']['distribution_tariff']);
        $this->assertSame('Contrat GRD-F', $up['contracts']['contract_type']);
        $this->assertSame('HC (22H00-6H00)', $up['contracts']['offpeak_hours']);
        $this->assertSame('2021-01-01+01:00', $up['contracts']['last_activation_date']);
        // Aucune donnée personnelle du titulaire n'est relayée
        $this->assertStringNotContainsString('lastname', json_encode($legacy));
    }

    public function testContractWithFlatBodyAndCodeObjects(): void
    {
        $contract = [
            'segment' => ['code' => 'C5', 'libelle' => 'C5'],
            'subscribed_power' => '6 kVA',
            'contract_start' => '2020-05-17',
        ];
        $up = EnedisTranslator::contractToLegacy($contract, null, self::PRM)['customer']['usage_points'][0];
        $this->assertSame('C5', $up['contracts']['segment']);
        $this->assertSame('6 kVA', $up['contracts']['subscribed_power']);
        $this->assertSame('2020-05-17', $up['contracts']['last_activation_date']);
        $this->assertSame('', $up['contracts']['offpeak_hours']);
        $this->assertSame('', $up['usage_point']['meter_type']);
    }

    public function testContractFromMeteringSituationOnlyOrNothing(): void
    {
        $up = EnedisTranslator::contractToLegacy(null, ['relais' => ['plageHeuresCreuses' => 'HC (1H06-7H06;15H06-17H06)']], self::PRM)['customer']['usage_points'][0];
        $this->assertSame('HC (1H06-7H06;15H06-17H06)', $up['contracts']['offpeak_hours']);
        $this->assertSame(self::PRM, $up['usage_point']['usage_point_id']);

        $minimal = EnedisTranslator::contractToLegacy(null, null, self::PRM);
        $this->assertCount(1, $minimal['customer']['usage_points']);
        $this->assertSame(self::PRM, $minimal['customer']['usage_points'][0]['usage_point']['usage_point_id']);
        $this->assertSame('', $minimal['customer']['usage_points'][0]['contracts']['subscribed_power']);
    }

    // ----------------------------------------------------------- erreurs

    public function testErrorToLegacy(): void
    {
        $this->assertSame(
            ['error' => 'SGT404', 'error_description' => 'Pas de mesure trouvée pour ce point', 'code' => 'SGT404', 'message' => 'Pas de mesure trouvée pour ce point'],
            EnedisTranslator::errorToLegacy(['code' => 'SGT404', 'message' => 'Pas de mesure trouvée pour ce point'], 404)
        );
        $fromList = EnedisTranslator::errorToLegacy([['code' => 'E1', 'message' => 'm']], 400);
        $this->assertSame('E1', $fromList['error']);
        $fallback = EnedisTranslator::errorToLegacy(null, 500);
        $this->assertSame('enedis_error_500', $fallback['error']);
        $this->assertNotSame('', $fallback['error_description']);
        $legacyShape = EnedisTranslator::errorToLegacy(['error' => 'no_data_found', 'error_description' => 'x'], 404);
        $this->assertSame('no_data_found', $legacyShape['error']);
    }

    // ------------------------------------------------ services souscrits

    public function testSubscribedServicesRequest(): void
    {
        $request = EnedisTranslator::subscribedServicesRequest('987654321');
        $this->assertSame(987654321, $request['autorisationId']);
        $this->assertFalse($request['comptage']);
        $this->assertSame(['ACTIF', 'DEMANDE'], $request['etatCode']);
        $this->assertSame('ACCES', $request['serviceType']);
        $this->assertSame('abc-123', EnedisTranslator::subscribedServicesRequest('abc-123')['autorisationId']);
    }

    public function testExtractUsagePoints(): void
    {
        $body = ['nbTotalServices' => 4, 'serviceSouscrit' => [
            ['pointId' => self::PRM, 'etatCode' => 'ACTIF', 'mesuresTypeCode' => 'CDC'],
            ['pointId' => self::PRM, 'etatCode' => 'DEMANDE', 'mesuresTypeCode' => 'ENERGIE'],
            ['pointId' => '99999999999999', 'etatCode' => 'TERMINE'],
            ['pointId' => 'pas-un-prm', 'etatCode' => 'ACTIF'],
            ['etatCode' => 'ACTIF'],
            'texte',
            ['pointId' => 30001234567890, 'etatCode' => 'ACTIF'],
        ]];
        $this->assertSame([self::PRM, '30001234567890'], EnedisTranslator::extractUsagePoints($body));
        $this->assertSame([self::PRM], EnedisTranslator::extractUsagePoints(['serviceSouscrit' => ['pointId' => self::PRM]]));
        $this->assertSame([], EnedisTranslator::extractUsagePoints(['nbTotalServices' => 0, 'serviceSouscrit' => []]));
        $this->assertSame([], EnedisTranslator::extractUsagePoints(['nbTotalServices' => 0]));
        $this->assertSame([], EnedisTranslator::extractUsagePoints(null));
    }

    public function testIsValidUsagePointId(): void
    {
        $this->assertTrue(EnedisTranslator::isValidUsagePointId(self::PRM));
        $this->assertFalse(EnedisTranslator::isValidUsagePointId('1234'));
        $this->assertFalse(EnedisTranslator::isValidUsagePointId('1234567890123a'));
        $this->assertFalse(EnedisTranslator::isValidUsagePointId("12345678901234\n"));
        $this->assertFalse(EnedisTranslator::isValidUsagePointId(null));
    }
}
