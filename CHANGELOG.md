# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.2.0] - 2026-08-12

Monthly maintenance: relocates the admin screen, reconciles the broken-link
counts that disagreed between screens, and hardens the link checker's outbound
requests against SSRF in light of the WordPress 7.0.1–7.0.4 releases.

### Changed
- **The admin UI now says "Yoko Link Checker" everywhere**, not "Link Checker" —
  the menu entry, all three page headings, the Settings permission notice and the
  outbound User-Agent. The short name left it unclear who owns the tool.
- **Admin screen moved from a top-level menu to Tools → Yoko Link Checker.** Dashboard,
  Reports and Settings are now tabs on a single screen rather than three menu
  entries. All internal links build their URLs through `AdminController::page_url()`,
  so the screen can be relocated again from one constant.
- **Every count now comes from one place.** New `LinkQuery`, `StatusCounts` and
  `LinkStats` replace four independent counters that used two different units and
  three different filter sets. The dashboard, the Reports tabs, the "N items"
  total and the CSV are all derived from the same queries.
- **Both units are now labelled everywhere.** Unique URLs (the problem count — a
  URL is fixed once) and link occurrences (the work count — one per place to
  edit) are stated side by side, e.g. "12 broken URLs across 34 links". These
  numbers were always different; the UI previously showed one and implied the other.
- Ignoring is per-URL in the data model, and the UI now says so: the row action
  reads "Ignore this URL" and there is an explicit Ignored view.
- Blocked, timeout and error links appear in a **Needs Review** dashboard card
  rather than being counted in the total and rendered nowhere.
- CSV export honours the filters on screen, is named after its contents, and its
  first column is "URL" rather than "Broken URL" (it never contained only broken URLs).
- `Requires at least` documented alongside a new `Tested up to: 7.0` header.

### Fixed
- **"Last scan" reported the wrong time on every site not set to UTC.** Datetimes
  are stored with `current_time( 'mysql' )` (site-local) but were read back with a
  bare `strtotime()`, which parses as UTC — adding the site's UTC offset to every
  age. On a UTC-4 site a scan that had just finished read "4 hours ago", and one
  from yesterday read 28 hours. Scan *duration* was unaffected, because
  subtracting two equally wrong timestamps cancels the error, which is why it
  looked correct next to a wrong figure. All reads now go through
  `Util\StoredTime`; the same bug affected "Last Checked" in the Reports table and
  on the dashboard.
- **A just-started scan could be killed as stale.** The same misreading was applied
  to `started_at` in the 30-minute staleness check, so on a UTC-4 site a healthy
  scan looked 4 hours idle whenever the last-activity option had not been written yet.
- **Search no longer breaks pagination.** The count query ignored the search term
  while the row query applied it, so searching advertised pages that rendered empty.
- **The 'error' status is reachable.** It was counted in the dashboard total but
  missing from the Reports filters; `?status=error` silently fell back to Broken.
- **Dashboard cards now sum to the total.** Blocked, timeout and error were in the
  total but had no card.
- **Ignoring a link no longer desynchronises the screens.** The dashboard ignored
  the ignored flag entirely, so its numbers never moved when Reports' did.
- Scan progress can reach 100%: the denominator counted ignored URLs the checker
  never fetches, and the completed-state branch was unreachable because the phase
  check preceded it.
- CSV export populated Source URL and Source Title only for the `post` type,
  leaving them blank for pages and every custom post type.
- "Recent Broken Links" excluded ignored URLs, picks its source post
  deterministically, and reports how many places each URL appears.
- Link rows are pruned when their source post is permanently deleted
  (`before_delete_post`) and when links are removed from a post's content
  (during rescan), so occurrence counts stop drifting above URL counts.
- **Scheduled events are now always cleared on uninstall**, even when scan data is
  kept. The routine previously returned early in that case, leaving cron events
  firing forever with no handler once the plugin was deleted.
- Uninstall removes capabilities only from `administrator`, the role `Activator`
  grants them to; it also tried to remove them from `editor`, which never had them.

### Security
- **Redirects are validated at every hop.** The SSRF check previously ran once
  against the original URL while the transport followed up to three redirects
  unchecked, so an external URL redirecting to `169.254.169.254` or `127.0.0.1`
  was fetched. Redirects are now followed manually with the full check on each hop.
  This is the same class of issue WordPress fixed in core's URL validation in 7.0.3;
  the plugin did not inherit that fix because it bypassed `wp_http_validate_url()`.
- `reject_unsafe_urls` is now set, so core's hardened validator applies as a second layer.
- **DNS resolution failure now blocks instead of allowing.** An unresolvable host
  was treated as safe.
