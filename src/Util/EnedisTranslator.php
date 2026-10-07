<?php

namespace App\Util;

/**
 * Traduction entre les anciennes API DataConnect (v5) et les nouvelles API
 * mises en service lors de la bascule du 28/09/2026 (Implementing Act).
 *
 * Les applications Homey déjà installées continuent d'appeler le proxy avec les
 * chemins v5 (`metering_data_dc/v5/daily_consumption?usage_point_id=...`) et
 * attendent le format v5 (`meter_reading.interval_reading[]`). Le proxy appelle
 * les nouvelles API et reconstruit ici la réponse au format v5.
 *
 * Classe volontairement pure (aucun accès réseau, cache ou conteneur) pour être
 * testable unitairement.
 */
class EnedisTranslator {
  const KIND_MEASURE = 'measure';
  const KIND_CONTRACT = 'contract';

  const TYPE_LOAD_CURVE = 'load_curve';
  const TYPE_DAILY = 'daily';
  const TYPE_MAX_POWER = 'max_power';

  const MEASURE_BASE_PATH = 'mesure_synchrone_auto/v2';
  const CONTRACT_BASE_PATH = 'situation_contrat_auto/v1';
  const METERING_SITUATION_BASE_PATH = 'comptage_auto/v1';

  # Dernier segment du chemin v5 => sous-ressource de la nouvelle API mesure
  const MEASURE_RESOURCES = [
    'daily_consumption' => ['resource' => 'consommation_quotidienne', 'metier' => 'CONS', 'type' => self::TYPE_DAILY],
    'daily_production' => ['resource' => 'production_quotidienne', 'metier' => 'PROD', 'type' => self::TYPE_DAILY],
    'consumption_load_curve' => ['resource' => 'courbe_de_charge_consommation', 'metier' => 'CONS', 'type' => self::TYPE_LOAD_CURVE],
    'production_load_curve' => ['resource' => 'courbe_de_charge_production', 'metier' => 'PROD', 'type' => self::TYPE_LOAD_CURVE],
    'daily_consumption_max_power' => ['resource' => 'puissance_conso_max_quotidienne', 'metier' => 'CONS', 'type' => self::TYPE_MAX_POWER],
  ];

  const READING_TYPES = [
    self::TYPE_DAILY => ['unit' => 'Wh', 'measurement_kind' => 'energy', 'aggregate' => 'sum', 'measuring_period' => 'P1D'],
    self::TYPE_LOAD_CURVE => ['unit' => 'W', 'measurement_kind' => 'power', 'aggregate' => 'average'],
    self::TYPE_MAX_POWER => ['unit' => 'VA', 'measurement_kind' => 'power', 'aggregate' => 'maximum', 'measuring_period' => 'P1D'],
  ];

  public static function isValidUsagePointId($value): bool {
    return is_string($value) && preg_match('/^\d{14}\z/', $value) === 1;
  }

  /**
   * Construit le plan d'appel vers les nouvelles API pour un chemin v5.
   * Retourne null si le chemin n'est pas connu (le proxy le relaie alors tel quel).
   */
  public static function plan(string $legacyPath, array $query): ?array {
    $path = trim($legacyPath, '/');
    $prm = (string)($query['usage_point_id'] ?? '');

    if (preg_match('#^customers_upc/v\d+/usage_points/contracts$#', $path)) {
      return [
        'kind' => self::KIND_CONTRACT,
        'api' => 'contract',
        'usage_point_id' => $prm,
        'contract_path' => self::CONTRACT_BASE_PATH . '/' . rawurlencode($prm),
        'metering_situation_path' => self::METERING_SITUATION_BASE_PATH . '/' . rawurlencode($prm),
      ];
    }

    if (!preg_match('#^metering_data_[a-z]+/v\d+/([a-z_]+)$#', $path, $m)) {
      return null;
    }
    if (!array_key_exists($m[1], self::MEASURE_RESOURCES)) {
      return null;
    }
    $resource = self::MEASURE_RESOURCES[$m[1]];

    $start = (string)($query['start'] ?? '');
    $end = (string)($query['end'] ?? '');

    # La nouvelle API refuse dateFin == dateDebut (la date de fin est exclue).
    # L'application interroge parfois une seule journée avec start == end
    # (détection de la production) : on demande alors la journée complète.
    if ($start !== '' && $start === $end) {
      $next = self::addDays($start, 1);
      if ($next !== null) {
        $end = $next;
      }
    }

    $newQuery = [
      'pointId' => $prm,
      'dateDebut' => $start,
      'dateFin' => $end,
    ];
    if ($resource['type'] === self::TYPE_MAX_POWER) {
      $newQuery['mesuresPas'] = 'P1D';
      $newQuery['grandeurPhysique'] = 'PMA';
    }

    return [
      'kind' => self::KIND_MEASURE,
      'api' => 'measure',
      'usage_point_id' => $prm,
      'path' => self::MEASURE_BASE_PATH . '/' . $resource['resource'],
      'query' => $newQuery,
      'metier' => $resource['metier'],
      'type' => $resource['type'],
      'start' => $start,
      'end' => $end,
    ];
  }

