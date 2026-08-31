# BWG Ads Intelligence — Architectural Decisions

Decisions locked before build. Change here and in CLAUDE.md if anything shifts mid-build.

---

## 1. Meta Ad Library Integration (supersedes the old EntityIQ webhook design)

**Status (2026-08-31):** The EntityIQ Node.js extension described below was never built and has no plan to be — EntityIQ's actual roadmap is unrelated (Local SEO/schema tooling). The original design in this section (shared-secret HMAC webhook, async job queue, `entityiq-extension/routes/ads.js`, `lib/meta-ad-library.js`) never had anything on the other end and the MVP's ad surface step was non-functional as a result. **M11 replaced it**: `ads-intel/class-bwg-ai-ad-surface.php` now calls `ads-intel/class-bwg-ai-meta-ad-library.php`, which hits the Meta Graph API `ads_archive` endpoint directly from WordPress. No webhook, no HMAC signature, no remote job queue, no headless browser.

**Decision:** Direct server-to-server call from WordPress to `https://graph.facebook.com/{version}/ads_archive`, using a long-lived `ads_read` token.

**WordPress side:**
- Admin setting: `bwg_ai_meta_ad_library_token` (WP option, AES-256-CBC encrypted at rest — see `bwg_ai_get_meta_ad_library_token()` in `class-bwg-ai-security.php`)
- `BWG_AI_Meta_Ad_Library::search( $hints )` builds the query (`search_page_ids` when a Facebook page/ID is known, else `search_terms` from the discovered business name), calls the endpoint with `wp_remote_get()`, and normalizes each row into this plugin's ad shape.

**Flow (orchestration, in `class-bwg-ai-ad-surface.php`):**
1. `POST /confirm-discovery` (or `/add-accounts`) fires `do_action( 'bwg_ai_queue_ad_surface', $session_id, $hints )`.
2. `queue_job()` schedules `wp_schedule_single_event( time() + 2, 'bwg_ai_run_ad_surface', [ $session_id, $hints ] )` so the outbound Graph API call happens on WP-Cron, not inside the REST request.
3. `run()` (hooked to `bwg_ai_run_ad_surface`) calls `BWG_AI_Meta_Ad_Library::search()` and saves results via `save_ads()` — same compliance-analysis and drip-email side effects the old webhook handler had.

**Manual-entry fallback:** When `bwg_ai_meta_ad_library_token` is empty, `BWG_AI_Meta_Ad_Library::is_configured()` returns false and `run()` logs and returns without attempting a lookup (there is no scraper fallback — no headless browser in this pipeline). The front-end (`ai-form.js`) detects this via `meta_configured` on `GET /ad-surface-status/{id}` and switches to a manual-entry form where the user pastes Ad Library snapshot URLs (+ optional ad copy) to `POST /manual-ads`, handled by `BWG_AI_Ad_Surface::save_manual_ads()`.

**Ad snapshots:** Meta hosts its own rendered snapshot of every ad at `ad_snapshot_url` (returned by `ads_archive` and stored in `wp_bwg_ai_ads.ad_snapshot_url`). The gallery UI links out to it directly instead of capturing a screenshot — there's no Playwright/EntityIQ screenshot step in this pipeline.

**Historical note:** `wp_bwg_ai_sessions.entityiq_job_id`, the `/entityiq-webhook` REST route, and the `bwg_ai_entityiq_url`/`bwg_ai_entityiq_secret` options (all removed in M11/M12) were specific to the abandoned async design and are not used by anything currently in the plugin. The `entityiq_job_id` DB column itself is left in place rather than a destructive migration — safe to drop in a future DB version bump.

---

## 2. Screenshot Storage

**Decision (rewritten M12):** Local disk, on the WordPress server itself — there is no EntityIQ server to store anything on (see §1). WP admin has a Storage dashboard backed directly by the local filesystem and the `wp_bwg_ai_ads` table, no external service involved.

