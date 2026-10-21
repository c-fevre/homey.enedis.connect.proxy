# Changelog

All notable changes to this project will be documented in this file.

## [1.3.0] - 2026-10-21

### Changed
- PHP 8.4.27 (image `php:8.4.27-apache`, CI and e2e mock on 8.4): PHP 8.2 reaches end of
  life on 2026-12-31, 8.4 is supported until the end of 2028. `composer.json` now requires
  PHP >= 8.4

### Fixed
- `composer.lock` is now committed and used by the Docker build, CI and e2e: dependency
  versions no longer drift between builds (a fresh resolution on PHP 8.4 picked
  `symfony/routing` 8.1, whose XML loader is gone, and broke every route in dev mode)
- Error-page preview routes (`when@dev`) now load `errors.php` instead of the deprecated
  `errors.xml`; the e2e suite boots the image in `APP_ENV=dev` as well
- `docker-compose.yml` defaults to `APP_ENV=prod` instead of `dev`
- An `AUTHORIZATION_ENDPOINT` still pointing to `/dataconnect/v1/` (pre-switchover `.env`)
  is upgraded to `/dataconnect/v2/` at runtime instead of sending users to the old page;
  `/health` then reports `authorize_upgraded_from: "v1"` until the `.env` is corrected

## [1.2.1] - 2026-10-21

### Security
- PHP base image pinned to 8.2.35, which fixes php2shell (CVE-2026-109618 and
  CVE-2026-109619): unauthenticated remote code execution reachable before any
  application code runs, worst on Apache mod_php, which is what this image runs
- PHP hardening in `docker/php/conf.d/zz-hardening.ini`: `expose_php=Off` (no more
  `X-Powered-By: PHP/x.y.z`), `session.upload_progress.enabled=Off` (confirmed
  exploitation path of CVE-2026-109619), `post_max_size=8M` and `upload_max_filesize=2M`
  instead of 100M (the proxy only receives small OAuth forms and JSON requests)
- The Apache vhost no longer sets any `php_value`; the ini file is the single source
- CD always re-resolves the base image on the registry (`pull: true`), so a patched PHP
  image can no longer be shadowed by a cached `FROM` layer
- End-to-end test asserting the PHP version, the effective ini values, the absence of
  Apache overrides and of the `X-Powered-By` header

## [1.2.0] - 2026-09-28

Enedis DataConnect switchover of 2026-09-28 (Implementing Act). Installed Homey apps keep
working unchanged: they still call the v5 paths and receive the v5 response format.

### Added
- Consent callback accepts the new `?autorisation_id=...&state=...` form and exchanges the
  authorization id for the PRM through the `subscribed_services/v1` API (with retries)
- `/data/proxy/{path}` translates v5 requests to the new APIs (`mesure_synchrone_auto/v2`,
  `situation_contrat_auto/v1`, `comptage_auto/v1`) and converts responses back to the v5 format
- `ENEDIS_API_MODE` (`auto` | `new` | `legacy`): `auto` tries the new APIs first and falls
  back to the v5 APIs on failure. Switch to `new` once Enedis has shut the v5 APIs down
- `SUBSCRIBED_SERVICES_PATH`, `LOAD_CURVE_TIMESTAMP`, `CONTRACT_DEGRADED_FALLBACK` settings
- `X-Enedis-Proxy-Source` response header (`new` | `legacy` | `degraded`)
- `/health` reports the authorize version and the API mode in use
- Unit tests (`tests/Unit`) and end-to-end tests against a mock Enedis server (`tests/e2e`)

### Changed
- Default `AUTHORIZATION_ENDPOINT` is now `dataconnect/v2/oauth2/authorize`
- Consent callback is idempotent: reloading it shows the confirmation again
- The `code` parameter is no longer required on the consent callback (CONSENT flow)
- Network timeouts on all calls to Enedis (10s connect, 30s total)

## [1.1.0] - 2025-11-16

### Added
- Two-tier client_id security system:
  - `EXTERNAL_CLIENT_ID`: Public key for external apps (Homey, mobile apps)
  - `CLIENT_ID`: Private Enedis Data Hub credentials (server-side only)
- Automatic mapping between external and Enedis credentials
- Client_id validation in `/device/code` and `/device/token` endpoints

### Changed
- External apps now use `EXTERNAL_CLIENT_ID` instead of direct Enedis `CLIENT_ID`
- Updated test-flow.sh to use `EXTERNAL_CLIENT_ID`
- Enhanced README with security architecture documentation

### Security
- Enedis credentials (`CLIENT_ID`/`CLIENT_SECRET`) never exposed to clients
- Invalid client_id attempts logged for security monitoring
- Prevents credential leakage in client applications

## [1.0.0] - 2025-11-16

### Added
- Initial stable release
- PostgreSQL cache implementation for device codes, tokens, and client credentials
- Enedis CONSENT flow support with usage_point_id handling
- Client credentials token management (automatic fetch and refresh, 3h30 TTL)
- Data API proxy with automatic client_credentials injection
- Docker Compose deployment setup
- Comprehensive debug logging for OAuth flow
- Cache management utilities

### Changed
- Migrated to Enedis API v3 endpoints:
  - Token endpoint: `/oauth2/v3/token` (from `/v1/oauth2/token`)
  - Data API paths: `/metering_data_*/v5/*` (from `/v4/metering_data/*`)
- Updated to client_credentials grant type per Enedis specifications
- Improved array/object handling for PostgreSQL cache serialization

### Fixed
- Cache data access bugs (array vs object inconsistencies)
- Token refresh mechanism for CONSENT flow
- Client credentials lifecycle management
- PostgreSQL cache expiry handling

### Technical Details
- Symfony 7.3 / PHP 8.2
- PostgreSQL 13+ for caching
- Compatible with Enedis Data Hub API v3
- Implements RFC 8628 Device Authorization Grant
- Adapts to Enedis non-standard CONSENT flow

### Security
- Client secret kept server-side (never exposed to device)
- Client credentials token cached securely with TTL
- HTTPS required for production deployment
- Rate limiting support via LIMIT_REQUESTS_PER_MINUTE

## [1.2.0] - 2025-11-17

### Changed
- Device page UI: responsive input/button on mobile (full width, font sizing with CSS clamp) to avoid overflow.
- Health endpoint `/health`: used by client for readiness checks.

### Security
- Logs hardening: redact sensitive values (`user_code`, `state`, tokens) in logs to avoid leaking secrets.
- Minimal per-IP rate limit on critical endpoints (`/device/code`, `/device/token`) with 429 when exceeded.
