// Tests de bout en bout du proxy (voir run.sh).
// Le faux serveur Enedis sert les mêmes données via les anciennes API v5 et via les
// nouvelles API : la réponse traduite par le proxy doit être identique à la réponse v5.
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';

const AUTO = process.env.PROXY_AUTO || 'http://127.0.0.1:18080';
const NEW = process.env.PROXY_NEW || 'http://127.0.0.1:18081';
const LEGACY = process.env.PROXY_LEGACY || 'http://127.0.0.1:18082';
const MOCK = process.env.MOCK_ENEDIS || 'http://127.0.0.1:19000';
const CLIENT_ID = 'hmey_e2e_public_client_id';

const START = '2026-09-25';
const END = '2026-09-27';

const ENDPOINTS = [
  { legacy: 'metering_data_dc/v5/daily_consumption', fresh: '/mesure_synchrone_auto/v2/consommation_quotidienne', metier: 'CONS', type: 'daily' },
  { legacy: 'metering_data_clc/v5/consumption_load_curve', fresh: '/mesure_synchrone_auto/v2/courbe_de_charge_consommation', metier: 'CONS', type: 'load_curve' },
  { legacy: 'metering_data_dp/v5/daily_production', fresh: '/mesure_synchrone_auto/v2/production_quotidienne', metier: 'PROD', type: 'daily' },
  { legacy: 'metering_data_plc/v5/production_load_curve', fresh: '/mesure_synchrone_auto/v2/courbe_de_charge_production', metier: 'PROD', type: 'load_curve' },
];

// ------------------------------------------------------------------ helpers

let prmCounter = 0;
// Un PRM différent par test : les états mis en cache par le proxy sont propres à chaque PRM
const nextPrm = () => String(30000000000000 + (++prmCounter) + (Date.now() % 100000) * 1000).slice(0, 14);

async function mockControl(changes) {
  const r = await fetch(`${MOCK}/__control`, { method: 'POST', body: JSON.stringify(changes) });
  assert.equal(r.status, 200);
}
const mockReset = async () => { await fetch(`${MOCK}/__reset`, { method: 'POST' }); };
const mockClearRequests = async () => { await fetch(`${MOCK}/__requests`, { method: 'DELETE' }); };
const mockRequests = async () => (await fetch(`${MOCK}/__requests`)).json();
const dataCalls = async () => (await mockRequests()).filter(r => !r.path.includes('/oauth2/'));

async function reference(metier, type, start = START, end = END) {
  const r = await fetch(`${MOCK}/__reference?metier=${metier}&type=${type}&start=${start}&end=${end}`);
  return r.json();
}

const form = obj => new URLSearchParams(obj).toString();
const post = (base, route, body) => fetch(`${base}${route}`, {
  method: 'POST',
  headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
  body: form(body),
});

async function startFlow(base = AUTO) {
  const r = await post(base, '/device/code', { client_id: CLIENT_ID });
  assert.equal(r.status, 200, 'device/code');
  const code = await r.json();
  assert.ok(code.device_code && code.user_code);

  const verify = await fetch(`${base}/auth/verify_code?code=${encodeURIComponent(code.user_code)}`, { redirect: 'manual' });
  assert.equal(verify.status, 302, 'verify_code redirige vers Enedis');
  const location = new URL(verify.headers.get('location'));
  return { ...code, location, state: location.searchParams.get('state') };
}

async function callback(base, params, route = '/auth/redirect') {
  const r = await fetch(`${base}${route}?${form(params)}`);
  return { status: r.status, html: await r.text() };
}

async function pollToken(base, deviceCode) {
  const r = await post(base, '/device/token', {
    grant_type: 'urn:ietf:params:oauth:grant-type:device_code',
    client_id: CLIENT_ID,
    device_code: deviceCode,
  });
  return { status: r.status, body: await r.json() };
}