**Where files live:** `wp-content/uploads/bwg-ai-screenshots/{YYYY-MM-DD}/{session_id}/{platform}-{random}.{ext}` (via `wp_upload_dir()`, so it follows whatever the site's uploads base is, including any `WP_CONTENT_DIR`/multisite overrides). The top-level `bwg-ai-screenshots/` directory gets an `.htaccess` (`Deny from all`) and `index.php` the first time anything is saved there, so files are never reachable by a direct URL guess — see `BWG_AI_Screenshot_Store::base_dir()`.

**What actually writes screenshots:** only Google Ads Transparency captures (§5) — Meta links to its own hosted `ad_snapshot_url` and never touches this pipeline (§1). If a future milestone (LinkedIn/TikTok, M15) needs a captured screenshot too, it goes through the same `BWG_AI_Screenshot_Store::save()` / `BWG_AI_Render_Provider` pair rather than inventing a new storage path.

**Serving:** never a direct file URL. `wp_bwg_ai_ads.screenshot_path` stores the relative path; `GET /wp-json/bwg/v1/ai/screenshot/{ad_id}` streams the file after checking a short-lived HMAC-signed `sig`+`expires` query pair (`bwg_ai_screenshot_url()` / `bwg_ai_sign_screenshot_url()` in `class-bwg-ai-security.php`, 2-hour TTL). `GET /ads/{id}` includes a freshly-signed `screenshot_url` on every response rather than the raw path, so `<img>` tags (which can't send custom auth headers) just work.

**Byte tracking:** `wp_bwg_ai_ads.screenshot_bytes` is set at save time (from `file_put_contents()`'s return value) — stats are a `SUM()` over that column, not a filesystem walk. `BWG_AI_Screenshot_Store::stats()` returns total bytes + a 7-day daily breakdown, rendered on **WP Admin → Ads Intelligence → Storage** as a usage bar (against the existing `bwg_ai_storage_warning_gb` threshold) and a bar chart.

**Backup:** the Storage page's "Backup / Export Screenshots" card builds a ZIP (`BWG_AI_Screenshot_Store::export_zip()`, requires the `ZipArchive` PHP extension) of every screenshot in a date range plus a CSV manifest (`session_id, platform, ad_id, path, bytes, created_at`), streamed as a download via an `admin-post.php` handler (`BWG_AI_Admin::handle_storage_export()`) and deleted from disk immediately after.

**Delete / retention:**
- Manual: the Storage page has two delete actions — by explicit date range, and "older than N days" — both backed by `BWG_AI_Screenshot_Store::prune_range()` / `prune_older_than()`, which delete the file and null `screenshot_path`/`screenshot_bytes` in the same pass.
- Automatic: `bwg_ai_screenshot_retention_days` (Settings → Storage; default `0` = keep indefinitely). When set, `BWG_AI_Admin::daily_maintenance()` calls `prune_older_than()` on every run.
- Every delete (manual or automatic) is written to `wp_bwg_ai_audit_log`.
- The storage-warning email (`bwg_ai_storage_warning_gb`) is unchanged in spirit from the MVP design — it fires from the same daily maintenance cron once total bytes cross the threshold — just computed locally now instead of via an EntityIQ API call.

---

## 3. Dependency on bwg-speed-sitescout

**Decision:** Soft dependency. Always bundle fallback classes. Use `class_exists()` to prefer the sibling plugin's versions when active.

**Pattern in every file that uses shared classes:**
```php
if ( ! class_exists( 'BWG_CPA_Discovery' ) ) {
    require_once plugin_dir_path( __FILE__ ) . '../includes/fallbacks/class-bwg-cpa-discovery-stub.php';
}
```

**Classes to stub in `includes/fallbacks/`:**

| Stub file | Real class | Used for |
|---|---|---|
| `class-bwg-cpa-discovery-stub.php` | `BWG_CPA_Discovery` | Phase 1 GBP, social, pixel base |
| `class-bwg-sa-scraper-stub.php` | `BWG_SA_Scraper` | HTTP fetcher for landing page spider |
| `class-bwg-sa-module-pagespeed-stub.php` | `BWG_SA_Module_PageSpeed` | Core Web Vitals (Phase 6) |
| `class-bwg-compliance-stub.php` | `BWG_Compliance` | HIPAA compliance base checks |
| `class-bwg-cpa-rate-limiter-stub.php` | `BWG_CPA_Rate_Limiter` | Rate limiting pattern |

Stubs implement the full method signature but use direct HTTP calls / simpler logic. They are not feature-equivalent — they exist so the plugin runs standalone without fatal errors.

---

## 4. Email Provider

**Decision:** wp_mail by default. Admin can switch to SendGrid or Postmark via settings UI.

**Admin setting:** `bwg_ai_email_provider` — options: `wp_mail` | `sendgrid` | `postmark`

**`class-bwg-ai-email.php` pattern:**
```php
private function send( $to, $subject, $html, $text = '' ) {
    $provider = get_option( 'bwg_ai_email_provider', 'wp_mail' );
    switch ( $provider ) {
        case 'sendgrid':
            return $this->send_via_sendgrid( $to, $subject, $html, $text );
        case 'postmark':
            return $this->send_via_postmark( $to, $subject, $html, $text );
        default:
            return $this->send_via_wp_mail( $to, $subject, $html );
    }
}
```

**Settings fields** (in admin-settings.php):
- Provider dropdown: wp_mail / SendGrid / Postmark
- SendGrid API key (shown when SendGrid selected)
- Postmark server token (shown when Postmark selected)
- From name + from email (used by all providers)
- Test email button (sends a test to admin email)

**Note:** Other plugins (e.g. WP Mail SMTP, FluentSMTP) may intercept wp_mail. That is intentional and fine — this plugin does not fight it. The SendGrid/Postmark options are for sites that need guaranteed deliverability without a separate SMTP plugin.

---

## 5. Meta Ad Library API Token

**Decision (updated M11):** Stored in WordPress, not EntityIQ — there is no EntityIQ side to this plugin's ad surface pipeline (see §1).

**WordPress admin setting:** `bwg_ai_meta_ad_library_token` (Settings → API Keys tab), encrypted at rest with the same AES-256-CBC scheme as the other secret options (`bwg_ai_encrypt_secret()` / `bwg_ai_decrypt_secret()` in `class-bwg-ai-security.php`).

**Requirements:**
1. The token comes from a Meta developer app with the `ads_read` permission approved for the Ad Library API.
2. It must be a long-lived token — WordPress does not refresh short-lived tokens automatically.
3. If the token is absent, `BWG_AI_Ad_Surface::run()` skips the automated lookup entirely and the front-end falls back to manual ad entry (see §1). There is no scraper fallback (no Playwright, no headless browser anywhere in this plugin).

**Not yet built (Phase 2, later milestones):**
- Claude vision compliance on ad creative — M13.

## 5b. Google Ads Transparency (M12)

**Decision:** No bulk data API equivalent to Meta's `ads_archive` exists for Google (the "Ads Transparency Insights API" referenced in the pre-M11 EntityIQ-era docs was never confirmed generally available). Rather than reintroduce an EntityIQ-shaped dependency (a job queue calling a headless browser this plugin would have to run itself), M12 captures the advertiser's Google Ads Transparency Center domain-search results page (`https://adstransparency.google.com/?region=anywhere&domain={domain}`) through a **render-provider abstraction** and stores it as a single screenshot-backed ad record.

**`class-bwg-ai-render-provider.php`:** a thin, vendor-agnostic client — any hosted screenshot API whose endpoint accepts `?url=&access_key=` and returns raw image bytes works (ScreenshotOne, ApiFlash, urlbox.io, etc. are all compatible without code changes). Configured via `bwg_ai_screenshot_api_url` + `bwg_ai_screenshot_api_key` (Settings → API Keys), the key encrypted at rest.

**`class-bwg-ai-google-transparency.php`:** builds the domain-search URL from the session's website hostname, calls the render provider, saves the returned image via `BWG_AI_Screenshot_Store::save()` (see §2), and returns one normalized ad record (`platform = 'google'`) whose `ad_copy` explicitly says it's a full-page capture standing in for the whole result set, not per-ad detail — this is coarser than Meta's per-ad records, and the report/compliance UI should not imply otherwise.

**Manual fallback:** identical pattern to Meta (§1) — when no screenshot API is configured, `BWG_AI_Google_Transparency::is_configured()` is false, the automated capture is skipped, and the front-end offers manual entry (paste a Transparency Center ad URL + optional copy) via the same `POST /manual-ads` endpoint, now parameterized by `platform`.

**Orchestration:** `BWG_AI_Ad_Surface::run()` calls Meta and Google independently — one being unconfigured never blocks the other. `GET /ad-surface-status/{id}` reports `meta_configured` and `google_configured` separately so the front-end can offer manual entry per-platform.

---

## Storage Admin Dashboard — Detailed Spec

The admin storage panel (part of M10, rewritten for local storage in M12) lives at **WP Admin → Ads Intelligence → Storage**. Full detail in §2 — summary:

| Feature | Implementation |
|---|---|
| Total storage used | `BWG_AI_Screenshot_Store::stats()` — `SUM(screenshot_bytes)` over `wp_bwg_ai_ads`, no filesystem walk |
| Storage by week | 7-day daily breakdown from the same query, rendered as a bar chart |
| Backup / export date range | Date pickers → `admin-post.php?action=bwg_ai_storage_export` → streams a ZIP (files + CSV manifest) |
| Delete by date range | Date pickers + confirmation modal → `BWG_AI_Screenshot_Store::prune_range()` |
| Delete older than N days | Quick action + confirmation modal → `BWG_AI_Screenshot_Store::prune_older_than()` |
| Auto-delete by retention | `bwg_ai_screenshot_retention_days` setting (0 = off) → applied every run of `bwg_ai_daily_maintenance` |
| Storage warning threshold | Admin setting `bwg_ai_storage_warning_gb` (default 10 GB): daily maintenance emails the admin once total bytes cross it |