  /**
   * Indique si une réponse de la nouvelle API mesure a la forme attendue.
   */
  public static function isMeasureResponse($body): bool {
    return is_array($body) && array_key_exists('grandeur', $body) && is_array($body['grandeur']);
  }

  /**
   * Convertit une réponse de la nouvelle API mesure au format v5.
   *
   * @param array  $body          Réponse JSON décodée de la nouvelle API
   * @param array  $plan          Plan retourné par plan()
   * @param string $timestampMode 'auto' | 'end' | 'start' : convention d'horodatage des
   *                              points de courbe de charge renvoyés par Enedis. Le format v5
   *                              horodate la FIN de l'intervalle (premier point à 00:30).
   */
  public static function measureToLegacy(array $body, array $plan, string $timestampMode = 'auto'): array {
    $type = $plan['type'];
    $grandeur = self::pickGrandeur($body['grandeur'] ?? [], $plan['metier'], $type);

    $factor = self::unitFactor($grandeur['unite'] ?? null);
    $rawPoints = is_array($grandeur['points'] ?? null) ? $grandeur['points'] : [];

    $defaultStep = is_string($body['pas'] ?? null) && $body['pas'] !== '' ? $body['pas'] : 'PT30M';
    $readings = [];
    foreach ($rawPoints as $point) {
      if (!is_array($point)) continue;
      $value = $point['v'] ?? null;
      $date = self::normalizeDateTime($point['d'] ?? null);
      if ($value === null || $value === '' || !is_numeric($value) || $date === null) continue;

      $reading = [
        'value' => (string)(int)round(((float)$value) * $factor),
        'date' => $date,
      ];
      if ($type === self::TYPE_LOAD_CURVE) {
        $step = is_string($point['p'] ?? null) && $point['p'] !== '' ? $point['p'] : $defaultStep;
        $reading['interval_length'] = $step;
        $reading['measure_type'] = is_string($point['n'] ?? null) && $point['n'] !== '' ? $point['n'] : 'B';
      }
      $readings[] = $reading;
    }

    $periodStart = self::dateOnly($body['periode']['dateDebut'] ?? null) ?? $plan['start'];
    $periodEnd = self::dateOnly($body['periode']['dateFin'] ?? null) ?? $plan['end'];

    if ($type === self::TYPE_LOAD_CURVE) {
      if (self::shouldShiftToIntervalEnd($readings, $periodStart, $timestampMode)) {
        foreach ($readings as &$r) {
          $r['date'] = self::addSeconds($r['date'], self::stepSeconds($r['interval_length']));
        }
        unset($r);
      }
    } elseif ($type === self::TYPE_DAILY) {
      foreach ($readings as &$r) {
        $r['date'] = substr($r['date'], 0, 10);
      }
      unset($r);
    }

    usort($readings, fn($a, $b) => strcmp($a['date'], $b['date']));

    return [
      'meter_reading' => [
        'usage_point_id' => (string)($body['idPrm'] ?? $plan['usage_point_id']),
        'start' => $periodStart,
        'end' => $periodEnd,
        'quality' => (string)($body['etapeMetier'] ?? 'BRUT'),
        'reading_type' => self::READING_TYPES[$type],
        'interval_reading' => $readings,
      ],
    ];
  }

