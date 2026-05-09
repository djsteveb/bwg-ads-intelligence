# BWG Ads Intelligence — Build Plan

Ordered milestones. Complete each before starting the next. Each milestone ends with a commit + push.

Architectural decisions are in `docs/ARCHITECTURE.md`.  
AI context / handoff prompts are in `CLAUDE.md`.

---

## Milestone 0 — Plugin Scaffold
**Exit criteria:** Plugin activates in WordPress without fatal errors. All tables created. Deactivation is clean.

| File | What it does |
|---|---|
| `bwg-ads-intel/bwg-ads-intel.php` | Constants (`BWG_AI_VERSION`, `BWG_AI_DIR`, `BWG_AI_URL`), PSR-4-style autoloader, `register_activation_hook` → `BWG_AI_Activator::activate()`, `register_deactivation_hook`, `register_uninstall_hook` |
| `includes/class-bwg-ai-activator.php` | Creates all 8 DB tables (`sessions`, `discovered`, `ads`, `access`, `pages`, `reports`, `ratelimits`, `audit_log`). Uses `dbDelta()`. Stores `BWG_AI_DB_VERSION` option for future migrations. |
| `includes/class-bwg-ai-loader.php` | Collects all `add_action` / `add_filter` calls. Instantiates and wires all classes. Single `run()` method called at bottom of bootstrap. |
| `uninstall.php` | Drops all 8 tables. Deletes all `bwg_ai_*` options. Only runs if `WP_UNINSTALL_PLUGIN` is defined. |

---

## Milestone 1 — Session Layer + REST Skeleton
**Exit criteria:** `POST /start` returns `{ session_id, access_code }`. All 14 endpoints return 200 with placeholder JSON. Rate limiting active on `/start`.

| File | What it does |
|---|---|
| `includes/class-bwg-ai-session.php` | `create()`, `get()`, `update_step()`, `update_status()`. Access code: 6-char uppercase alphanumeric (avoid O/0/I/1). Resume token: `bin2hex(random_bytes(32))`, expires 30 days. |
| `includes/class-bwg-ai-rate-limiter.php` | Token bucket against `wp_bwg_ai_ratelimits`. Checks `class_exists('BWG_CPA_Rate_Limiter')` first and delegates if available. Limits: `/start` → 5/hour per IP; `/resume` → 10/hour per IP. |
| `includes/class-bwg-ai-security.php` | `verify_nonce()`, `sanitize_url()`, `sanitize_email_input()`, `verify_captcha()` (Cloudflare Turnstile), `verify_webhook_signature()` (HMAC-SHA256 for EntityIQ callbacks), `log_audit()`. |
| `ads-intel/class-bwg-ai-rest.php` | `register_rest_routes()` hooked into `rest_api_init`. Registers all 14 routes. Stubs return `WP_REST_Response` with `[ 'ok' => true, 'phase' => 'stub' ]`. `/report/{token}` and `/resume` skip nonce auth. `/entityiq-webhook` uses signature auth instead of nonce. |
| `includes/fallbacks/` (5 stub files) | Minimal stubs for `BWG_CPA_Discovery`, `BWG_SA_Scraper`, `BWG_SA_Module_PageSpeed`, `BWG_Compliance`, `BWG_CPA_Rate_Limiter`. Implement method signatures, return empty/safe defaults. |

**Security notes for M1:**
- All nonce-protected endpoints: `check_ajax_referer` equivalent via `verify_nonce()`
- IP extraction: use `$_SERVER['REMOTE_ADDR']` only; never trust `X-Forwarded-For` without a configured trusted proxy allowlist
- `/start` body: validate URL (must be http/https, real TLD, not a private IP range), validate email format, run captcha

---

## Milestone 2 — Phase 1: Discovery Engine
**Exit criteria:** Submitting a URL triggers cron, cron populates `wp_bwg_ai_discovered`, polling endpoint returns progress.

| File | What it does |
|---|---|
| `ads-intel/class-bwg-ai-discovery.php` | Orchestrates all sub-tasks. Checks for `BWG_CPA_Discovery` first; extends it or runs standalone. Sub-tasks run sequentially, each updating a `discovery_progress` JSON on the session row. On completion, sets `step_completed = 1` and schedules `bwg_ai_queue_ad_surface`. |

