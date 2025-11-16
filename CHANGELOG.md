# Changelog

All notable changes to this project will be documented in this file.

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