- **Bracketed IPv6 literals are handled.** `http://[::1]/` bypassed the check
  entirely: it failed IP validation with brackets, passed through `gethostbyname()`
  unchanged, and was read as a resolution failure.
- Schemes are allow-listed to http and https, closing `file://`, `gopher://` and `dict://`.
- The DNS cache is time-limited rather than living for the whole process, narrowing
  the DNS-rebinding window.
- The parallel checking path no longer follows redirects without validation.
- The internal-URL fallback no longer uses a second, weaker HTTP path with SSL
  verification disabled; it goes through `HttpClient` with a scoped exemption for
  the site's own host.
- The User-Agent advertised `https://example.com`; it now identifies the real site.
- **Every AJAX endpoint has its own nonce.** All ten shared a single
  `yoko_lc_admin` nonce, so a token leaked from any plugin page — via a referrer,
  or anything able to read the localized script object — authorised `clear_data`,
  which truncates all three tables, exactly as readily as a status poll.
- **`clear_data` now requires `manage_options`** rather than the scan-management
  capability. Being able to run a scan is not the same as being able to destroy
  its history.
- **Status polling can no longer be used to spawn cron on demand.** The endpoint
  needs only view capability but called `spawn_cron()` on every poll; it is now
  rate-limited to once every 30 seconds.
- **Settings saving uses Post/Redirect/Get.** The save ran during page render, so
  the POST stayed in browser history and a refresh silently re-submitted it —
  including the cron reschedule.
- **Saved post types are validated against registered post types**, not just
  sanitized, so arbitrary slugs can no longer be stored.

### Added
- **WP-CLI commands**: `wp yoko-lc counts`, `wp yoko-lc verify`, `wp yoko-lc prune`
  and `wp yoko-lc export`. `verify` asserts the counts are internally consistent and
  exits non-zero when they are not — it is the acceptance test for this release.
- **An uninstall data-retention setting.** `uninstall.php` has always read
  `yoko_lc_remove_data_on_uninstall` and defaulted to deleting everything, but
  nothing ever wrote it — there was no way to opt out. The Settings tab now has
  the checkbox.
- Per-status counts on the Reports filter tabs, and a rows-per-page screen option.

### Performance
- **Redirects are followed in parallel rounds, not one URL at a time.** The SSRF
  fix initially pulled every redirecting URL out of its batch and re-checked it
  sequentially. On sites where http→https or trailing-slash canonicalisation is
  common — which is most large sites — that would have serialised a large share of
  the checking phase, the exact stall this plugin exists to avoid. Each round now
  validates and follows every redirect target together, so a batch costs one round
  per hop depth rather than one request per redirecting link. Several links
  redirecting to the same canonical URL fetch that URL once.
- **The dashboard's counts are cached, with explicit invalidation.** Reconciling
  the counts meant joining the links table where the old query was an index-only
  scan of the smaller urls table — cheap on a small site, not on one with millions
  of link rows. The unfiltered dashboard figures are cached and flushed by every
  seam that changes them (scan completion, ignore/un-ignore, prune, clear data),
  so nothing goes stale waiting for a TTL. Filtered and searched counts on the
  Reports tab are always live.

### Removed
- **The `yoko_lc_internal_http_args` filter.** Internal URL checks ran through a
  separate HTTP path with their own arguments and SSL verification disabled; they
  now go through the same client as every other request, so there is one SSRF gate
  rather than two. Use `yoko_lc_http_request_args` instead, plus
  `yoko_lc_allow_private_urls` where a private address must be reachable.

### Upgrade notes
- **Anyone with a plugin page open across the upgrade will see one "Security check
  failed"** on their next action, because the page holds nonces in the old format.
  Reloading fixes it. Unavoidable when nonce actions change.
- **The localized `ylcAdmin.nonce` value is now `ylcAdmin.nonces`, keyed by action.**
  Nothing in the plugin relies on the old key, but bespoke integrations would.
- **Bookmarks to the old admin URLs stop working.** The screen moved from
  `admin.php?page=yoko-link-checker*` to `tools.php?page=yoko-link-checker&tab=…`.
  The menu slug itself is unchanged, so stored data and capabilities are unaffected.
- **Running a scan deletes stale and orphaned link rows** (and `wp yoko-lc prune`
  does so explicitly). This is the intended fix for counts drifting upward, but it
  is not undone by reverting the code — snapshot the database first if that matters.

## [1.1.1] - 2026-03-04

Resolves 17 code review findings from Round 4 codebase review (Wave 4).

