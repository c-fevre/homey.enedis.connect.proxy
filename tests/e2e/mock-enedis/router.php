<?php
/**
 * Faux serveur Enedis pour les tests de bout en bout du proxy.
 *
 * Simule à la fois les anciennes API v5 et les nouvelles API (bascule du 28/09/2026)
 * avec les mêmes données, ce qui permet de vérifier que la traduction du proxy
 * restitue exactement ce que l'application recevait avant.
 *
 * Lancement : php -S 0.0.0.0:9000 router.php
 * Pilotage  : POST /__control (JSON), GET /__requests, POST /__reset
 */

const STATE_FILE = '/tmp/mock-enedis-state.json';

function defaults(): array {
  return [
    'scenario' => [
      'token_status' => 200,
      'subscribed_status' => 200,
      'subscribed_empty_first' => 0,   # nombre de premières réponses sans service
      'new_measure_status' => 200,
      'new_contract_status' => 200,
      'new_metering_status' => 200,
      'legacy_status' => 200,
      'timestamp_style' => 'end',      # end | start | iso
      'unit' => 'base',                # base (W/Wh) | kilo (kW/kWh)
      'prm_for_autorisation' => '12345678901234',
    ],
    'tokens' => [],
    'token_count' => 0,
    'subscribed_calls' => 0,
    'requests' => [],
  ];
}

function load_state(): array {
  if (!file_exists(STATE_FILE)) return defaults();
  $state = json_decode(file_get_contents(STATE_FILE), true);
  return is_array($state) ? $state : defaults();
}

function save_state(array $state): void {
  file_put_contents(STATE_FILE, json_encode($state), LOCK_EX);
}

function respond(int $status, $body): bool {
  http_response_code($status);
  header('Content-Type: application/json');
  echo json_encode($body);
  return true;
}

function bearer(): ?string {
  $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
  return preg_match('/^Bearer\s+(.+)$/', $auth, $m) ? $m[1] : null;
}

# Valeur déterministe d'un point, identique pour les deux générations d'API
function point_value(string $metier, string $type, int $index): int {
  $base = $metier === 'PROD' ? 40 : 300;
  return $type === 'daily' ? ($base * 40) + $index * 111 : $base + ($index % 48) * 7;
}

function days(string $start, string $end): array {
  $out = [];
  $d = DateTimeImmutable::createFromFormat('!Y-m-d', $start, new DateTimeZone('UTC'));
  $e = DateTimeImmutable::createFromFormat('!Y-m-d', $end, new DateTimeZone('UTC'));
  if (!$d || !$e) return $out;
  while ($d < $e && count($out) < 400) {
    $out[] = $d;
    $d = $d->modify('+1 day');
  }
  return $out;
}

# Points au format v5 : [['value' => '300', 'date' => '2026-09-26 00:30:00'], ...]
function reference_points(string $metier, string $type, string $start, string $end): array {
  $points = [];
  $i = 0;
  foreach (days($start, $end) as $day) {
    if ($type === 'daily') {
      $points[] = ['value' => (string)point_value($metier, $type, $i++), 'date' => $day->format('Y-m-d')];
      continue;
    }
    for ($slot = 1; $slot <= 48; $slot++) {
      # Horodatage v5 : fin de l'intervalle de 30 minutes
      $points[] = [
        'value' => (string)point_value($metier, $type, $i++),
        'date' => $day->modify('+' . ($slot * 30) . ' minutes')->format('Y-m-d H:i:s'),
        'interval_length' => 'PT30M',
        'measure_type' => 'B',
      ];
    }
  }
  return $points;
}

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$rawBody = file_get_contents('php://input');
$state = load_state();