// Appairage complet via le nouveau retour Enedis (autorisation_id)
async function pair(prm, base = AUTO) {
  await mockControl({ prm_for_autorisation: prm });
  const flow = await startFlow(base);
  const cb = await callback(base, { autorisation_id: '987654321', state: flow.state });
  assert.match(cb.html, /Consentement obtenu/);
  const token = await pollToken(base, flow.device_code);
  assert.equal(token.status, 200);
  return token.body;
}

async function getData(base, legacyPath, prm, token, { start = START, end = END } = {}) {
  const query = legacyPath.includes('contracts') ? { usage_point_id: prm } : { usage_point_id: prm, start, end };
  const r = await fetch(`${base}/data/proxy/${legacyPath}?${form(query)}`, {
    headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
  });
  const text = await r.text();
  let json = null;
  try { json = JSON.parse(text); } catch (e) { /* corps non JSON */ }
  return { status: r.status, source: r.headers.get('x-enedis-proxy-source'), json, text };
}

test.before(async () => {
  await mockReset();
});
test.beforeEach(async () => {
  // Remet le scénario nominal sans effacer les jetons déjà émis par le faux serveur
  await mockControl({
    token_status: 200, subscribed_status: 200, subscribed_empty_first: 0,
    new_measure_status: 200, new_contract_status: 200, new_metering_status: 200,
    legacy_status: 200, timestamp_style: 'end', unit: 'base', rate_limit_first: 0,
  });
  await mockClearRequests();
});

// ------------------------------------------------------------- santé / config

test('health expose le statut, la version authorize et le mode', async () => {
  for (const [base, mode] of [[AUTO, 'auto'], [NEW, 'new'], [LEGACY, 'legacy']]) {
    const r = await fetch(`${base}/health`);
    assert.equal(r.status, 200);
    const text = await r.text();
    assert.ok(text.includes('"status":"ok"'), 'le healthcheck Docker cherche cette chaîne exacte');
    const json = JSON.parse(text);
    assert.equal(json.authorize, 'v2');
    assert.equal(json.api_mode, mode);
  }
});

// ------------------------------------------------------ parcours consentement

test('redirection vers la page de consentement Enedis v2', async () => {
  const flow = await startFlow();
  assert.equal(flow.location.pathname, '/dataconnect/v2/oauth2/authorize');
  assert.equal(flow.location.searchParams.get('client_id'), 'e2e-enedis-client-id', 'identifiant Enedis privé, pas l\'identifiant public');
  assert.equal(flow.location.searchParams.get('response_type'), 'code');
  assert.equal(flow.location.searchParams.get('duration'), 'P1095D');
  assert.ok(flow.state.length >= 32 && flow.state.length <= 100, 'state limité à 100 caractères par Enedis');
  assert.deepEqual([...flow.location.searchParams.keys()].sort(), ['client_id', 'duration', 'response_type', 'state']);
});

test('nouveau retour Enedis : autorisation_id échangé contre le PRM', async () => {
  const prm = nextPrm();
  await mockControl({ prm_for_autorisation: prm });
  const flow = await startFlow();

  const pending = await pollToken(AUTO, flow.device_code);
  assert.equal(pending.body.error, 'authorization_pending');

  // Forme exacte annoncée par Enedis : <redirect_url>?autorisation_id=...&state=... (sans code)
  const cb = await callback(AUTO, { autorisation_id: '987654321', state: flow.state });
  assert.equal(cb.status, 200);
  assert.match(cb.html, /Consentement obtenu/);

  const calls = (await mockRequests()).filter(r => r.path.startsWith('/subscribed_services/v1'));
  assert.equal(calls.length, 1);
  assert.equal(calls[0].method, 'POST');
  assert.match(calls[0].content_type, /application\/json/);
  assert.match(calls[0].authorization, /^Bearer mock-token-/);
  assert.deepEqual(JSON.parse(calls[0].body), {
    autorisationId: 987654321, comptage: false, etatCode: ['ACTIF', 'DEMANDE'], serviceType: 'ACCES',
  });

  const token = await pollToken(AUTO, flow.device_code);
  assert.equal(token.status, 200);
  assert.equal(token.body.usage_points_id, prm);
  assert.equal(token.body.token_type, 'Bearer');
  assert.ok(token.body.access_token && token.body.refresh_token);
  assert.equal(token.body.expires_in, 12600);

  // Le device_code est à usage unique
  const again = await pollToken(AUTO, flow.device_code);
  assert.equal(again.body.error, 'invalid_grant');
});

