# CLAUDE.md — AI Context for BWG Ads Intelligence

This file is the first thing a new AI chat session should read. It contains enough context to continue the build without re-reading everything from scratch.

**Always read this file first. Then read `docs/BUILD-PLAN.md` for the ordered task list.**

---

## What This Project Is

A WordPress plugin (`bwg-ads-intel`) + Node.js EntityIQ extension that audits treatment center advertisers' full ad footprint across 6+ platforms, runs HIPAA + platform compliance analysis, and converts cold URL entries into managed-service clients ($3k–$10k/mo).

**Entry:** Public URL form on a WordPress site (shortcode `[bwg_ads_intel]`)  
**Exit:** Multi-audience audit reports + access to retained managed services

Full product blueprint: `ads-intelligence-system.html`  
Technical PRD: `ads-intelligence-prd.md`  
Architectural decisions: `docs/ARCHITECTURE.md`  
Ordered build plan: `docs/BUILD-PLAN.md`

---

## Repository Layout

```
bwg-ads-intelligence/              ← This repo (planning + WP plugin)
├── CLAUDE.md                      ← You are here
├── readme.md                      ← Project overview
├── ads-intelligence-system.html   ← Full product blueprint (rendered)
├── ads-intelligence-prd.md        ← Technical PRD (architecture, DB, endpoints)
├── docs/
│   ├── ARCHITECTURE.md            ← 5 locked architectural decisions
│   └── BUILD-PLAN.md              ← Ordered milestone build plan with todos
└── bwg-ads-intel/                 ← WordPress plugin (build here)
    ├── bwg-ads-intel.php
    ├── includes/
    │   ├── fallbacks/             ← Stub classes for bwg-speed-sitescout
    │   ├── class-bwg-ai-loader.php
    │   ├── class-bwg-ai-activator.php
    │   ├── class-bwg-ai-session.php
    │   ├── class-bwg-ai-rate-limiter.php
    │   ├── class-bwg-ai-security.php
    │   └── class-bwg-ai-email.php
    ├── ads-intel/
    │   ├── class-bwg-ai-rest.php
    │   ├── class-bwg-ai-shortcode.php
    │   ├── class-bwg-ai-discovery.php
    │   ├── class-bwg-ai-ad-surface.php
    │   ├── class-bwg-ai-compliance.php
    │   ├── class-bwg-ai-spider.php  (Phase 6 — deferred)
    │   ├── class-bwg-ai-report.php
    │   └── assets/
    │       ├── ai-form.js
    │       └── ai-form.css
    ├── admin/
    │   ├── class-bwg-ai-admin.php
    │   └── partials/
    │       ├── admin-list.php
    │       ├── admin-detail.php
    │       ├── admin-settings.php
    │       └── report-template.php
    └── uninstall.php

entityiq-extension/                ← Node.js additions (separate repo, different agent)
├── routes/ads.js
└── lib/
    ├── ad-scraper.js
    ├── meta-ad-library.js
    ├── google-transparency.js
    ├── screenshot.js
    ├── vision-compliance.js
    └── pdf-report.js
```

**Note:** The `entityiq-extension/` directory contains specs for the EntityIQ agent working in the other repository. Do not build those files here — they live in the EntityIQ repo.

---

## Two Codebases, Two Agents

| Layer | Repo | Agent |
|---|---|---|
| WordPress Plugin | This repo (`bwg-ads-intelligence`) | This agent |
| EntityIQ Node.js extension | EntityIQ repo (separate) | Other agent |

The WP plugin fires async REST calls to EntityIQ for heavy jobs (scraping, screenshots, vision). EntityIQ calls back via webhook when done.

**For the EntityIQ agent** — everything it needs to build is in `docs/ARCHITECTURE.md` (webhook auth, storage, .env vars) and `docs/BUILD-PLAN.md` (Milestone 5, EntityIQ side section).

---

## Git Branch

All work goes on: `claude/implement-email-layer-U9k6B`

```bash
git checkout claude/implement-email-layer-U9k6B
# ... make changes ...
git add <specific files>
git commit -m "[M{N}] milestone name — summary"
git push -u origin claude/implement-email-layer-U9k6B
```

---

## Build Status

**Last completed milestone:** Cross-repo security audit (2026-07-08) — CAPTCHA now fails closed instead of skipping verification when unconfigured; `uninstall.php`'s option cleanup list fixed to match actual registered option names; five previously-plaintext credentials (SendGrid, Postmark, EntityIQ HMAC secret, CAPTCHA secret, Google Places key) encrypted at rest (AES-256-CBC); joined the BWG suite's shared-credential system so the Google Places key falls back to a sibling plugin's key when not configured here. See `1-map-synposises` (`CAPABILITIES.md`) for the full cross-repo writeup.

