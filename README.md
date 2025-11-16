OAuth 2.0 Device Flow Proxy Server for Enedis
=============================================

An OAuth 2.0 Device Code Flow proxy for [Homey](https://homey.app/fr-fr/app/com.clement-fevre.enedis.connect/Mon-Suivi-Conso-Enedis/) plugin, adapted to the [Enedis Data Hub](https://datahub-enedis.fr).

Un proxy OAuth 2.0 « Device Code Flow » pour Homey, adapte au Data Hub Enedis.

This project is a fork of https://github.com/aaronpk/Device-Flow-Proxy-Server, a big thanks to him.

This service acts as an OAuth server that implements the device code flow, proxying to a real OAuth server behind the scenes.

Compared to the original project, this implementation uses PostgreSQL instead of Redis, it sends back all parameters received during redirect (mainly to get usage_point_id from Enedis), it adds a feature to provide client_secret from .env file instead of getting it from device request, to keep it private, and it implements Enedis CONSENT flow with client_credentials for data API access.

Installation
------------

```bash
cp .env.example .env
composer install
```

In the `.env` file, fill out the required variables:
- Change `APP_SECRET` to a random string
- Generate `EXTERNAL_CLIENT_ID` for external apps (Homey):
  ```bash
  openssl rand -hex 20 | sed 's/^/hmey_prod_/'
  ```
- Set `CLIENT_ID` and `CLIENT_SECRET` from your Enedis Data Hub account (keep private)
- Configure endpoints (already set for Enedis API v3):
  - `TOKEN_ENDPOINT=https://gw.ext.prod.api.enedis.fr/oauth2/v3/token`
  - `DATA_ENDPOINT=https://gw.ext.prod.api.enedis.fr`
- Set `FLOW=CONSENT` to enable Enedis CONSENT flow with client_credentials

**Security Note**: The proxy uses two different client IDs:
- `EXTERNAL_CLIENT_ID`: Public key used by external apps (Homey, mobile apps, etc.)
- `CLIENT_ID`: Private Enedis Data Hub credentials (never exposed to clients)

The proxy automatically maps external requests to Enedis credentials, keeping your Enedis `CLIENT_ID` and `CLIENT_SECRET` secure.

You will need PostgreSQL (Docker Compose setup included) or point to an existing PostgreSQL server in the config file.


Usage
-----

The device will need to register an application at the OAuth server to get a client ID. You'll need to set the proxy's URL as the callback URL in the OAuth application registration:

```
http://localhost:8080/auth/redirect
```

The device can begin the flow by making a POST request to this proxy using the `EXTERNAL_CLIENT_ID`:

```bash
curl http://localhost:8080/device/code -d client_id=FILLME
```

Note: The `client_secret` is automatically managed by the proxy (stored in `.env`), devices never need to provide it.

Legacy syntax (if your device must provide client_secret):

```
curl http://localhost:8080/device/code -d client_id=1234567890 -d client_secret=12345678-1234-1234-1234-1234567890ab
```

The response will contain the URL the user should visit and the code they should enter, as well as a long device code.

```json
{
    "device_code": "5cb3a6029c967a7b04f642a5b92b5cca237ec19d41853f55dcce98a4d2aa528f",
    "user_code": "248707",
    "verification_uri": "http://localhost:8080/device",
    "expires_in": 300,
    "interval": 5
}
```

The device should instruct the user to visit the URL and enter the code, or can provide a full link that pre-fills the code for the user in case the device is displaying a QR code.

`http://localhost:8080/device?code=248707`

The device should then poll the token endpoint at the interval provided, making a POST request like the below:

```
curl http://localhost:8080/device/token -d grant_type=urn:ietf:params:oauth:grant-type:device_code \
  -d client_id=1234567890 \
  -d device_code=5cb3a6029c967a7b04f642a5b92b5cca237ec19d41853f55dcce98a4d2aa528f
```

While the user is busy logging in, the response will be

```
{"error":"authorization_pending"}
```

Once the user has finished logging in and granting access to the application, the response will contain an access token.

```json
{
  "access_token": "8YQZTbKML5Ntx2iuzdBJTvWE4XzIlSHeYmu4Y1GVpjrft2q768wavr",
  "refresh_token": "QcMhancv1wPyi8uwnkzcTNyd397oC7K0La8otPcssYMpXT",
  "token_type": "Bearer",
  "expires_in": 12600,
  "usage_point_id" : "1234567890abcd"
}
```

If the client_secret is not known by the device but is configured in the `.env` file, you can refresh the token with:

```
curl http://localhost:8080/device/proxy -d grant_type=refresh_token \
  -d client_id=1234567890 \
  -d refresh_token=QcMhancv1wPyi8uwnkzcTNyd397oC7K0La8otPcssYMpXT
```

or if the servers are using the client_credentials flow (if `FLOW` is unset in `.env` file):

```
curl http://localhost:8080/device/token -d grant_type=refresh_token \
  -d client_id=1234567890 \
  -d usages_point_id=1234567890abcd \
  -d refresh_token=QcMhancv1wPyi8uwnkzcTNyd397oC7K0La8otPcssYMpXT
```

You'll get a response with new access and refresh tokens.


```json
{
  "access_token": "6czyedyLUHvyjtWZuWwBLkXNZhzk9QLP9Cip5NPhFNmc8znWoPipnW",
  "refresh_token": "YpcX7v7sohTvDTWfzZpj4DyfZgvYtJKdNj7YHEhr3ZH7FCiqSDCDJ2",
  "token_type": "Bearer",
  "expires_in": 12600,
  "usage_point_id" : "1234567890abcd"
}
```

If the servers are not using the client_credentials flow (if `FLOW` is set to `DEVICE` in `.env` file), you can now send your data request to final server with the obtained access_token.

If the servers are using the client_credentials flow (if `FLOW` is set to `CONSENT` in `.env` file), you can now send your data request through the proxy. The proxy will automatically obtain and refresh client_credentials tokens from Enedis.

Example for daily consumption data (Enedis API v5):

```bash
curl --header "Authorization: Bearer 6czyedyLUHvyjtWZuWwBLkXNZhzk9QLP9Cip5NPhFNmc8znWoPipnW" \
    "http://localhost:8080/data/proxy/metering_data_dc/v5/daily_consumption?usage_point_id=19182633854086&start=2025-11-01&end=2025-11-15"
```

Available Enedis Data API v5 endpoints:
- `/metering_data_dc/v5/daily_consumption` - Consommation journaliere
- `/metering_data_dcmp/v5/daily_consumption_max_power` - Puissance maximale journaliere
- `/metering_data_clc/v5/consumption_load_curve` - Courbe de charge consommation
- `/metering_data_dp/v5/daily_production` - Production journaliere
- `/metering_data_plc/v5/production_load_curve` - Courbe de charge production

Enedis CONSENT Flow Architecture
---------------------------------

This proxy implements Enedis specific CONSENT flow:

1. User authenticates via Enedis OAuth and grants consent
2. Enedis redirects with `usage_point_id` (no authorization_code)
3. Proxy generates internal `access_token` and `refresh_token` for the device
4. Device uses these tokens to authenticate API requests to the proxy
5. Proxy automatically obtains `client_credentials` token from Enedis (valid 3h30, shared across all consented users)
6. Proxy forwards data requests to Enedis API with the `client_credentials` token

Cache Management
----------------

The proxy uses PostgreSQL to cache:
- Device codes and user codes (300s expiry)
- User consent state (300s expiry)
- Internal access/refresh tokens (no expiry until refresh)
- Client credentials tokens (12600s / 3h30 expiry)

To clear the cache:

```bash
docker compose exec -T db psql -U $DB_USER -d $DB_NAME -c "TRUNCATE TABLE cache;"
```

Notes
-----

- Apache AH00558 warning suppressed via ServerName directive in Docker image
- Compatible with Enedis API v3 (migration from v1 completed November 2024)
- Client credentials mechanism implemented per Enedis specifications