test('retour rechargé (sortie d\'iframe) : la confirmation est réaffichée, sans nouvel échange', async () => {
  const prm = nextPrm();
  await mockControl({ prm_for_autorisation: prm });
  const flow = await startFlow();
  const params = { autorisation_id: '42', state: flow.state };
  assert.match((await callback(AUTO, params)).html, /Consentement obtenu/);
  await mockClearRequests();
  assert.match((await callback(AUTO, params)).html, /Consentement obtenu/);
  assert.match((await callback(AUTO, params, '/auth/redirect_parent')).html, /Consentement obtenu/);
  assert.equal((await dataCalls()).length, 0);
  const token = await pollToken(AUTO, flow.device_code);
  assert.equal(token.body.usage_points_id, prm);
});

test('route /auth/redirect_parent acceptée pour le nouveau retour', async () => {
  const prm = nextPrm();
  await mockControl({ prm_for_autorisation: prm });
  const flow = await startFlow();
  const cb = await callback(AUTO, { autorisation_id: '43', state: flow.state }, '/auth/redirect_parent');
  assert.match(cb.html, /Consentement obtenu/);
  assert.equal((await pollToken(AUTO, flow.device_code)).body.usage_points_id, prm);
});

test('ancien retour Enedis (usage_point_id) toujours accepté', async () => {
  const prm = nextPrm();
  const flow = await startFlow();
  const cb = await callback(AUTO, { code: 'abc', usage_point_id: prm, state: flow.state });
  assert.match(cb.html, /Consentement obtenu/);
  assert.equal((await dataCalls()).length, 0, 'aucun appel Enedis nécessaire');
  assert.equal((await pollToken(AUTO, flow.device_code)).body.usage_points_id, prm);
});

test('services souscrits pas encore disponibles : nouvelles tentatives puis succès', async () => {
  const prm = nextPrm();
  await mockControl({ prm_for_autorisation: prm, subscribed_empty_first: 2 });
  const flow = await startFlow();
  const cb = await callback(AUTO, { autorisation_id: '44', state: flow.state });
  assert.match(cb.html, /Consentement obtenu/);
  assert.equal((await mockRequests()).filter(r => r.path.startsWith('/subscribed_services')).length, 3);
  assert.equal((await pollToken(AUTO, flow.device_code)).body.usage_points_id, prm);
});

test('services souscrits en erreur : message clair, puis succès au rechargement', async () => {
  const prm = nextPrm();
  await mockControl({ prm_for_autorisation: prm, subscribed_status: 500 });
  const flow = await startFlow();
  const params = { autorisation_id: '45', state: flow.state };

  const failed = await callback(AUTO, params);
  assert.match(failed.html, /Rechargez cette page/);
  assert.doesNotMatch(failed.html, /Consentement obtenu/);
  assert.equal((await pollToken(AUTO, flow.device_code)).body.error, 'authorization_pending');

  await mockControl({ prm_for_autorisation: prm, subscribed_status: 200 });
  assert.match((await callback(AUTO, params)).html, /Consentement obtenu/);
  assert.equal((await pollToken(AUTO, flow.device_code)).body.usage_points_id, prm);
});

test('services souscrits refusé (403) : pas de jeton émis', async () => {
  await mockControl({ subscribed_status: 403 });
  const flow = await startFlow();
  const failed = await callback(AUTO, { autorisation_id: '46', state: flow.state });
  assert.doesNotMatch(failed.html, /Consentement obtenu/);
  assert.equal((await pollToken(AUTO, flow.device_code)).body.error, 'authorization_pending');
});