**Discovery sub-tasks (all in `class-bwg-ai-discovery.php`):**

1. **NAP extraction** — fetch homepage + `/contact`, parse `schema.org/LocalBusiness` JSON-LD, footer text, `tel:` links. Store name, address, phone.
2. **GBP match** — Google Places API `findplacefromtext` with NAP data. Store `place_id`, rating, review_count, category, hours.
3. **Social detection** — regex/DOM parse for FB page URLs, IG links, LinkedIn company, TikTok, YouTube. Extract pixel IDs from page source (`fbq('init', 'PIXEL_ID')`, `gtag`, GTM container ID, TikTok `ttq.load`).
4. **WHOIS/domain intel** — RDAP API (`https://rdap.org/domain/{domain}`). Store registrar, creation_date, expiry_date, nameservers. Flag if domain age < 6 months.
5. **Tech stack fingerprint** — fetch homepage, run against bundled Wappalyzer patterns JSON (open-source, include subset in plugin). Store CMS, form tool, chat widget, call tracking provider.
6. **LegitScript lookup** — HTTP GET to LegitScript search (public). Parse result for certification status. Flag if not found or "Not Recommended".
7. **State licensure signals** — check if site mentions SAMHSA, JCAHO/TJC, CARF, state license numbers (regex). Flag if none found.

Wire `GET /discovery-status/{id}` — returns `{ step, progress_pct, discovered: {...} }`.  
Wire `POST /confirm-discovery` — saves user edits to `wp_bwg_ai_discovered`.

---

## Milestone 3 — Front-End Form Shortcode
**Exit criteria:** `[bwg_ads_intel]` on a page renders a working multi-step form through Phase 1 confirm. Resume via `?resume=TOKEN` works.

| File | What it does |
|---|---|
| `ads-intel/class-bwg-ai-shortcode.php` | `register()` hooks `[bwg_ads_intel]`. Enqueues `ai-form.css` and `ai-form.js`. Localizes `window.bwgAI` (restUrl, nonce, resumeToken from `$_GET['resume']`, captchaSiteKey, scheduleUrl). |
| `ads-intel/assets/ai-form.css` | Styles matching the design system from `ads-intelligence-system.html` (same CSS custom properties: `--ink`, `--gold-mid`, `--teal`, `--coral`, etc.). Mobile-first, responsive. |
| `ads-intel/assets/ai-form.js` | Step machine (vanilla JS): **Step 1** → URL + email form → POST `/start` → show access code toast. **Step 2** → polling loop `/discovery-status/{id}` every 3s with progress bar. **Step 3** → discovery review form + corrections → POST `/confirm-discovery` → advance. **Resume flow** → on load, if `window.bwgAI.resumeToken`, POST `/resume` → restore to correct step. |

**UX notes:**
- Access code displayed prominently after Step 1 with "Save this code" message
- Each step auto-saves state to `localStorage` keyed by session_id as backup
- Error states for network failures, rate limit (show cooldown timer), captcha failure

---

## Milestone 4 — Email Layer
**Exit criteria:** Save-spot email fires on session create. Drip cron schedules Day 1/3/7 emails. Admin can select email provider.

| File | What it does |
|---|---|
| `includes/class-bwg-ai-email.php` | Central `send()` dispatcher (wp_mail / SendGrid / Postmark based on `bwg_ai_email_provider` option). Methods: `send_save_spot()`, `send_ads_preview()`, `send_followup_day1/3/7()`, `send_access_request(platform)`, `send_report_ready()`. All emails use HTML templates inlined with CSS. |

**Drip cron (`bwg_ai_send_access_followup` — hourly):**
- Query sessions where `step_completed < 4` and `created_at` is 24h / 72h / 7d ago
- Check `audit_log` to avoid duplicate sends
- Fire appropriate email, log to `wp_bwg_ai_audit_log`

**Email templates (inline HTML, no external CSS):**
- Save-spot: access code, resume link, what happens next
- Ads preview: "We found X ads" — blurred preview teaser, link to resume
- Day 1 follow-up: compliance flag teaser, resume CTA
- Day 3: "Still found X issues..." — urgency, specific flag count
- Day 7: final outreach, direct booking link

