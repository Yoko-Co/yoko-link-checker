# Yoko Link Checker

A performant, extensible broken link checker for WordPress. Scans content for links, checks their validity, and reports issues.

**Version:** 1.1.1 | **Requirements:** WordPress 6.0+ | PHP 8.0+

## Description

Yoko Link Checker helps you identify and fix broken links across your WordPress website. It scans your posts, pages, and custom post types for links, validates them, and provides detailed reports to help you maintain a healthy site.

**Designed for managed WordPress hosting** — uses WordPress-native functions for internal URL verification to avoid PHP worker exhaustion on thread-limited hosts like Kinsta, WP Engine, and Flywheel.

### Features

- **Comprehensive Scanning**: Scans posts, pages, and custom post types for links
- **Smart Internal URL Checking**: Verifies internal links using WordPress functions (no HTTP overhead)
- **HTTP Fallback**: Falls back to HTTP checks for custom routes and plugin pages
- **Batch Processing**: Handles large sites efficiently with AJAX-driven batch processing
- **Status Classification**: Categorizes link issues (broken, redirect, warning, timeout, blocked)
- **Admin Dashboard**: Clean interface with real-time progress and stats
- **Link Reports**: View all links with status filtering, search, and CSV export
- **Intelligent Classification**: Handles quirky sites (LinkedIn 999, Facebook 403, etc.)
- **Extensible Architecture**: Filters and hooks for customization

## Requirements

- WordPress 6.0 or higher
- PHP 8.0 or higher

## Installation

### From GitHub