  /**
   * Résumé non personnel d'une traduction de mesure, pour les logs : nombre de points,
   * heure du premier et du dernier point, unité d'origine et décalage appliqué.
   * Permet de vérifier en production que l'horodatage correspond à la convention v5
   * (premier point d'une journée à 00:30, dernier à 00:00 le lendemain).
   */
  public static function measureDiagnostics(array $body, array $legacy, array $plan): string {
    $readings = $legacy['meter_reading']['interval_reading'] ?? [];
    $count = count($readings);
    $grandeur = self::pickGrandeur($body['grandeur'] ?? [], $plan['metier'], $plan['type']);
    $unit = (string)($grandeur['unite'] ?? '?');
    $rawFirst = null;
    foreach (($grandeur['points'] ?? []) as $point) {
      if (is_array($point) && is_string($point['d'] ?? null)) {
        $rawFirst = self::normalizeDateTime($point['d']);
        break;
      }
    }
    $first = $count ? substr($readings[0]['date'], 11) ?: $readings[0]['date'] : '-';
    $last = $count ? substr($readings[$count - 1]['date'], 11) ?: $readings[$count - 1]['date'] : '-';
    $shifted = 'n/a';
    if ($plan['type'] === self::TYPE_LOAD_CURVE && $count && $rawFirst !== null) {
      $shifted = in_array($rawFirst, array_column($readings, 'date'), true) ? 'non' : 'oui';
    }
    return sprintf('points=%d, premier=%s, dernier=%s, unite=%s, format_brut=%s, decalage=%s',
      $count, $first, $last, $unit, self::describeDateFormat($grandeur['points'][0]['d'] ?? null), $shifted);
  }

  private static function describeDateFormat($value): string {
    if (!is_string($value)) return 'absent';
    return preg_replace('/\d/', '9', $value);
  }

  /**
   * Convertit les réponses « situation contractuelle » et « situation comptage »
   * au format de l'ancienne API contrats v5. L'une des deux peut être absente.
   */
  public static function contractToLegacy(?array $contractBody, ?array $meteringBody, string $prm): array {
    $contract = $contractBody ? self::findObjectWithAnyKey($contractBody, [
      'subscribed_power', 'contract_type', 'distribution_tariff', 'contract_start', 'segment',
    ]) : null;
    $contract = $contract ?? [];

    $offpeak = $meteringBody ? self::findValue($meteringBody, 'plageHeuresCreuses') : null;
    if (!is_string($offpeak) || $offpeak === '') {
      $offpeak = self::findValue($contractBody ?? [], 'offpeak_hours');
    }

    $meterType = null;
    if ($meteringBody) {
      $typeComptage = self::findValue($meteringBody, 'typeComptage');
      if (is_array($typeComptage) && is_string($typeComptage['code'] ?? null)) {
        $meterType = $typeComptage['code'];
      }
    }
    if ($meterType === null && is_string($contract['meter_type'] ?? null)) {
      $meterType = $contract['meter_type'];
    }

    $contracts = [
      'segment' => self::scalarOrEmpty($contract['segment'] ?? null),
      'subscribed_power' => self::formatPower($contract['subscribed_power'] ?? null),
      'last_activation_date' => self::toLegacyDate(
        $contract['contract_start'] ?? $contract['last_activation_date'] ?? null
      ),
      'distribution_tariff' => self::scalarOrEmpty($contract['distribution_tariff'] ?? null),
      'offpeak_hours' => is_string($offpeak) ? trim($offpeak) : '',
      'contract_type' => self::scalarOrEmpty($contract['contract_type'] ?? null),
      'contract_status' => self::scalarOrEmpty($contract['contract_status'] ?? null),
      'last_distribution_tariff_change_date' => self::toLegacyDate(
        $contract['last_distribution_tariff_change_date'] ?? null
      ),
    ];

    $contractPrm = $contract['usage_point_id'] ?? null;
    $usagePointId = is_scalar($contractPrm) && self::isValidUsagePointId((string)$contractPrm)
      ? (string)$contractPrm
      : $prm;

    return [
      'customer' => [
        'customer_id' => '',
        'usage_points' => [[
          'usage_point' => [
            'usage_point_id' => $usagePointId,
            'usage_point_status' => 'com',
            'meter_type' => $meterType ?? '',
          ],
          'contracts' => $contracts,
        ]],
      ],
    ];
  }

  /**
   * Convertit une erreur des nouvelles API ({code, message}) en gardant aussi les
   * clés de l'ancien format ({error, error_description}).
   */
  public static function errorToLegacy($body, int $status): array {
    $code = null;
    $message = null;
    if (is_array($body)) {
      # Certaines erreurs sont renvoyées sous forme de tableau d'erreurs
      $first = array_is_list($body) && isset($body[0]) && is_array($body[0]) ? $body[0] : $body;
      # La passerelle Enedis renvoie ses propres erreurs sous la forme {"fault": {...}}
      if (isset($first['fault']) && is_array($first['fault'])) {
        $first = $first['fault'];
      }
      $code = $first['code'] ?? $first['error'] ?? null;
      $message = $first['message'] ?? $first['libelle'] ?? $first['error_description'] ?? null;
      if (is_scalar($message) && is_scalar($first['description'] ?? null) && (string)$first['description'] !== '') {
        $message = $message . ' : ' . $first['description'];
      }
    }
    $code = is_scalar($code) && (string)$code !== '' ? (string)$code : 'enedis_error_' . $status;
    $message = is_scalar($message) && (string)$message !== '' ? (string)$message : 'Erreur Enedis (HTTP ' . $status . ')';

    return [
      'error' => $code,
      'error_description' => $message,
      'code' => $code,
      'message' => $message,
    ];
  }