### Fixed
- **P1**: Polling race condition — `scheduleNextPoll` now fires in AJAX complete callback, preventing permanent scan status stalls
- **P2**: `check_timeout` default corrected from 8 to 30, matching Activator/AdminController
- **P2**: `register_menu` capability uses fixed custom strings instead of `current_user_can()` at registration time
- **P2**: Export handler moved from `admin_init` to `load-{$hook_suffix}` for page-specific execution
- **P2**: Cron reschedule uses `time()` instead of `time() + interval`
- **P2**: `strtotime()` return values validated in DashboardPage/LinksListTable
- **P2**: Media extension guard added for `attachment_url_to_postid()`
- **P2**: N+1 queries optimized via batch URL hash lookups (`find_by_hashes()`) and link existence checks (`find_existing_batch()`)
- **P3**: `ylcAdmin` undefined guard added, Yoda conditions enforced
- **P3**: Null check on `get_edit_post_link()`, `sanitize_csv_value()` return type fixed
- **P3**: Duplicate `do_action` calls removed in ResultsPage ignore/unignore

### Changed
- Inline settings script moved to `admin.js` `bindEvents()`
- Cron schedules filter registered before activation with explicit 0 args for auto_scan action
- `url_id` now passed in ignore/unignore hooks
- Removed redundant `count_posts()` per batch; uses scan record total instead

### Removed
- Dead modal code (HTML, JS `closeModal`/Escape handlers, CSS rules)
- 8 dead repository methods (~166 LOC) from LinkRepository and ScanRepository
- Unused `PROBLEM_STATUSES` constant
- `next_check` column and index from schema

### Database
- Schema bumped to 1.2.0

## [1.1.0] - 2026-03-03

Addresses all findings from comprehensive multi-agent code review (5 P1 Critical, 16 P2 Important, 13 P3 Nice-to-Have).

### Added
- Parallel HTTP requests for external URL checking via `Requests::request_multiple()`
- SSRF protection blocking private/internal IP ranges (127.x, 10.x, 192.168.x, etc.)
- Concurrent batch execution protection via transient-based locks
- Stale scan recovery with 30-minute timeout auto-failing stuck scans
- Database schema version tracking for automatic upgrades
- Composite index `(status, is_ignored, id)` for improved query performance
- Return value checks on all `$wpdb->insert()` calls
- `manage_options` fallback to capability checks for settings
- Polling timer cleanup on page unload
- AJAX response validation in JavaScript
- `set_service()` method for test dependency injection
- Localized all hard-coded English strings in JavaScript

### Changed
- Dashboard now uses single `GROUP BY` query instead of 16+ `COUNT` queries
- CSV export streams with chunked queries for constant memory usage
- Cron schedules filter registered persistently in `boot()` method
- Exception messages sanitized in AJAX responses (no sensitive data leakage)
- Consolidated 30+ inline debug blocks into Logger calls
- Moved raw SQL from DashboardPage to LinkRepository
- StatusClassifier injected into BatchProcessor (proper DI)
- TRUNCATE operations wrapped in transaction with error handling
- Improved PHP coding standards compliance (PSR-2)
- Enhanced error logging with structured context arrays

### Fixed
- **P1**: Ignore/unignore now targets correct table (`urls.is_ignored`, not `links`)
- **P1**: Removed batch processing from AJAX status poll (delegated to WP-Cron)
- **P1**: Schema version mismatch between Plugin.php and Activator.php
- **P1**: Ghost property `$url->redirect_url` → `$url->final_url`
- **P2**: TOCTOU race condition in `find_or_create()` URL logic
- **P2**: Associated links now cleaned up on URL deletion
- **P2**: Stale options list in `uninstall.php` updated
- **P2**: N+1 query pattern in discovery phase (post cache priming)
- **P2**: N+1 queries in LinksListTable (batch post cache priming)
- Closing brace positioning per PSR-2
- `count()` usage inside loop conditions
- Equals sign alignment in variable assignments
- Associative array formatting (multi-line requirement)

### Removed
- Unused `UrlValidator` class (205 LOC dead code)
- Unused `ExtractorRegistry`/`Interface` methods
- Duplicate URL-skip logic (consolidated from 3 locations to 1)
- Count method aliases from `UrlRepository`
- Speculative code for unbuilt features
- Dead grouped `yoko_lc_settings` option

## [1.0.8] - 2026-03-03

### Fixed
- Internal URLs that can't be verified via WordPress functions now fall back to HTTP check
- Internal 404 pages are now correctly flagged as "broken" instead of "warning"
- Custom routes, plugin pages, and archive URLs are now properly verified

### Added
- `check_internal_url_via_http()` method for fallback HTTP verification
- `yoko_lc_internal_http_args` filter for customizing internal HTTP request arguments
- Short timeout (3 seconds) for internal HTTP checks to prevent delays

