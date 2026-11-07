# Changelog

All notable changes to **EQ Finance** will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [1.0.2] - 2026-11-07

### Added
- **Institutional Design System**: Completely overhauled UI across the Admin Panel, API Documentation, and Testing Studio. Replaced "vibe-coded" neon glows and radial gradients with a matte Obsidian Slate & Cobalt palette, crisp borders, and left-aligned structured typography.
- **API Key Status Filtering & Exact Synchronization**: Added live status tabs on the API Keys page (`All`, `Active`, `Disabled`, `Revoked`, `Expired`) with precise count pills matching the Dashboard metric card.
- **Expired Key Categorization**: Added SQL UTC timestamp comparison in `StatsService::dashboard()` and `ApiKeyService::counts()` to accurately separate active keys from expired keys.
- **Strict MySQL `ONLY_FULL_GROUP_BY` Compatibility**: Fixed column aggregations in `StatsService::perKeyUsage()`.

### Changed
- All card headers, forms, company data, and status indicators are strictly left-aligned; financial numbers and metric grids feature right-aligned tabular numerals (`font-variant-numeric: tabular-nums`).
- Added bidirectional cross-navigation links between Documentation, Admin Panel, and API Studio.

## [1.0.1] - 2026-11-07

### Added
- **Embedded JSON Extractor**: Direct parsing of Google Finance `AF_initDataCallback` streams, delivering 10x faster extraction and full compatibility with Google's modern Wiz layout.
- **God-Level Testing Studio 2.0**: Completely redesigned dark glassmorphism dashboard with Day Range & 52-Week Range visual track bars, financial metrics grid, live cURL command generator, rate quota battery meter, and latency tracker.
- **Smart URL Normalization**: Automatic detection and normalization of base URLs (`http://host:port` vs `http://host:port/api/v1`) preventing 404 routing errors across network clients.
- **LAN / Network Hosting Support**: Added explicit Apache `<Directory /var/www/html>` configuration in Docker and standalone `test-client.html` for zero-configuration laptop-to-server access.

### Fixed
- Fixed `EXTRACTION_FAILED` error caused by Google Finance migrating away from legacy DOM classes to JSON payloads.
- Fixed 404 routing when accessing Testing Studio on external IP addresses over LAN.

## [1.0.0] - 2026-11-06

### Added
- **Core Scraping Engine**: High-resilience DOM/XPath extractor for Google Finance quote pages with fallback strategies and strict error mapping (`EXTRACTION_FAILED`, `SOURCE_BLOCKED`, `SYMBOL_NOT_FOUND`).
- **REST API Endpoints**:
  - `GET /api/v1/stock`: Single quote retrieval with cache headers and rate limit metadata.
  - `GET /api/v1/stocks`: Batch quote queries with time-budget protection.
  - `GET /api/v1/status`: Public health check with service and DB status.
  - `GET /api/v1/search`: Unsupported operation handler adhering to safety limits.
  - `GET /docs`: Interactive public API documentation page.
- **Admin Dashboard**:
  - Complete web panel at `/admin/` with session security and CSRF protection.
  - API key manager with minute, day, and monthly quotas, rotation, and revocation.
  - Usage analytics, daily volume breakdowns, and paginated request logs.
  - Cache management (TTL, stale-serving policy, manual purge) and system diagnostics probe.
- **Testing Studio**:
  - Web UI at `/test` and standalone `test-client.html` for testing live quotes, batches, rate limits, and JSON inspection directly in the browser.
- **Cross-Platform One-Command Setup**:
  - Docker Compose stack (`php:8.2-apache` + `mariadb:10.11`) with auto-initialization and healthchecks.
  - `install.sh` for single-command installation on Linux and macOS.
  - `install.bat` and `install.ps1` for single-command installation on Windows.
  - `Makefile` for developer commands (`install`, `up`, `down`, `logs`, `test`).
- **CI / GitHub Actions**:
  - Automated CI test matrix testing PHP 8.1, 8.2, 8.3 and Docker Compose builds.
- **Version Control & Community Templates**:
  - Issue templates (bug report, feature request), pull request template, and contribution guidelines.