**Previous milestone:** Security review — IDOR protection, captcha on resume, access-code lockout, cron guards, abuse notifications

**Next milestone to build:** QA / staging deployment

**Milestones:**
- [x] Planning — docs written, todos set
- [x] M0 — Plugin scaffold (bootstrap, DB tables, uninstall)
- [x] M1 — Session layer + REST skeleton
- [x] M2 — Phase 1 Discovery Engine
- [x] M3 — Front-end form shortcode
- [x] M4 — Email layer
- [x] M5 — Phase 2 EntityIQ integration
- [x] M6 — Phase 3 Text compliance engine
- [x] M7 — Phase 4 Screenshot gallery UI
- [x] M8 — Phase 5 Access request funnel
- [x] M9 — Executive report
- [x] M10 — Admin panel
- [x] Security review

*(Update this list after each milestone is committed.)*

---

## Key Architectural Decisions (summary — full detail in docs/ARCHITECTURE.md)

1. **EntityIQ webhook auth:** HMAC-SHA256 signature (`X-BWG-Signature` header) + timestamp replay protection. Secret in WP option `bwg_ai_entityiq_secret` and EntityIQ `.env BWG_WEBHOOK_SECRET`.

2. **Screenshot storage:** Local disk on EntityIQ server (`BWG_SCREENSHOT_DIR`). Admin storage dashboard shows total/weekly usage; export and delete by date range via EntityIQ storage API routes.

3. **bwg-speed-sitescout dependency:** Soft — `class_exists()` check, bundle stubs in `includes/fallbacks/` if sibling plugin not active.

4. **Email provider:** wp_mail default. Admin UI to switch to SendGrid or Postmark. Never fight other SMTP plugins that intercept wp_mail.

5. **Meta Ad Library API token:** In EntityIQ `.env` only. WP never touches it. See `docs/ARCHITECTURE.md` for full `.env` template.

---

## DB Tables (prefix: `wp_bwg_ai_`)

| Table | Purpose |
|---|---|
| `sessions` | One row per audit. Tracks step, status, access code, resume token, EntityIQ job ID. |
| `discovered` | Phase 1 results: GBP, social, pixels, WHOIS, LegitScript, discovery_confidence JSON. |
| `ads` | One row per ad found. Platform, copy, image URL, screenshot path, compliance_flags JSON, vision_analysis JSON. |
| `access` | Per-platform access grant status (pending/granted/export). |
| `pages` | Phase 6 landing page spider results (deferred to Phase 2 build). |
| `reports` | Generated reports, report_token (UUID), report_data JSON, PDF path. |
| `ratelimits` | Token bucket for rate limiting. key, count, expires_at. |
| `audit_log` | All actions, emails sent, errors. session_id, action, message, context JSON. |

---

## REST Endpoints

Base: `/wp-json/bwg/v1/ai/`  
Auth: `X-WP-Nonce` on all except `/resume` (access code auth) and `/report/{token}` (public) and `/entityiq-webhook` (HMAC signature auth)

| Endpoint | Method | Phase |
|---|---|---|
| `/start` | POST | 1 — create session |
| `/discovery-status/{id}` | GET | 1 poll |
| `/confirm-discovery` | POST | 1 save |
| `/ad-surface-status/{id}` | GET | 2 poll |
| `/ads/{id}` | GET | 2 |
| `/confirm-ads` | POST | 4 |
| `/add-accounts` | POST | 4 |
| `/access-status` | POST | 5 |
| `/request-access` | POST | 5 |
| `/upload-export` | POST | 5 |
| `/spider-status/{id}` | GET | 6 (deferred) |
| `/report/{token}` | GET | public |
| `/email-report` | POST | 7 |
| `/resume` | POST | any |
| `/entityiq-webhook` | POST | internal |

---

## WP-Cron Hooks

| Hook | Schedule | Handler |
|---|---|---|
| `bwg_ai_run_discovery` | 5s after `/start` | `BWG_AI_Discovery` |
| `bwg_ai_poll_entityiq` | Every 30s while job active | `BWG_AI_REST` → `BWG_AI_Ad_Surface` |
| `bwg_ai_send_access_followup` | Hourly | `BWG_AI_Email` |
| `bwg_ai_daily_maintenance` | Daily | `BWG_AI_Admin` |

---

## Security Requirements (apply throughout)

