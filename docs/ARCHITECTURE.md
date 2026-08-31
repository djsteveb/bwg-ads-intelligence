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

**Historical note:** `wp_bwg_ai_sessions.entityiq_job_id` and the `/entityiq-webhook` REST route (removed in M11) were specific to the abandoned async design and are not used by anything currently in the plugin.

---

## 2. Screenshot Storage

**Decision:** Local disk on the EntityIQ server. WP admin has a storage dashboard.

**EntityIQ side:**
- Screenshots saved to: `{ENTITYIQ_DATA_DIR}/bwg-screenshots/{YYYY-MM-DD}/{session_id}/`
- Env var: `BWG_SCREENSHOT_DIR=/var/data/bwg-screenshots`
- Each screenshot record: filename, file size (bytes), session_id, platform, ad_id, captured_at

**WordPress side:**
- `wp_bwg_ai_ads.screenshot_path` stores the relative path (e.g. `2024-01-15/sess_abc123/meta_ad_001.png`)
- EntityIQ exposes a signed URL endpoint for WP admin to display screenshots: `GET /ads/screenshot/:path?sig=...&expires=...`
- Admin dashboard storage panel (in M10) queries EntityIQ `GET /ads/storage/stats` which returns:
  - Total storage used (bytes)
  - Storage by week (array of `{ week_start, bytes, file_count }`)
- Export by date range: `GET /ads/storage/export?from=YYYY-MM-DD&to=YYYY-MM-DD` — returns zip of screenshots + CSV manifest
- Delete by date range: `DELETE /ads/storage?from=YYYY-MM-DD&to=YYYY-MM-DD` — deletes files and nulls `screenshot_path` in WP DB via webhook callback

**For the EntityIQ agent:** Add these storage routes to `entityiq-extension/routes/ads.js`:
- `GET /ads/storage/stats`
- `GET /ads/storage/export`
- `DELETE /ads/storage`

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
- Google Ads Transparency — M12, via a render-provider abstraction (not decided yet whether that's a direct API call or a hosted render service; EntityIQ is not assumed).
- Claude vision compliance on ad creative — M13.
- Screenshot capture for platforms that don't host their own ad snapshot (Meta doesn't need this — see §1) — evaluated per-platform in M12+ as needed, not a blanket EntityIQ dependency.

---

## Storage Admin Dashboard — Detailed Spec

The admin storage panel (part of M10) lives at **WP Admin → Ads Intelligence → Storage**.

| Feature | Implementation |
|---|---|
| Total storage used | EntityIQ `GET /ads/storage/stats` → display formatted bytes |
| Storage by week | Bar chart or table of `{ week_start, bytes, file_count }` |
| Export date range | Date pickers → `GET /ads/storage/export?from=&to=` → download zip |
| Delete date range | Date pickers + confirmation modal → `DELETE /ads/storage?from=&to=` → EntityIQ deletes files, webhooks WP to null screenshot_path values |
| Storage warning threshold | Admin setting: alert if total > N GB (default 10 GB) |

EntityIQ must implement all four storage routes (stats, export, delete, webhook callback on delete completion).