---

## Milestone 5 — Phase 2: EntityIQ Ad Surface Integration
**Exit criteria:** WP fires job to EntityIQ after Phase 1 confirm; EntityIQ scrapes Meta Ad Library; webhook returns ads; WP saves them; polling endpoint returns progress.

### WordPress side

| File | What it does |
|---|---|
| `ads-intel/class-bwg-ai-ad-surface.php` | `queue_job(session_id)` — POST to EntityIQ `/ads/surface` with `{ session_id, website_url, advertiser_hints, platforms: ['meta'] }`. Stores `entityiq_job_id`. `handle_webhook(payload)` — verify HMAC sig, save ads to `wp_bwg_ai_ads`, run compliance engine on each ad, trigger `send_ads_preview` email. |

Schedule `bwg_ai_poll_entityiq` every 30s while job is active (as backup to webhook).  
Wire `GET /ad-surface-status/{id}` and `GET /ads/{id}`.

### EntityIQ side (Node.js)

| File | What it does |
|---|---|
| `entityiq-extension/routes/ads.js` | Express router. Registers `POST /ads/surface`, `GET /ads/surface/:jobId`, `POST /ads/screenshot`, `POST /ads/vision` (stub), `POST /ads/pdf` (stub), `GET /ads/storage/stats`, `GET /ads/storage/export`, `DELETE /ads/storage`. |
| `entityiq-extension/lib/meta-ad-library.js` | Meta Ad Library API client. `search(advertiserName, pageId)` → returns normalized ad objects. Falls back to Playwright scraper if token missing or 429. |
| `entityiq-extension/lib/google-transparency.js` | Google Ads Transparency API stub for MVP. Returns empty array. Full implementation in Phase 2. |
| `entityiq-extension/lib/ad-scraper.js` | LinkedIn, TikTok, Bing stubs for MVP. Returns empty arrays. |
| `entityiq-extension/lib/screenshot.js` | Playwright browser manager. Queue with `PLAYWRIGHT_CONCURRENCY` limit. `captureUrl(url)` → saves to `BWG_SCREENSHOT_DIR/{date}/{session_id}/{filename}.png`, returns relative path + file size. |
| `entityiq-extension/lib/vision-compliance.js` | Stub for MVP. Returns `{ flags: [], analyzed: false, reason: 'deferred' }`. Phase 2 wires Claude vision. |
| `entityiq-extension/lib/pdf-report.js` | Stub for MVP. Returns `{ pdf_path: null, reason: 'deferred' }`. Phase 2 wires Puppeteer. |

**Job flow:**
1. POST `/ads/surface` → create job record in memory/Redis, return `202 { job_id }`
2. Async worker: run Meta scraper → screenshot each ad → run vision stub → POST back to WP webhook
3. `GET /ads/surface/:jobId` → return `{ status, progress_pct, ads_found, ads[] }`

---

## Milestone 6 — Phase 3: Text Compliance Engine
**Exit criteria:** All ads in `wp_bwg_ai_ads` have `compliance_flags` populated after save.

| File | What it does |
|---|---|
| `ads-intel/class-bwg-ai-compliance.php` | `analyze_ad_copy(ad_copy, platform)` → returns `compliance_flags[]` array. Each flag: `{ rule_id, severity (high/medium/low), category, excerpt, citation }`. Called automatically when ads are saved via webhook. |

**Rule categories:**

| Category | Rules |
|---|---|
| HIPAA / Legal (high) | Treatment outcome guarantees ("100% success", "guaranteed sobriety"), patient story identifiers, bait-and-switch availability ("beds available now"), 42 CFR Part 2 patterns (substance use disclosure without consent language), unlicensed facility implied claims |
| Platform policy (medium) | Missing health disclaimer on addiction ads, before/after language ("before treatment vs. after"), non-LegitScript certified claims on Google/Meta, missing "call for availability" disclaimer |
| Best practice (low) | No phone number, no insurance mention, no accreditation reference, excessive urgency language |