- All DB queries: `$wpdb->prepare()` — no raw string interpolation
- All output: `esc_html()`, `esc_attr()`, `esc_url()` before rendering
- All inputs: appropriate sanitize_* function
- Nonces: every form, every state-changing REST endpoint
- Rate limits: `/start` 5/hr/IP + 20/day/IP; `/resume` 10/hr/IP; `/upload-export` 3/session/hr
- File uploads: MIME check, size limit (10MB), store outside web root
- SSRF protection: validate URL input against allowlist (http/https only, no private IP ranges)
- EntityIQ webhook: HMAC-SHA256 + timestamp replay protection

---

## BWG Suite Integration (Partially Joined)

**Corrected 2026-08-31 — the previous version of this section was stale.** This plugin *does* have `includes/bwg-suite-bridge.php` (function-guarded, loaded unconditionally from `bwg-ads-intel.php`), and it's live for one thing: **credential sharing**. `bwg_ai_get_google_places_key()` (`includes/class-bwg-ai-security.php`) tries this plugin's own `bwg_ai_google_places_key` option first, then falls back to `bwg_suite_find_shared_credential( 'google_places_api_key', 'Ads Intelligence' )`, which can pull a configured key from SpeedScout, EntityIQ, or Webring if this plugin's own isn't set. The admin settings screen (`admin/partials/admin-settings.php`) also uses `bwg_suite_credential_source_statuses()` to show "using shared key from X" / "could come from X if configured" hints. Verified 2026-08-31 by reading the bridge file end to end: `$wpdb->prepare()` on every query, correctly `function_exists()`-guarded against redeclaration when a sibling plugin's own copy of the same file loads first, and the credential resolver correctly excludes this plugin's own entry via `$exclude_label` so it never "shares" a key with itself.

**Not yet joined:** the cross-plugin **shared enrichment-data cache** (`wp_bwg_data_cache`, read/write via `bwg_cache_get()`/`bwg_cache_set()` — both defined in the bridge file but never called anywhere else in this plugin). `ads-intel/class-bwg-ai-discovery.php`'s Google Places lookups still go direct every time rather than reading/writing the shared cache the way `webring-plus-v7` and others do. This matches `1-map-synposises/CAPABILITIES.md`'s own note: "joined the bridge; Google Places calls still go direct."