# ------------------------------------------------------------- pilotage
if ($path === '/__reset') {
  save_state(defaults());
  return respond(200, ['ok' => true]);
}
if ($path === '/__control') {
  $changes = json_decode($rawBody, true) ?: [];
  $state['scenario'] = array_merge($state['scenario'], $changes);
  $state['subscribed_calls'] = 0;
  save_state($state);
  return respond(200, $state['scenario']);
}
if ($path === '/__requests') {
  if ($method === 'DELETE') {
    $state['requests'] = [];
    save_state($state);
    return respond(200, ['ok' => true]);
  }
  return respond(200, $state['requests']);
}
if ($path === '/__reference') {
  # Réponse v5 de référence, sans authentification, pour comparaison dans les tests
  return respond(200, reference_points($_GET['metier'], $_GET['type'], $_GET['start'], $_GET['end']));
}

$state['requests'][] = [
  'method' => $method,
  'path' => $path,
  'query' => $_GET,
  'body' => $rawBody,
  'content_type' => $_SERVER['CONTENT_TYPE'] ?? ($_SERVER['HTTP_CONTENT_TYPE'] ?? null),
  'authorization' => $_SERVER['HTTP_AUTHORIZATION'] ?? null,
];
$scenario = $state['scenario'];

# ---------------------------------------------------------------- jeton
if ($method === 'POST' && preg_match('#/oauth2/v\d+/token$|/v1/oauth2/token$#', $path)) {
  if ($scenario['token_status'] !== 200) {
    save_state($state);
    return respond($scenario['token_status'], ['error' => 'invalid_client']);
  }
  if (($_POST['grant_type'] ?? '') !== 'client_credentials' || empty($_POST['client_id']) || empty($_POST['client_secret'])) {
    save_state($state);
    return respond(400, ['error' => 'invalid_request']);
  }
  $state['token_count']++;
  $token = 'mock-token-' . $state['token_count'];
  $state['tokens'][] = $token;
  save_state($state);
  return respond(200, ['access_token' => $token, 'token_type' => 'Bearer', 'expires_in' => 12600, 'scope' => 'am_application_scope default']);
}

# Toutes les API de données exigent un jeton émis par ce serveur
$token = bearer();
if (!$token || !in_array($token, $state['tokens'], true)) {
  save_state($state);
  return respond(401, ['code' => '900901', 'message' => 'Invalid Credentials']);
}

# --------------------------------------------------- services souscrits
if (preg_match('#^/subscribed_services/v1/?$#', $path)) {
  $state['subscribed_calls']++;
  $calls = $state['subscribed_calls'];
  save_state($state);
  if ($method !== 'POST') return respond(405, ['code' => '405', 'message' => 'Method not allowed']);
  if ($scenario['subscribed_status'] !== 200) {
    return respond($scenario['subscribed_status'], ['code' => 'ERR', 'message' => 'Erreur simulée services souscrits']);
  }
  $body = json_decode($rawBody, true);
  if (!is_array($body) || !isset($body['autorisationId']) || !array_key_exists('comptage', $body)) {
    return respond(400, ['code' => 'SGT400', 'message' => 'autorisationId et comptage sont obligatoires']);
  }
  if ($calls <= $scenario['subscribed_empty_first']) {
    return respond(200, ['nbTotalServices' => 0, 'serviceSouscrit' => []]);
  }
  $prm = $scenario['prm_for_autorisation'];
  $services = [];
  foreach (['CDC', 'ENERGIE', 'ITC'] as $i => $type) {
    $services[] = [
      'id' => 'svc-' . $i,
      'injection' => false,
      'soutirage' => true,
      'serviceCode' => 'ACCES',
      'dateDebut' => '2026-09-28',
      'dateFin' => '2029-09-27',
      'etatCode' => 'ACTIF',
      'etatLibelle' => 'Actif',
      'pointId' => $prm,
      'mesuresTypeCode' => $type,
      'mesuresPas' => $type === 'CDC' ? 'PT30M' : 'P1D',
      'autorisation' => ['autorisationId' => $body['autorisationId'], 'autorisationType' => 'EXPLICITE'],
    ];
  }
  return respond(200, ['nbTotalServices' => count($services), 'serviceSouscrit' => $services]);
}

