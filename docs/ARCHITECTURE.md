# BWG Ads Intelligence — Architectural Decisions

Decisions locked before build. Change here and in CLAUDE.md if anything shifts mid-build.

---

## 1. EntityIQ Webhook Authentication

**Decision:** Shared secret, stored in two places and passed on every callback.

**WordPress side:**
- Admin setting: `bwg_ai_entityiq_secret` (WP option, encrypted at rest via `wp_encrypt_data` or stored as a salted hash)
- Also store: `bwg_ai_entityiq_url` — base URL of the EntityIQ service

**EntityIQ side (add to `.env`):**
```
BWG_WEBHOOK_SECRET=<generate with openssl rand -hex 32>
BWG_WP_WEBHOOK_URL=https://your-wp-site.com/wp-json/bwg/v1/ai/entityiq-webhook
```

**Flow:**
- EntityIQ signs every callback with `X-BWG-Signature: sha256=HMAC(secret, body)`
- WP verifies signature before processing any webhook payload
- Replay protection: include `timestamp` in payload, reject if >5 minutes old

**For the EntityIQ agent:** See `entityiq-extension/routes/ads.js` — add the signature header to every `axios.post()` call back to WordPress. The secret lives in `process.env.BWG_WEBHOOK_SECRET`.

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

**Decision:** Stored in EntityIQ `.env`, not in WordPress.

All ad library scraping runs inside EntityIQ. WordPress never touches the token directly — it just fires a job and receives results.

**EntityIQ `.env` entries needed:**
```
# Meta Ad Library
META_AD_LIBRARY_TOKEN=<your Meta developer app access token>
META_AD_LIBRARY_FALLBACK=playwright   # 'playwright' or 'none'

# Google Ads Transparency
GOOGLE_ADS_TRANSPARENCY_KEY=<Google Cloud API key with Ads Transparency Insights API enabled>

# Playwright
PLAYWRIGHT_HEADLESS=true
PLAYWRIGHT_CONCURRENCY=3             # max concurrent browser instances

# Screenshot storage
BWG_SCREENSHOT_DIR=/var/data/bwg-screenshots

# Webhook back to WordPress
BWG_WEBHOOK_SECRET=<openssl rand -hex 32>
BWG_WP_WEBHOOK_URL=https://your-wp-site.com/wp-json/bwg/v1/ai/entityiq-webhook
```

**For the EntityIQ agent:**
1. Copy `.env.example` (create one from the above) to `.env` and fill in values
2. The Meta Ad Library API token requires a Meta developer app with the `ads_read` permission approved
3. If the token is absent or rate-limited, `meta-ad-library.js` falls back to the Playwright scraper automatically (controlled by `META_AD_LIBRARY_FALLBACK`)
4. Google Ads Transparency API requires the "Ads Transparency Insights" API enabled in Google Cloud Console

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
