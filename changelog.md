# Changelog - ML Gallery Migrator Pro

## [1.0.28] - 2026-04-11
- **TEST**: Controlled release to validate the native GitHub updater flow.
- **CORE**: No changes to migration or album logic.

## [1.0.27] - 2026-04-11
- **FEATURE**: Native update detection and installation via GitHub Releases.
- **CORE**: Implemented `MLGMP\Updater` class to bridge GitHub API and WordPress update transients.
- **SECURITY**: Forced TLS 1.2 for all update check requests to GitHub.
- **DOCS**: Added update-related headers to the main plugin file.

## [1.0.26] - 2026-04-11
- **RELEASE**: Final preparation for public repository.
- **SECURITY**: Hardened capability checks and nonce verification across all endpoints.
- **HYGIENE**: Implementation of `uninstall.php` for complete data cleanup on deletion.
- **UI**: Final visual parity refinements and responsive layout hardening.
- **DOCS**: Deeply improved `readme.txt` for WordPress.org compliance.

## [1.0.25] - 2026-04-11
- **FIX (Responsive Safety)**: Refinements for narrow admin viewports.
- **FIX (Layout Resilience)**: Handled long translated labels in English and Spanish.
- **UI**: Graceful stacking for operational panels and hero area.
- **i18n**: Completed missing translation strings in admin diagnostics.

## [1.0.24] - 2026-04-11
- **i18n**: Implemented native WordPress Internationalization.
- **Locales**: Added support for Brazilian Portuguese (pt_BR), English (en_US), and Spanish (es_ES).
- **Core**: Automated locale loading from `/languages` directory.

## [1.0.23] - 2026-04-11
- **UI**: Fixed column height parity for operational panels (Controls and Progress).

## [1.0.22] - 2026-04-11
- **UI**: Migrated operational panel to Full-Width layout for better visual rhythm.

## [1.0.19] - 2026-04-11
- **FIX (Album Parity)**: Implemented Two-Pass album migration for high-fidelity mapping.
- **FIX (Shortcodes)**: Correct conversion of encoded quotes (e.g., &quot;) in Nicepage/Gutenberg blocks.