  /**
   * Corps de la requête « services souscrits » pour échanger un autorisation_id.
   */
  public static function subscribedServicesRequest(string $autorisationId): array {
    return [
      'autorisationId' => ctype_digit($autorisationId) ? (int)$autorisationId : $autorisationId,
      'comptage' => false,
      'etatCode' => ['ACTIF', 'DEMANDE'],
      'serviceType' => 'ACCES',
    ];
  }

  /**
   * Extrait la liste des PRM (sans doublon) d'une réponse « services souscrits ».
   */
  public static function extractUsagePoints($body): array {
    if (!is_array($body)) return [];
    $services = $body['serviceSouscrit'] ?? $body['servicesSouscrits'] ?? null;
    if (!is_array($services)) return [];
    if (!array_is_list($services)) $services = [$services];

    $prms = [];
    foreach ($services as $service) {
      if (!is_array($service)) continue;
      $state = strtoupper((string)($service['etatCode'] ?? ''));
      if ($state === 'TERMINE' || $state === 'SUPPRIME') continue;
      $prm = $service['pointId'] ?? null;
      if (is_int($prm)) $prm = (string)$prm;
      if (self::isValidUsagePointId($prm) && !in_array($prm, $prms, true)) {
        $prms[] = $prm;
      }
    }
    return $prms;
  }

  # ---------------------------------------------------------------- helpers

  private static function pickGrandeur(array $grandeurs, string $metier, string $type): array {
    $candidates = array_values(array_filter($grandeurs, 'is_array'));
    if (!$candidates) return [];

    $sameMetier = array_values(array_filter(
      $candidates,
      fn($g) => strtoupper((string)($g['grandeurMetier'] ?? '')) === $metier
    ));
    if ($sameMetier) $candidates = $sameMetier;

    # Grandeur physique attendue : puissance active (PA), énergie active (EA), puissance max (PMA)
    $wanted = [self::TYPE_LOAD_CURVE => 'PA', self::TYPE_DAILY => 'EA', self::TYPE_MAX_POWER => 'PMA'][$type];
    foreach ($candidates as $g) {
      if (strtoupper((string)($g['grandeurPhysique'] ?? '')) === $wanted) return $g;
    }
    return $candidates[0];
  }

  private static function unitFactor($unit): float {
    if (!is_string($unit) || $unit === '') return 1.0;
    $u = trim($unit);
    if (preg_match('/^k(W|Wh|VA|VAh)$/i', $u) && $u[0] === 'k') return 1000.0;
    if (preg_match('/^M(W|Wh|VA|VAh)$/', $u)) return 1000000.0;
    return 1.0;
  }

  /**
   * Normalise une horodate Enedis en « YYYY-MM-DD HH:MM:SS » (heure locale française).
   */
  public static function normalizeDateTime($value): ?string {
    if (!is_string($value)) return null;
    $v = trim($value);
    if ($v === '') return null;

    if (preg_match('/^(\d{4}-\d{2}-\d{2})$/', $v, $m)) {
      return $m[1] . ' 00:00:00';
    }
    if (!preg_match('/^(\d{4}-\d{2}-\d{2})[T ](\d{2}):(\d{2})(?::(\d{2}))?(?:\.\d+)?\s*(Z|[+-]\d{2}:?\d{2})?$/', $v, $m)) {
      return null;
    }
    $seconds = isset($m[4]) && $m[4] !== '' ? $m[4] : '00';
    $local = sprintf('%s %s:%s:%s', $m[1], $m[2], $m[3], $seconds);
    $zone = $m[5] ?? '';

    # Une horodate en UTC explicite est ramenée en heure locale française.
    # Les décalages +01:00 / +02:00 sont déjà de l'heure locale : on garde l'heure telle quelle.
    if ($zone === 'Z' || preg_match('/^[+-]00:?00$/', $zone)) {
      try {
        $dt = new \DateTimeImmutable($local, new \DateTimeZone('UTC'));
        return $dt->setTimezone(new \DateTimeZone('Europe/Paris'))->format('Y-m-d H:i:s');
      } catch (\Throwable $e) {
        return null;
      }
    }
    return $local;
  }