# ------------------------------------------------- nouvelle API mesure
$newResources = [
  'consommation_quotidienne' => ['CONS', 'daily'],
  'production_quotidienne' => ['PROD', 'daily'],
  'courbe_de_charge_consommation' => ['CONS', 'load_curve'],
  'courbe_de_charge_production' => ['PROD', 'load_curve'],
];
if (preg_match('#^/mesure_synchrone_auto/v2/([a-z_]+)$#', $path, $m) && isset($newResources[$m[1]])) {
  save_state($state);
  [$metier, $type] = $newResources[$m[1]];
  if ($scenario['new_measure_status'] !== 200) {
    $messages = [403 => "Le client n'est pas autorisé pour le point demandé", 404 => 'Pas de mesure trouvée pour ce point', 429 => 'SLA dépassé'];
    return respond($scenario['new_measure_status'], ['code' => 'SGT' . $scenario['new_measure_status'], 'message' => $messages[$scenario['new_measure_status']] ?? 'Erreur simulée']);
  }
  foreach (['pointId', 'dateDebut', 'dateFin'] as $required) {
    if (empty($_GET[$required])) return respond(400, ['code' => 'SGT400', 'message' => "Le paramètre $required est obligatoire"]);
  }
  if (isset($_GET['usage_point_id']) || isset($_GET['start']) || isset($_GET['end'])) {
    return respond(400, ['code' => 'SGT400', 'message' => 'Paramètre v5 inattendu sur la nouvelle API']);
  }
  if ($type === 'load_curve' && $_GET['dateDebut'] === $_GET['dateFin']) {
    return respond(400, ['code' => 'SGT400', 'message' => 'La date de fin ne peut être égale à la date de début']);
  }

  $kilo = $scenario['unit'] === 'kilo';
  $points = [];
  foreach (reference_points($metier, $type, $_GET['dateDebut'], $_GET['dateFin']) as $ref) {
    $date = $ref['date'];
    if ($type === 'load_curve') {
      $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $date, new DateTimeZone('UTC'));
      if ($scenario['timestamp_style'] === 'start') {
        $date = $dt->modify('-30 minutes')->format('Y-m-d H:i:s');
      } elseif ($scenario['timestamp_style'] === 'iso') {
        $date = $dt->format('Y-m-d\TH:i:s') . '+02:00';
      }
    }
    $value = $kilo ? number_format(((int)$ref['value']) / 1000, 3, '.', '') : $ref['value'];
    $point = ['v' => $value, 'd' => $date];
    if ($type === 'load_curve') {
      $point += ['p' => 'PT30M', 'n' => 'B', 'iv' => '0'];
    }
    $points[] = $point;
  }
  # Ordre volontairement inversé : le proxy doit retrier
  $points = array_reverse($points);

  $response = [
    'idPrm' => $_GET['pointId'],
    'etapeMetier' => 'BRUT',
    'periode' => ['dateDebut' => $_GET['dateDebut'], 'dateFin' => $_GET['dateFin']],
    'modeCalcul' => $type === 'daily' ? 'DIFF.INDEX' : 'MESURE',
    'grandeur' => [[
      'grandeurMetier' => $metier,
      'grandeurPhysique' => $type === 'daily' ? 'EA' : 'PA',
      'unite' => ($kilo ? 'k' : '') . ($type === 'daily' ? 'Wh' : 'W'),
      'points' => $points,
      'calendrier' => [],
    ]],
    'contexte' => [],
  ];
  if ($type === 'daily') $response['pas'] = 'P1D';
  return respond(200, $response);
}