**Known duplicate credentials** (each independently configured here, with an identical-purpose credential also stored in at least one sibling plugin — the Google Places one has a live fallback per above; the rest don't yet):
- `bwg_ai_google_places_key` — also in `pagespeedtwo-sitescout` (`bwg_sa_google_places_key`), `entityiq` (`google_places_api_key`), and `webring-plus-v7`'s own settings. **Fallback-sharing is live** via the bridge (see above).
- `bwg_ai_captcha_secret_key` (Cloudflare Turnstile) — also in `bwg-domain-hosting-control-record` (`dhcr_captcha_secret_key`) and `pagespeedtwo-sitescout`'s CPA Assessment module. No shared-credential resolver entry exists for this yet.

**Candidate for future bridge integration:** wire `class-bwg-ai-discovery.php`'s Google Places lookups into `bwg_cache_get()`/`bwg_cache_set()` (write `places_basic`/`places_full` keyed by domain, same pattern already live in `webring-plus-v7`), since this plugin's discovery flow looks up real businesses by domain rather than doing free-text search. Not implemented yet — flagging as a scoped follow-up. See `1-map-synposises` (`CAPABILITIES.md`, "Duplicate credentials across the suite" and the shared-cache producer/consumer table) for the full cross-repo matrix.

**Minor code note (not a blocker):** the bridge's `bwg_suite_encrypt_secret()`/`decrypt_secret()` and this plugin's own `bwg_ai_encrypt_secret()`/`decrypt_secret()` (`class-bwg-ai-security.php`) both store secrets as `base64(cipher . '::' . $iv)` and split on the *first* `::` via `explode( '::', $decoded, 2 )`. Since the ciphertext is arbitrary binary, it can occasionally contain the byte sequence `::` before the real separator, which would split in the wrong place and fail to decrypt (falls back to returning the raw stored value, so it fails safe rather than crashing — but the key silently doesn't decrypt). Low probability, and it's a pattern shared by already-security-reviewed code elsewhere in this plugin, not something newly introduced — not urgent, but worth a length-prefixed or fixed-offset format (IV is always 16 bytes) if this code is touched again.

---

## MVP Scope vs. Phase 2

**Build now (MVP):**
- Phase 1 Discovery (GBP, social, pixels, WHOIS, LegitScript)
- Phase 2 Meta Ad Library only (API + scraper fallback)
- Phase 3 Text compliance (ad copy rules only — no vision)
- Phase 4 Screenshot gallery + confirm UI
- Phase 5 Access request funnel (Meta + Google templates, CSV upload)
- Session persistence, email drip (3 follow-ups)
- Executive report (1 audience, in-browser)
- Admin: session list, detail, settings, storage dashboard

**Deferred to Phase 2:**
- Google Transparency, LinkedIn, TikTok, Bing scraping
- Claude vision image compliance (`vision-compliance.js`)
- Phase 6 landing page spider
- Phase 7 admissions / call audit
- All 5 audience reports + PDF export
- Chrome extension
- Competitor surveillance / continuous monitoring

---

## New Chat Prompt

*Copy this prompt when starting a fresh chat to continue this build:*

---

> Read `/home/user/bwg-ads-intelligence/CLAUDE.md` first — it is the AI context document for this project. Then read `docs/BUILD-PLAN.md` for the MVP milestone spec (M0–M10, all complete) and `docs/PHASE-2-STATUS.md` + `docs/PHASE-2-BUILD-PLAN.md` for what's next. Then run `git log --oneline -10` to confirm build state.
>
> The project is: BWG Ads Intelligence System — a WordPress plugin for auditing treatment center advertisers' ad footprint. It was originally spec'd as a two-codebase architecture (this WP plugin + a separate Node.js EntityIQ extension doing the ad-scraping/vision/PDF work), but a 2026-08-31 investigation found EntityIQ never built that side and has no plan to — its real roadmap is unrelated (Local SEO/schema tooling). **Decision: build the remaining ad-surface/vision/PDF work self-contained in this WordPress plugin, in PHP, calling external APIs directly — no EntityIQ dependency.** MVP architectural decisions are locked in `docs/ARCHITECTURE.md`, though §1 and §5 there still describe the old EntityIQ-dependent design and need updating as `docs/PHASE-2-BUILD-PLAN.md` is implemented.
>
> **Current status: All milestones M0–M10 + Security Review are complete for MVP. Since then, a Phase 2 scope investigation (2026-08-31) found that the MVP Meta Ad Library integration is actually non-functional — it calls EntityIQ endpoints that don't exist in the real EntityIQ repo — and produced a full self-contained build plan to fix it plus build out Phase 2. Read `docs/PHASE-2-STATUS.md` and `docs/PHASE-2-BUILD-PLAN.md` before doing anything else.**
>
> **Next task: M11 — fix the Meta Ad Library integration (critical, MVP is broken without this).**
>
> Per `docs/PHASE-2-BUILD-PLAN.md` §1: replace the EntityIQ job-queue calls in `ads-intel/class-bwg-ai-ad-surface.php` (`queue_job()`, `poll()`, `post_to_entityiq()`, `get_from_entityiq()`) with a direct call to the Meta Graph API `ads_archive` endpoint. Specifically:
> - New file `ads-intel/class-bwg-ai-meta-ad-library.php` — calls `GET https://graph.facebook.com/{version}/ads_archive` with a long-lived `ads_read` token, using the same `wp_remote_get()` + `wp_remote_post()` conventions already used elsewhere in this plugin (see `bwg_ai_get_google_places_key()` in `class-bwg-ai-security.php` for the settings/encryption pattern to follow for the new `bwg_ai_meta_ad_library_token` option).
> - Display each ad's `ad_snapshot_url` (Meta's own hosted rendered snapshot) directly instead of capturing a screenshot — no headless browser needed for Meta.
> - When no token is configured, fall back to a manual-entry mode (user pastes Ad Library URLs) rather than scraping — document this tradeoff in the UI.
> - `class-bwg-ai-ad-surface.php` becomes the orchestrator that calls this class (and future platform adapters) synchronously/via WP-Cron batches, instead of queuing a remote EntityIQ job — drop the async webhook/HMAC pattern for this pipeline entirely.
> - Update `docs/ARCHITECTURE.md` §1 and §5 to match once this is built, since they still describe the EntityIQ-dependent design being replaced.
>
> After M11 is done and tested, continue down `docs/PHASE-2-BUILD-PLAN.md`'s milestone table in order: M12 (Google Ads Transparency via a render-provider abstraction), M13 (Claude vision compliance — port the pattern from `BWG-Ads-Acount-Audit`'s `class-bwg-maa-vision.php`, HIPAA-focused prompt), M14 (PDF export via browser print-to-PDF, same source, + the 4 remaining audience reports), M15 (LinkedIn/TikTok, after a ToS feasibility spike). Bing/Microsoft is cut from scope — no public ad-transparency data source exists for it.
>
> Do not add new features beyond what's in `docs/PHASE-2-BUILD-PLAN.md` without a documented spec change first.

---

*(Keep this prompt updated as milestones complete.)*