1. Download the latest release from the [Releases](https://github.com/Yoko-Co/yoko-link-checker/releases) page
2. Upload the `yoko-link-checker` folder to the `/wp-content/plugins/` directory
3. Activate the plugin through the 'Plugins' menu in WordPress

### Manual Installation

1. Clone this repository:
   ```bash
   git clone https://github.com/Yoko-Co/yoko-link-checker.git
   ```
2. Copy the folder to your WordPress plugins directory
3. Activate the plugin in WordPress admin

## Usage

1. Navigate to **Tools → Yoko Link Checker** in the WordPress admin
2. Click **Start Scan** to begin scanning your content
3. Watch real-time progress as links are discovered and checked
4. Open the **Reports** tab to view detailed results
5. Filter by status (Broken, Warning, Redirect, etc.)
6. Click source links to edit pages with broken links
7. Export results to CSV for sharing or tracking

## Link Statuses

| Status | Description |
|--------|-------------|
| **Valid** | Link works (200 response) |
| **Broken** | Link returns 404 or error |
| **Warning** | Needs manual review (blocked by site, unusual response) |
| **Redirect** | Link redirects to another URL |
| **Blocked** | Access denied (401/403) |
| **Timeout** | Request timed out |

## Configuration

The plugin provides several configuration options:

- **Post Types**: Select which post types to scan
- **Check Timeout**: Set timeout for HTTP requests
- **Batch Size**: Configure posts/URLs processed per batch

### Debug Logging

Enable debug logging in `wp-config.php`:

```php
define( 'YOKO_LC_DEBUG', true );
```

Logs are written to `wp-content/debug.log`.

## Architecture

The plugin follows a clean, modular architecture:

```
src/
├── Admin/           # Admin UI (Dashboard, Reports, AJAX handlers)
├── Checker/         # Link validation (HTTP client, status classifier)
├── Extractor/       # Content extraction (HTML parsing, link discovery)
├── Model/           # Data models (Url, Link, Scan)
├── Repository/      # Data persistence (URL deduplication, queries)
├── Scanner/         # Scan orchestration (batch processing, state)
└── Util/            # Utilities (logging, URL normalization)
```

### Key Design Decisions

1. **Internal URLs use WordPress functions** — `url_to_postid()`, taxonomy lookups, attachment checks — avoiding self-referential HTTP requests that can deadlock PHP workers.

2. **HTTP fallback for unverified internal URLs** — Custom routes and plugin pages that WordPress can't resolve are checked via HTTP with a short timeout.

3. **URL deduplication via SHA-256 hashing** — Same URL appearing on 100 pages = 1 HTTP check.

4. **AJAX-driven batch processing** — Each batch is a separate request, preventing timeout issues and enabling real-time progress.

## Development

### Project Structure

- `yoko-link-checker.php` - Main plugin file
- `src/` - PHP source code
- `assets/` - CSS and JavaScript files
- `templates/` - Admin page templates
- `docs/` - Documentation
- `uninstall.php` - Cleanup on uninstall

### Coding Standards

This plugin follows WordPress Coding Standards. To check your code:

```bash
composer install
./vendor/bin/phpcs
./vendor/bin/phpcbf  # Auto-fix issues
```

## Filters & Hooks

```php
// Customize post types to scan
add_filter( 'yoko_lc_scannable_post_types', fn($types) => ['post', 'page', 'product'] );

// Skip specific URLs
add_filter( 'yoko_lc_skip_url_check', fn($skip, $url) => str_contains($url, 'localhost'), 10, 2 );

// Adjust batch sizes
add_filter( 'yoko_lc_discovery_batch_size', fn() => 100 );
add_filter( 'yoko_lc_checking_batch_size', fn() => 10 );

// Customize internal HTTP check
add_filter( 'yoko_lc_internal_http_args', fn($args, $url) => $args, 10, 2 );

// Hook into scan lifecycle
add_action( 'yoko_lc_scan_completed', fn($scan) => wp_mail(...) );
```

## Changelog

### 1.1.1
Resolves 17 findings from Round 4 code review.

**Critical (P1):**
- Fixed polling race condition causing permanent scan status stalls

**Important (P2):**
- Fixed `check_timeout` default mismatch (8 → 30)
- Fixed `register_menu` capability check timing
- Moved export handler to page-specific hook
- Fixed cron reschedule timing
- Added media extension guard for attachments
- Optimized N+1 queries with batch lookups
- Validated `strtotime()` return values

**Code Quality (P3):**
- Removed dead modal code and 8 unused repository methods (~166 LOC)
- Removed `next_check` column, bumped schema to 1.2.0
- Added defensive guards and Yoda conditions

### 1.1.0
Addresses all findings from comprehensive multi-agent code review.

**Critical Fixes (P1):**
- Fixed ignore/unignore targeting wrong table
- Removed batch processing from AJAX status poll
- Added stale scan recovery (30-min timeout)
- Fixed schema version mismatch
- Fixed ghost property `redirect_url` → `final_url`

**Important Improvements (P2):**
- Added parallel HTTP requests via `Requests::request_multiple()`
- Replaced 16+ COUNT queries with single GROUP BY on dashboard
- Added SSRF protection (private IP blocking)
- Added concurrent batch execution locks
- Fixed TOCTOU race condition in `find_or_create()`
- Streaming CSV export with constant memory
- Primed post caches to eliminate N+1 queries

**Code Quality (P3):**
- Removed 205 LOC dead code (UrlValidator)
- Consolidated duplicate logic
- Added composite database index
- Full PSR-2 compliance

### 1.0.8
- **Fixed**: Internal 404s now correctly flagged as broken instead of warning
- **Added**: HTTP fallback for internal URLs that WordPress functions can't verify
- **Added**: `yoko_lc_internal_http_args` filter for customizing internal HTTP checks

### 1.0.7
- Renamed "Broken Links" submenu to "Reports"
- Renamed "Anchor Text" column to "Link Text"
- CSV export now includes Source URL with permalinks
- Improved CSV column names and ordering

### 1.0.6
- Fixed TypeError in bulk actions (`LinkRepository::update()` expected Link object)
- Added `update_by_id()` method to LinkRepository

### 1.0.5
- Fixed CSV export outputting HTML instead of CSV data
- Fixed bulk actions causing white screen
- Added status tooltips with descriptions

### 1.0.4
- Fixed "Export to CSV" not working
- Fixed "Clear All Data" button not working
- Added accent border styling to dashboard
- Added info notice about staying on page during scan

### 1.0.3
- Internal URLs now checked using WordPress functions
- Detects links to deleted, draft, or unpublished posts
- Unverifiable internal URLs marked as warnings

### 1.0.2
- Fixed undefined property warning for `redirect_url`
- Fixed upstream timeouts by skipping internal URL checks
- Added `YOKO_LC_DEBUG` constant to control logging

### 1.0.1
- Fixed AJAX action name mismatch preventing scanner from starting
- Fixed incorrect namespace reference causing status check errors

### 1.0.0
- Initial release

## Documentation

- [Quick Guide](docs/QUICK_GUIDE.md) — Team-friendly usage guide
- [Technical Specification](docs/TECHNICAL_SPECIFICATION.md) — Deep dive for developers

## License

This plugin is licensed under the GPL v2 or later.

```
This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.
```

## Credits

Developed by [Yoko Co.](https://yokoco.com)