# ------------------------------------------------ nouvelles API contrat
if (preg_match('#^/situation_contrat_auto/v1/(\d{14})$#', $path, $m)) {
  save_state($state);
  if ($scenario['new_contract_status'] !== 200) {
    return respond($scenario['new_contract_status'], ['code' => 'SGT' . $scenario['new_contract_status'], 'message' => "Le client n'est pas autorisé pour le point demandé"]);
  }
  return respond(200, ['contracts' => [[
    'usage_point_id' => $m[1],
    'contract_start' => '2021-03-15T00:00:00+0100',
    'contract_type' => 'Contrat GRD-F',
    'contractor' => 'EDF',
    'pricing_structure' => 'HPHC',
    'distribution_tariff' => 'BTINFMUDT',
    'subscribed_power' => ['unit' => 'kVA', 'value' => '9'],
    'segment' => 'C5',
    'customer' => ['person' => ['title' => 'M', 'lastname' => 'DUPONT', 'firstname' => 'Jean']],
  ]]]);
}
if (preg_match('#^/comptage_auto/v1/(\d{14})$#', $path, $m)) {
  save_state($state);
  if ($scenario['new_metering_status'] !== 200) {
    return respond($scenario['new_metering_status'], ['code' => 'SGT' . $scenario['new_metering_status'], 'message' => 'Situation comptage ou PRM non existant']);
  }
  return respond(200, [
    'dispositifComptage' => ['typeComptage' => ['code' => 'AMM', 'libelle' => 'Compteur Linky']],
    'compteurs' => [['numeroSerie' => '021876543210', 'regimePropriete' => ['code' => 'LOUE', 'libelle' => 'Loué']]],
    'relais' => ['plageHeuresCreuses' => 'HC (22H38-6H38)'],
  ]);
}

# ------------------------------------------------------ anciennes API v5
$legacyResources = [
  'daily_consumption' => ['CONS', 'daily'],
  'daily_production' => ['PROD', 'daily'],
  'consumption_load_curve' => ['CONS', 'load_curve'],
  'production_load_curve' => ['PROD', 'load_curve'],
];
if (preg_match('#^/metering_data_[a-z]+/v5/([a-z_]+)$#', $path, $m) && isset($legacyResources[$m[1]])) {
  save_state($state);
  if ($scenario['legacy_status'] !== 200) {
    return respond($scenario['legacy_status'], ['error' => 'no_data_found', 'error_description' => 'Erreur simulée API v5', 'error_uri' => 'https://bluecoder.enedis.fr']);
  }
  [$metier, $type] = $legacyResources[$m[1]];
  return respond(200, ['meter_reading' => [
    'usage_point_id' => $_GET['usage_point_id'] ?? '',
    'start' => $_GET['start'] ?? '',
    'end' => $_GET['end'] ?? '',
    'quality' => 'BRUT',
    'reading_type' => $type === 'daily'
      ? ['unit' => 'Wh', 'measurement_kind' => 'energy', 'aggregate' => 'sum', 'measuring_period' => 'P1D']
      : ['unit' => 'W', 'measurement_kind' => 'power', 'aggregate' => 'average'],
    'interval_reading' => reference_points($metier, $type, $_GET['start'] ?? '', $_GET['end'] ?? ''),
  ]]);
}
if ($path === '/customers_upc/v5/usage_points/contracts') {
  save_state($state);
  if ($scenario['legacy_status'] !== 200) {
    return respond($scenario['legacy_status'], ['error' => 'not_found', 'error_description' => 'Erreur simulée API v5']);
  }
  return respond(200, ['customer' => ['customer_id' => '1358019319', 'usage_points' => [[
    'usage_point' => ['usage_point_id' => $_GET['usage_point_id'] ?? '', 'usage_point_status' => 'com', 'meter_type' => 'AMM'],
    'contracts' => [
      'segment' => 'C5',
      'subscribed_power' => '9 kVA',
      'last_activation_date' => '2021-03-15+01:00',
      'distribution_tariff' => 'BTINFMUDT',
      'offpeak_hours' => 'HC (22H38-6H38)',
      'contract_type' => 'Contrat GRD-F',
      'contract_status' => 'SERVC',
      'last_distribution_tariff_change_date' => '2021-03-15+01:00',
    ],
  ]]]]);
}

save_state($state);
return respond(404, ['code' => '404', 'message' => 'No matching resource found for given API Request']);