  private static function dateOnly($value): ?string {
    $normalized = self::normalizeDateTime($value);
    return $normalized === null ? null : substr($normalized, 0, 10);
  }

  private static function shouldShiftToIntervalEnd(array $readings, string $periodStart, string $mode): bool {
    $mode = strtolower($mode);
    if ($mode === 'start') return true;
    if ($mode === 'end' || !$readings) return false;

    # Détection automatique : en horodatage de fin d'intervalle, le premier point de la
    # période est à 00:30 (ou 00:10...). Un point à 00:00:00 le premier jour demandé
    # signifie donc que les points sont horodatés en début d'intervalle.
    if ($periodStart === '') return false;
    $midnight = $periodStart . ' 00:00:00';
    foreach ($readings as $r) {
      if ($r['date'] === $midnight) return true;
    }
    return false;
  }

  public static function stepSeconds($step): int {
    if (is_string($step) && preg_match('/^PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?$/', $step, $m)) {
      $seconds = ((int)($m[1] ?? 0)) * 3600 + ((int)($m[2] ?? 0)) * 60 + ((int)($m[3] ?? 0));
      if ($seconds > 0) return $seconds;
    }
    return 1800;
  }

  # Arithmétique « naïve » (sans fuseau) : les horodates restent des heures murales.
  private static function addSeconds(string $dateTime, int $seconds): string {
    $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $dateTime, new \DateTimeZone('UTC'));
    if (!$dt) return $dateTime;
    return $dt->modify(($seconds >= 0 ? '+' : '') . $seconds . ' seconds')->format('Y-m-d H:i:s');
  }

  private static function addDays(string $date, int $days): ?string {
    $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('UTC'));
    if (!$dt || $dt->format('Y-m-d') !== $date) return null;
    return $dt->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
  }

  /**
   * Date au format v5 (« 2021-01-01+01:00 ») à partir d'une date ISO-8601.
   */
  private static function toLegacyDate($value): string {
    if (!is_string($value) || trim($value) === '') return '';
    $v = trim($value);
    if (!preg_match('/^(\d{4}-\d{2}-\d{2})(?:[T ]\d{2}:\d{2}(?::\d{2})?(?:\.\d+)?)?\s*(Z|[+-]\d{2}:?\d{2})?$/', $v, $m)) {
      return $v;
    }
    $zone = $m[2] ?? '';
    if ($zone === '') return $m[1];
    if ($zone === 'Z') return $m[1] . '+00:00';
    if (strpos($zone, ':') === false) {
      $zone = substr($zone, 0, 3) . ':' . substr($zone, 3);
    }
    return $m[1] . $zone;
  }

  private static function formatPower($value): string {
    if (is_array($value)) {
      $v = $value['value'] ?? $value['valeur'] ?? null;
      $u = $value['unit'] ?? $value['unite'] ?? 'kVA';
      if (!is_scalar($v) || (string)$v === '') return '';
      return trim((string)$v . ' ' . (is_scalar($u) ? (string)$u : 'kVA'));
    }
    return self::scalarOrEmpty($value);
  }

  private static function scalarOrEmpty($value): string {
    if (is_array($value)) {
      # Objets {code, libelle} : on garde le code, plus proche des valeurs v5
      $candidate = $value['code'] ?? $value['libelle'] ?? $value['value'] ?? null;
      return is_scalar($candidate) ? (string)$candidate : '';
    }
    return is_scalar($value) ? (string)$value : '';
  }

  private static function findObjectWithAnyKey(array $data, array $keys, int $depth = 0): ?array {
    if ($depth > 6) return null;
    foreach ($keys as $key) {
      if (array_key_exists($key, $data)) return $data;
    }
    foreach ($data as $child) {
      if (is_array($child)) {
        $found = self::findObjectWithAnyKey($child, $keys, $depth + 1);
        if ($found !== null) return $found;
      }
    }
    return null;
  }

  private static function findValue(array $data, string $key, int $depth = 0) {
    if ($depth > 6) return null;
    if (array_key_exists($key, $data)) return $data[$key];
    foreach ($data as $child) {
      if (is_array($child)) {
        $found = self::findValue($child, $key, $depth + 1);
        if ($found !== null) return $found;
      }
    }
    return null;
  }
}