## [1.0.7] - 2026-03-03

### Changed
- Renamed "Broken Links" submenu to "Reports" for broader reporting functionality
- Page title changed from "Broken Links" to "Link Reports"
- Renamed "Anchor Text" column to "Link Text" for clarity
- CSV export now includes "Source URL" column with actual permalinks
- Improved CSV column names: "Broken URL", "Source URL", "Source Title", "Source Type", "Error Details"
- CSV columns reordered for better remediation workflow

### Removed
- Removed bulk actions (Ignore, Un-ignore, Recheck) to simplify MVP interface
- Removed checkbox column from results table
- Removed bulk action processing code

## [1.0.6] - 2026-03-03

### Fixed
- Fixed TypeError in bulk actions: `LinkRepository::update()` expected Link object, received integer
- Added `update_by_id()` method to LinkRepository for updating by ID with data array

## [1.0.5] - 2026-03-03

### Fixed
- Fixed CSV export outputting HTML instead of CSV data (headers sent before output)
- Fixed bulk actions (Ignore, Un-ignore, Recheck) causing white screen or not working
- Changed bulk actions form method from GET to POST
- Added redirect after bulk action processing to prevent re-processing on refresh
- Added `manage_options` capability fallback for bulk actions

### Added
- Status badges now have tooltips with descriptions explaining what each status means
- Warning, Blocked, Timeout, Error, and Redirect statuses show detailed explanations
- Error messages from the server are now displayed in status tooltips

## [1.0.4] - 2026-03-03

### Fixed
- Fixed "Export to CSV" not working on results page
- Fixed "Clear All Data" button not working

### Added
- CSV export now includes all link data with proper UTF-8 encoding
- Info notice reminding users to keep the page open during scans

### Changed
- Improved card styling with accent border and enhanced shadow
- Stat cards now have subtle hover animation
- Updated border radius and shadow for modern appearance

## [1.0.3] - 2026-03-03

### Fixed
- Internal URLs are now properly checked for broken links instead of being skipped
- Uses WordPress functions (`url_to_postid`, `get_post`, etc.) to validate internal links
- Detects links to deleted, draft, or unpublished posts

### Changed
- Internal URL validation no longer requires HTTP requests (prevents PHP worker deadlocks)
- Unverifiable internal URLs (custom routes, archives) marked as warnings for manual review

## [1.0.2] - 2026-03-03

### Fixed
- Fixed undefined property `redirect_url` warning (should be `final_url`)
- Fixed upstream timeouts when checking internal URLs by skipping self-referential requests

### Changed
- Internal URLs are now automatically marked as valid without HTTP requests
- This prevents PHP worker deadlocks on limited-resource hosts

## [1.0.1] - 2026-03-03

### Fixed
- Fixed AJAX action name mismatch preventing scanner from starting (`ylc_` → `yoko_lc_` prefix)
- Fixed incorrect namespace reference (`Jeremie\YokoLinkChecker` → `YokoLinkChecker`)

### Changed
- Added `YOKO_LC_DEBUG` constant to control plugin logging (disabled by default)
- All debug logging now requires explicit opt-in via `wp-config.php`

## [1.0.0] - 2026-03-03

### Added
- Initial release of Yoko Link Checker
- Content scanning for posts, pages, and custom post types
- Link validation with HTTP checking
- Comprehensive status classification (broken, redirect, timeout, etc.)
- Admin dashboard with scan management
- Results page with filtering and sorting
- Auto-scan scheduling via WP-Cron
- Batch processing for large sites
- Extensible extractor architecture
- Clean uninstall with data removal options
- WordPress Coding Standards compliance
- PHP 8.0+ support
- WordPress 6.0+ support

[Unreleased]: https://github.com/Yoko-Co/yoko-link-checker/compare/v1.0.7...HEAD
[1.0.7]: https://github.com/Yoko-Co/yoko-link-checker/compare/v1.0.6...v1.0.7
[1.0.6]: https://github.com/Yoko-Co/yoko-link-checker/compare/v1.0.5...v1.0.6
[1.0.5]: https://github.com/Yoko-Co/yoko-link-checker/compare/v1.0.4...v1.0.5
[1.0.4]: https://github.com/Yoko-Co/yoko-link-checker/compare/v1.0.3...v1.0.4
[1.0.3]: https://github.com/Yoko-Co/yoko-link-checker/compare/v1.0.2...v1.0.3
[1.0.2]: https://github.com/Yoko-Co/yoko-link-checker/compare/v1.0.1...v1.0.2
[1.0.1]: https://github.com/Yoko-Co/yoko-link-checker/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/Yoko-Co/yoko-link-checker/releases/tag/v1.0.0