test('retours invalides : erreur Enedis, state inconnu, paramètres manquants', async () => {
  const flow = await startFlow();

  const refused = await callback(AUTO, { error: 'invalid_request', error_description: 'demande_non_aboutie', state: flow.state });
  assert.match(refused.html, /demande_non_aboutie/);

  assert.match((await callback(AUTO, {})).html, /Des paramètres manquent/);
  assert.match((await callback(AUTO, { autorisation_id: '1', state: 'inconnu' })).html, /state n&#039;est pas valide|state n'est pas valide/);
  assert.match((await callback(AUTO, { state: flow.state })).html, /autorisation_id/);
  assert.doesNotMatch((await callback(AUTO, { autorisation_id: '../../etc', state: flow.state })).html, /Consentement obtenu/);

  assert.equal((await pollToken(AUTO, flow.device_code)).body.error, 'authorization_pending');
  // Le parcours reste utilisable après ces erreurs
  assert.match((await callback(AUTO, { autorisation_id: '47', state: flow.state })).html, /Consentement obtenu/);
});

// ------------------------------------------------------------------ données

for (const style of ['end', 'start', 'iso']) {
  for (const unit of ['base', 'kilo']) {
    test(`mesures via les nouvelles API identiques au format v5 (horodatage ${style}, unité ${unit})`, async () => {
      const prm = nextPrm();
      const tokens = await pair(prm);
      await mockControl({ timestamp_style: style, unit });

      for (const ep of ENDPOINTS) {
        await mockClearRequests();
        const viaNew = await getData(AUTO, ep.legacy, prm, tokens.access_token);
        assert.equal(viaNew.status, 200, ep.legacy);
        assert.equal(viaNew.source, 'new', ep.legacy);

        const calls = await dataCalls();
        assert.equal(calls.length, 1, 'un seul appel Enedis');
        assert.equal(calls[0].path, ep.fresh);
        assert.deepEqual(calls[0].query, { pointId: prm, dateDebut: START, dateFin: END });
        assert.equal(calls[0].content_type, null, 'pas de Content-Type sur un GET');

        const viaLegacy = await getData(LEGACY, ep.legacy, prm, tokens.access_token);
        assert.equal(viaLegacy.source, 'legacy');
        assert.deepEqual(viaNew.json, viaLegacy.json, `${ep.legacy} : réponse traduite différente de la réponse v5`);

        const expected = await reference(ep.metier, ep.type);
        assert.deepEqual(viaNew.json.meter_reading.interval_reading, expected);
        assert.equal(expected.length, ep.type === 'daily' ? 2 : 96);
      }
    });
  }
}

test('une seule journée demandée (start == end) : journée complète renvoyée', async () => {
  const prm = nextPrm();
  const tokens = await pair(prm);
  const r = await getData(AUTO, 'metering_data_plc/v5/production_load_curve', prm, tokens.access_token, { start: '2026-09-26', end: '2026-09-26' });
  assert.equal(r.status, 200);
  assert.equal(r.json.meter_reading.interval_reading.length, 48);
  const call = (await dataCalls()).at(-1);
  assert.deepEqual(call.query, { pointId: prm, dateDebut: '2026-09-26', dateFin: '2026-09-27' });
});

test('contrat : nouvelles API traduites au format v5', async () => {
  const prm = nextPrm();
  const tokens = await pair(prm);
  await mockClearRequests();

  const viaNew = await getData(AUTO, 'customers_upc/v5/usage_points/contracts', prm, tokens.access_token);
  assert.equal(viaNew.status, 200);
  assert.equal(viaNew.source, 'new');
  assert.deepEqual((await dataCalls()).map(c => c.path), [`/situation_contrat_auto/v1/${prm}`, `/comptage_auto/v1/${prm}`]);

  const up = viaNew.json.customer.usage_points[0];
  assert.deepEqual(up.usage_point, { usage_point_id: prm, usage_point_status: 'com', meter_type: 'AMM' });
  assert.equal(up.contracts.subscribed_power, '9 kVA');
  assert.equal(up.contracts.segment, 'C5');
  assert.equal(up.contracts.distribution_tariff, 'BTINFMUDT');
  assert.equal(up.contracts.contract_type, 'Contrat GRD-F');
  assert.equal(up.contracts.offpeak_hours, 'HC (22H38-6H38)');
  assert.equal(up.contracts.last_activation_date, '2021-03-15+01:00');
  assert.ok(!viaNew.text.includes('DUPONT'), 'les données personnelles du titulaire ne sont pas relayées');

  // Mêmes valeurs que l'ancienne API pour tous les champs lus par l'application
  const legacyUp = (await getData(LEGACY, 'customers_upc/v5/usage_points/contracts', prm, tokens.access_token)).json.customer.usage_points[0];
  for (const key of ['segment', 'subscribed_power', 'distribution_tariff', 'offpeak_hours', 'contract_type', 'last_activation_date']) {
    assert.equal(up.contracts[key], legacyUp.contracts[key], key);
  }
  assert.deepEqual(up.usage_point, legacyUp.usage_point);
});

test('contrat : une seule des deux nouvelles API répond', async () => {
  const prm = nextPrm();
  const tokens = await pair(prm);
  await mockControl({ new_metering_status: 404 });
  const r = await getData(AUTO, 'customers_upc/v5/usage_points/contracts', prm, tokens.access_token);
  assert.equal(r.status, 200);
  assert.equal(r.source, 'new');
  assert.equal(r.json.customer.usage_points[0].contracts.subscribed_power, '9 kVA');
  assert.equal(r.json.customer.usage_points[0].contracts.offpeak_hours, '');
});

// ------------------------------------------------------------------- replis

test('nouvelle API refusée (403) : repli sur v5, puis nouvelle API évitée pour ce PRM', async () => {
  const prm = nextPrm();
  const tokens = await pair(prm);
  await mockControl({ new_measure_status: 403 });
  await mockClearRequests();
  const tokenCallsBefore = (await mockRequests()).length;
  assert.equal(tokenCallsBefore, 0);

  const first = await getData(AUTO, ENDPOINTS[1].legacy, prm, tokens.access_token);
  assert.equal(first.status, 200);
  assert.equal(first.source, 'legacy');
  assert.deepEqual(first.json.meter_reading.interval_reading, await reference('CONS', 'load_curve'));

  await mockClearRequests();
  const second = await getData(AUTO, ENDPOINTS[1].legacy, prm, tokens.access_token);
  assert.equal(second.source, 'legacy');
  const calls = await mockRequests();
  assert.deepEqual(calls.map(c => c.path), [`/${ENDPOINTS[1].legacy}`], 'ni nouvelle API ni renouvellement de jeton');

  // Un autre PRM n'est pas pénalisé par le refus du premier
  await mockControl({ new_measure_status: 200 });
  const other = nextPrm();
  const otherTokens = await pair(other);
  assert.equal((await getData(AUTO, ENDPOINTS[1].legacy, other, otherTokens.access_token)).source, 'new');
});

test('nouvelle API en panne (500) : repli sur v5', async () => {
  const prm = nextPrm();
  const tokens = await pair(prm);
  await mockControl({ new_measure_status: 500 });
  const r = await getData(AUTO, ENDPOINTS[0].legacy, prm, tokens.access_token);
  assert.equal(r.status, 200);
  assert.equal(r.source, 'legacy');
  assert.deepEqual(r.json.meter_reading.interval_reading, await reference('CONS', 'daily'));
});

test('API v5 arrêtées : les nouvelles API suffisent', async () => {
  const prm = nextPrm();
  const tokens = await pair(prm);
  await mockControl({ legacy_status: 404 });
  for (const ep of ENDPOINTS) {
    const r = await getData(AUTO, ep.legacy, prm, tokens.access_token);
    assert.equal(r.status, 200, ep.legacy);
    assert.equal(r.source, 'new');
  }
  const contract = await getData(AUTO, 'customers_upc/v5/usage_points/contracts', prm, tokens.access_token);
  assert.equal(contract.status, 200);
  assert.equal(contract.source, 'new');
});

test('aucune donnée des deux côtés : erreur métier lisible', async () => {
  const prm = nextPrm();
  const tokens = await pair(prm);
  await mockControl({ new_measure_status: 404, legacy_status: 404 });
  const r = await getData(AUTO, ENDPOINTS[3].legacy, prm, tokens.access_token);
  assert.equal(r.status, 404);
  assert.equal(r.json.error_description, 'Pas de mesure trouvée pour ce point');
  assert.ok(r.json.error);
});

test('quota dépassé (429) : renvoyé tel quel, sans repli', async () => {
  const prm = nextPrm();
  const tokens = await pair(prm);
  await mockControl({ new_measure_status: 429 });
  await mockClearRequests();
  const r = await getData(AUTO, ENDPOINTS[0].legacy, prm, tokens.access_token);
  assert.equal(r.status, 429);
  assert.equal((await dataCalls()).filter(c => c.path.startsWith('/metering_data')).length, 0);
});

test('quota dépassé une fois : nouvelle tentative réussie', async () => {
  const prm = nextPrm();
  const tokens = await pair(prm);
  await mockControl({ rate_limit_first: 1 });
  await mockClearRequests();
  const r = await getData(AUTO, ENDPOINTS[1].legacy, prm, tokens.access_token);
  assert.equal(r.status, 200);
  assert.equal(r.source, 'new');
  assert.equal((await dataCalls()).length, 2, 'deux appels à la nouvelle API, aucun repli');
  assert.deepEqual(r.json.meter_reading.interval_reading, await reference('CONS', 'load_curve'));
});

test('contrat indisponible partout : réponse minimale pour ne pas bloquer l\'appairage', async () => {
  const prm = nextPrm();
  const tokens = await pair(prm);
  await mockControl({ new_contract_status: 403, new_metering_status: 403, legacy_status: 404 });
  const r = await getData(AUTO, 'customers_upc/v5/usage_points/contracts', prm, tokens.access_token);
  assert.equal(r.status, 200);
  assert.equal(r.source, 'degraded');
  assert.equal(r.json.customer.usage_points.length, 1);
  assert.equal(r.json.customer.usage_points[0].usage_point.usage_point_id, prm);
});

test('mode new : pas de repli ; mode legacy : pas de nouvelle API', async () => {
  const prm = nextPrm();
  const tokens = await pair(prm);

  await mockControl({ new_measure_status: 500 });
  await mockClearRequests();
  const strict = await getData(NEW, ENDPOINTS[0].legacy, prm, tokens.access_token);
  assert.equal(strict.status, 500);
  assert.equal((await dataCalls()).filter(c => c.path.startsWith('/metering_data')).length, 0);

  await mockControl({ new_measure_status: 200 });
  assert.equal((await getData(NEW, ENDPOINTS[0].legacy, prm, tokens.access_token)).source, 'new');

  await mockClearRequests();
  const old = await getData(LEGACY, ENDPOINTS[0].legacy, prm, tokens.access_token);
  assert.equal(old.source, 'legacy');
  assert.deepEqual((await dataCalls()).map(c => c.path), [`/${ENDPOINTS[0].legacy}`]);
});

test('jeton Enedis expiré (401) : renouvelé automatiquement', async () => {
  const prm = nextPrm();
  const tokens = await pair(prm);
  // Réinitialise le faux serveur : les jetons déjà émis (et mis en cache par le proxy) deviennent invalides
  await mockReset();
  const r = await getData(AUTO, ENDPOINTS[0].legacy, prm, tokens.access_token);
  assert.equal(r.status, 200);
  assert.equal(r.source, 'new');
  const calls = await mockRequests();
  assert.equal(calls.filter(c => c.path.includes('/oauth2/')).length, 1);
});

// -------------------------------------------------------------- sécurité

test('contrôles d\'accès inchangés', async () => {
  const prm = nextPrm();
  const tokens = await pair(prm);
  const route = `${AUTO}/data/proxy/${ENDPOINTS[0].legacy}`;
  const q = p => `?${form({ usage_point_id: p, start: START, end: END })}`;
  await mockClearRequests();

  assert.equal((await fetch(`${route}${q(prm)}`)).status, 404, 'Authorization manquant');
  assert.equal((await fetch(`${route}${q(prm)}`, { headers: { Authorization: 'Bearer faux' } })).status, 403, 'jeton inconnu');
  assert.equal((await fetch(`${route}${q('11111111111111')}`, { headers: { Authorization: `Bearer ${tokens.access_token}` } })).status, 404, 'PRM d\'un autre utilisateur');
  assert.equal((await fetch(`${route}?start=${START}&end=${END}`, { headers: { Authorization: `Bearer ${tokens.access_token}` } })).status, 400, 'PRM manquant');
  assert.equal((await mockRequests()).length, 0, 'aucun appel Enedis pour une requête refusée');

  const bad = await post(AUTO, '/device/code', { client_id: 'mauvais' });
  assert.equal(bad.status, 400);
});

test('renouvellement du jeton de l\'application', async () => {
  const prm = nextPrm();
  const tokens = await pair(prm);
  const r = await post(AUTO, '/device/token', {
    grant_type: 'refresh_token', client_id: CLIENT_ID, refresh_token: tokens.refresh_token, usage_points_id: prm,
  });
  assert.equal(r.status, 200);
  const refreshed = await r.json();
  assert.notEqual(refreshed.access_token, tokens.access_token);
  assert.equal((await getData(AUTO, ENDPOINTS[0].legacy, prm, refreshed.access_token)).status, 200);
});

// ------------------------------------------- vrai client de l'application Homey

const here = path.dirname(fileURLToPath(import.meta.url));
const APP_DIR = process.env.APP_DIR || path.resolve(here, '../../../com.clement-fevre.enedis.connect');
const hasApp = fs.existsSync(path.join(APP_DIR, 'lib/EnedisAPI.js')) && fs.existsSync(path.join(APP_DIR, 'node_modules/node-fetch'));

test('application Homey réelle (EnedisAPI + TokenManager) contre le proxy', { skip: hasApp ? false : `application introuvable dans ${APP_DIR}` }, async () => {
  const require = createRequire(path.join(APP_DIR, 'app.js'));
  const Module = require('node:module');
  const originalLoad = Module._load;
  // Le module « homey » n'existe que sur un Homey Pro
  Module._load = function load(request, ...rest) {
    if (request === 'homey') return {};
    return originalLoad.call(this, request, ...rest);
  };

  try {
    const constants = require('./lib/constants');
    constants.API.PROXY_BASE_URL = AUTO;
    constants.API.BASE_URL_PRODUCTION = `${AUTO}/data/proxy`;
    constants.API.EXTERNAL_CLIENT_ID = CLIENT_ID;
    const EnedisAPI = require('./lib/EnedisAPI');
    const { parseOffpeakHours } = require('./lib/utils');

    const store = new Map();
    const homey = { settings: { get: k => store.get(k) ?? null, set: (k, v) => store.set(k, v), unset: k => store.delete(k) } };
    const errors = [];
    const api = new EnedisAPI({
      environment: 'production', homey, log: () => {}, error: (...a) => errors.push(a.join(' ')),
    });

    // Appairage : l'application démarre le flux, l'utilisateur consent chez Enedis
    const prm = nextPrm();
    await mockControl({ prm_for_autorisation: prm });
    const flow = await api.tokenManager.startDeviceCodeFlow();
    const verify = await fetch(`${AUTO}/auth/verify_code?code=${encodeURIComponent(flow.user_code)}`, { redirect: 'manual' });
    const state = new URL(verify.headers.get('location')).searchParams.get('state');
    await assert.rejects(api.tokenManager.pollForTokenOnce(flow.device_code), /authorization_pending/);
    assert.match((await callback(AUTO, { autorisation_id: '5550001', state })).html, /Consentement obtenu/);
    await api.tokenManager.pollForTokenOnce(flow.device_code);
    assert.equal(await api.tokenManager.getUsagePointId(), prm);

    // Les API v5 sont arrêtées : seul le nouveau parcours peut répondre
    await mockControl({ legacy_status: 404 });

    // Même lecture que drivers/pdl/driver.js lors de l'ajout du compteur
    const contracts = await api.getContracts(prm);
    assert.ok(contracts.customer && contracts.customer.usage_points && contracts.customer.usage_points.length > 0);
    const usagePoint = contracts.customer.usage_points[0].usage_point;
    const contract = contracts.customer.usage_points[0].contracts;
    assert.equal(usagePoint.usage_point_id, prm);
    assert.equal(contract.subscribed_power, '9 kVA');
    const tariff = parseOffpeakHours(contract.offpeak_hours);
    assert.ok(tariff && tariff.periods.length > 0, 'heures creuses comprises par le moteur tarifaire');
    const activation = new Date(contract.last_activation_date.split('+')[0]);
    assert.ok(!Number.isNaN(activation.getTime()), 'date d\'activation lisible par device.js');

    // Même lecture que drivers/pdl/device.js pour la puissance temps réel
    const curve = await api.getLoadCurve(prm, '2026-09-26', '2026-09-27');
    const readings = curve.meter_reading.interval_reading;
    assert.equal(readings.length, 48);
    const match = readings.find(r => r.date === '2026-09-26 18:30:00');
    assert.ok(match, 'tranche horaire retrouvée par égalité stricte de chaîne');
    assert.ok(Number.isInteger(parseInt(match.value, 10)));
    assert.ok(!Number.isNaN(new Date(match.date.replace(' ', 'T')).getTime()));
    assert.equal(readings.at(-1).date, '2026-09-27 00:00:00');

    const daily = await api.getDailyConsumption(prm, '2026-09-20', '2026-09-27');
    assert.equal(daily.meter_reading.interval_reading.length, 7);
    assert.match(daily.meter_reading.interval_reading[0].date, /^\d{4}-\d{2}-\d{2}$/);
    const totalKwh = daily.meter_reading.interval_reading.reduce((sum, r) => sum + parseInt(r.value, 10), 0) / 1000;
    assert.ok(totalKwh > 0);

    // Détection de la production (driver.js appelle avec start == end)
    const prod = await api.getProductionLoadCurve(prm, '2026-09-26', '2026-09-26');
    assert.equal(prod.meter_reading.interval_reading.length, 48);
    assert.equal((await api.getDailyProduction(prm, '2026-09-25', '2026-09-27')).meter_reading.interval_reading.length, 2);

    // Compteur sans production : l'erreur remonte comme avant (production non détectée)
    await mockControl({ legacy_status: 404, new_measure_status: 404 });
    await assert.rejects(api.getProductionLoadCurve(prm, '2026-09-26', '2026-09-26'), /HTTP 404/);

    // Refus Enedis : l'application renouvelle son jeton auprès du proxy et reste connectée
    await mockControl({ legacy_status: 403, new_measure_status: 403 });
    const before = store.get('access_token');
    await assert.rejects(api.getLoadCurve(prm, '2026-09-26', '2026-09-27'), /HTTP 403/);
    assert.ok(store.get('access_token'), 'session conservée');
    assert.notEqual(store.get('access_token'), before, 'jeton renouvelé');

    await mockControl({ legacy_status: 404, new_measure_status: 200 });
    const otherPrm = nextPrm();
    await assert.rejects(api.getLoadCurve(otherPrm, '2026-09-26', '2026-09-27'), /HTTP 404/, 'PRM non consenti refusé');
  } finally {
    Module._load = originalLoad;
  }
});