Run `BWG_Compliance::check()` first if sibling plugin is active; supplement with above rules.

---

## Milestone 7 — Phase 4: Screenshot Gallery UI
**Exit criteria:** User sees found ads with screenshots, can confirm or flag each, can add more accounts.

Add Step 4 to `ai-form.js`:
- Gallery grid: pulls from `GET /ads/{id}`, renders ad card (screenshot, copy excerpt, platform badge, compliance flag count)
- Each card: "Confirm — this is ours" / "Flag — don't recognize this" buttons → PATCH state locally, batch POST to `/confirm-ads`
- "Add more accounts" expandable form → POST to `/add-accounts` → triggers new EntityIQ job, shows loading state

Wire `POST /confirm-ads` — updates `wp_bwg_ai_ads.user_confirmed` flag.  
Wire `POST /add-accounts` — saves additional identifiers, re-queues EntityIQ surface job.

---

## Milestone 8 — Phase 5: Access Request Funnel
**Exit criteria:** User gets per-platform email templates they can forward. Can upload Meta/Google CSV exports. Export data appears in ads table.

Add Step 5 to `ai-form.js`:
- Platform cards (Meta, Google) with status badge (pending / granted / exported)
- "Generate access request email" → calls `send_access_request(platform)`, shows inline preview of the email
- Step-by-step grant instructions (collapsible, per-platform)
- Upload portal: drag-drop or file picker for CSV exports, progress bar, success/error state

Wire `POST /access-status` — updates `wp_bwg_ai_access.access_status`.  
Wire `POST /upload-export`:
- Meta Ads CSV parser: columns `Ad ID, Ad name, Ad status, Results, Reach, Impressions, Cost per result, Amount spent, Starts, Ends`
- Google Ads CSV parser: standard Google Ads export format
- Merge parsed data into `wp_bwg_ai_ads` (match by ad_id or create new rows)

---

## Milestone 9 — Executive Report
**Exit criteria:** On Phase 5 complete, report generates, tokenized URL is accessible publicly, email fires with link.

| File | What it does |
|---|---|
| `ads-intel/class-bwg-ai-report.php` | `generate(session_id, audience = 'executive')`. Assembles report data JSON from `discovered`, `ads`, `access` tables. Computes risk score (weighted flag severity counts, 0–100). Estimates wasted spend (heuristic from spend ranges × compliance rate). Derives top 3 actions from highest-severity flags. Stores in `wp_bwg_ai_reports` with `report_token = wp_generate_uuid4()`. Returns token. |
| `admin/partials/report-template.php` | HTML template for executive report. Uses same design system (DM Serif Display, Inter, IBM Plex Mono). Sections: risk score gauge, wasted spend estimate, top 3 urgent actions, platform snapshot, what's working, CTA to book a call. |

Wire `GET /report/{token}` — no auth, render report-template.php with report data (expires_at check).  
Wire `POST /email-report` — send report link + PDF stub attachment.

---

## Milestone 10 — Admin Panel
**Exit criteria:** WP admin can see all sessions, drill into any session, manage settings, view storage usage.

| File | What it does |
|---|---|
| `admin/class-bwg-ai-admin.php` | Register admin menu ("Ads Intelligence"). Sub-menus: Sessions, Settings, Storage. Handle settings form save (`register_setting`, nonce, `sanitize_callback`). |
| `admin/partials/admin-list.php` | WP_List_Table subclass. Columns: ID, Email, URL, Status, Step, Compliance Flags, Created. Sortable. Filterable by status. Bulk action: delete sessions. |
| `admin/partials/admin-detail.php` | Full session view: discovered data cards, ads gallery, compliance flag list, access status per platform, report links, email log, audit trail, manual status override. |
| `admin/partials/admin-settings.php` | Settings sections: General (EntityIQ URL, shared secret), Email (provider selector, API keys, from name/email, test button), API Keys (Google Places, Captcha), Storage (warning threshold), Booking URL. |
| Storage panel | Query EntityIQ `GET /ads/storage/stats`. Render total used, weekly bar chart (HTML/CSS only, no chart lib dependency). Date range pickers for export (download zip) and delete (confirmation modal with session count). |

**`bwg_ai_daily_maintenance` cron:**
- Expire resume tokens older than 30 days
- Clean `wp_bwg_ai_ratelimits` rows where `expires_at` < now
- Prune `wp_bwg_ai_audit_log` rows older than 90 days (configurable)
- If total screenshot storage > warning threshold, email admin

---

## Security Review ✅ Complete

*Completed 2026-05-09. All items resolved and committed.*

**WordPress plugin best practices:**
- [x] All user inputs sanitized with `sanitize_text_field`, `sanitize_url`, `sanitize_email`, `absint` etc.
- [x] All DB queries use `$wpdb->prepare()` — zero raw interpolation
- [x] All output escaped with `esc_html`, `esc_attr`, `esc_url` before rendering
- [x] Nonces on every form and every state-changing REST endpoint
- [x] Capability checks (`current_user_can('manage_options')`) on all admin actions
- [x] File uploads: MIME type validation (not just extension), size limit, store outside web root or use wp_handle_upload with restrictions
- [x] REST endpoints that return session data verify the requesting user owns the session — `X-BWG-Session-Token` header checked via `hash_equals()` on all 10 session-scoped endpoints; admin bypass via `current_user_can('manage_options')`
- [x] Webhook endpoint: HMAC-SHA256 signature verification + timestamp replay protection (reject if >5 min old)
- [x] No sensitive data (API keys, tokens) logged to `audit_log` or error logs

**Abuse / DDoS hardening:**
- [x] `/start` rate limit: 5 per IP per hour, 20 per IP per day (stored in `wp_bwg_ai_ratelimits`)
- [x] `/resume` rate limit: 10 per IP per hour
- [x] `/upload-export` rate limit: 3 per session per hour; max file size 10MB; allowed types: `text/csv`, `application/csv`
- [x] Captcha (Cloudflare Turnstile) on `/start` and `/resume` (access-code path; token-path exempt — 256-bit entropy)
- [x] Access codes: lockout after 5 wrong guesses per IP per hour — `BWG_AI_Rate_Limiter::is_locked()` + `::increment()`
- [x] Resume tokens: 64 hex chars (256-bit entropy); cannot be guessed
- [x] All cron handlers guard with `wp_doing_cron()`: `BWG_AI_Discovery::run()`, `BWG_AI_Ad_Surface::poll()`, `BWG_AI_Email::send_followups()`, `BWG_AI_Admin::daily_maintenance()`
- [x] EntityIQ webhook: HMAC signature required; IP allowlist option (`BWG_ENTITYIQ_ALLOWED_IPS`) deferred to staging config
- [x] Admin notification if: >100 sessions/hr or >50 failed resume attempts/hr — `BWG_AI_Email::notify_on_abuse()` called from hourly cron; storage threshold alert in daily maintenance

**Additional checks:**
- [x] SQL injection: all queries use `$wpdb->prepare()`; platform/status values validated against explicit allowlists
- [x] XSS: JS output uses `esc()` helper (HTML-entity encoding); no `innerHTML` with unsanitized data; `extract()` anti-pattern removed from `get_report()` and replaced with typed explicit assignments
- [x] CSRF: nonce on all state-changing REST actions; `X-WP-Nonce` header required
- [x] SSRF: `BWG_AI_Security::sanitize_url_input()` rejects non-http(s) schemes, private IP ranges (10.x, 172.16.x, 192.168.x, 127.x, ::1, link-local, loopback), bare hostnames; DNS resolves host and checks resolved IP too
- [ ] Dependency audit: `npm audit` on EntityIQ extension before staging deploy *(run in EntityIQ repo)*

---

## Handoff Protocol

At the end of each milestone:

1. **Commit** all files for that milestone with message: `[M{N}] {milestone name} — {one-line summary}`
2. **Push** to `claude/read-files-plan-build-yho2p`
3. **Update `CLAUDE.md`** — mark milestone complete, update "Last completed milestone" and "Next steps" sections
4. **Assess context:** If the current chat has been running long (many files written, large context), open a new chat. Otherwise continue.
5. **New chat prompt** (copy from `CLAUDE.md` → "New Chat Prompt" section — kept updated after each milestone)
