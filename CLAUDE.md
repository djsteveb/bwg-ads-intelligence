# CLAUDE.md — AI Context for BWG Ads Intelligence

This file is the first thing a new AI chat session should read. It contains enough context to continue the build without re-reading everything from scratch.

**Always read this file first. Then read `docs/BUILD-PLAN.md` for the MVP milestone spec (M0–M10, complete) and `docs/PHASE-2-STATUS.md` + `docs/PHASE-2-BUILD-PLAN.md` for the current Phase 2 work (M11+).**

---

## What This Project Is

A WordPress plugin (`bwg-ads-intel`) that audits treatment center advertisers' full ad footprint across 6+ platforms, runs HIPAA + platform compliance analysis, and converts cold URL entries into managed-service clients ($3k–$10k/mo).

**Single codebase, self-contained.** The plugin was originally spec'd as a two-repo architecture (this WP plugin + a separate Node.js "EntityIQ" extension doing ad-scraping/vision/PDF work). A 2026-08-31 investigation found EntityIQ never built that side and has no plan to. As of M11, all ad-surface/vision/PDF work is built directly in this plugin, in PHP, calling external APIs (Meta, Google, Anthropic) directly — see `docs/PHASE-2-STATUS.md`.

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

**Note:** The `entityiq-extension/` directory below is a historical artifact from the abandoned two-repo design (specs that were meant for a separate EntityIQ agent that never built them). It is not built or maintained — do not add files there. See `docs/PHASE-2-STATUS.md` for the full history.

---

## Git Branch

Each milestone gets its own feature branch off `main` (see the branch name given in that session's task). Recent example: M11 landed on `claude/meta-ad-library-m11-xwbmbc`.

```bash
git checkout -b claude/{milestone-slug}
# ... make changes ...
git add <specific files>
git commit -m "[M{N}] milestone name — summary"
git push -u origin claude/{milestone-slug}
```

---

## Build Status

**Last completed milestone:** M13 — Claude vision compliance (2026-08-31). New `class-bwg-ai-vision.php` sends each ad's creative to the Claude API (`claude-opus-5`, raw `wp_remote_post()` — no PHP SDK, matching every other external call in this dependency-free plugin) with a HIPAA/42-CFR-Part-2/FTC-focused prompt, merging its flags into `compliance_flags` alongside the text rules (both now tagged `source: 'text'|'vision'`). For Meta ads (whose only creative is `ad_snapshot_url`, an HTML page) it reuses M12's render-provider to capture a real image first. Gated by a new `bwg_ai_claude_api_key` setting — unconfigured means vision is skipped silently, same fallback pattern as Meta/Google. See `docs/ARCHITECTURE.md` §6 and `docs/PHASE-2-STATUS.md`.

**Previous milestone:** M12 — Google Ads Transparency + local screenshot storage (2026-08-31) — render-provider abstraction for Google's Transparency Center, local screenshot storage with backup/delete-by-range/delete-by-age, and full EntityIQ retirement.

**Next milestone to build:** M14 — PDF export + remaining audience reports (see `docs/PHASE-2-BUILD-PLAN.md`)

**Milestones:**
- [x] Planning — docs written, todos set
- [x] M0 — Plugin scaffold (bootstrap, DB tables, uninstall)
- [x] M1 — Session layer + REST skeleton
- [x] M2 — Phase 1 Discovery Engine
- [x] M3 — Front-end form shortcode
- [x] M4 — Email layer
- [x] M5 — Phase 2 EntityIQ integration *(superseded by M11 — EntityIQ side never existed)*
- [x] M6 — Phase 3 Text compliance engine
- [x] M7 — Phase 4 Screenshot gallery UI
- [x] M8 — Phase 5 Access request funnel
- [x] M9 — Executive report
- [x] M10 — Admin panel
- [x] Security review
- [x] M11 — Fix Meta Ad Library integration (direct Graph API call, no EntityIQ)
- [x] M12 — Google Ads Transparency (render-provider) + local screenshot storage/backup/retention
- [x] M13 — Claude vision compliance (reuses M12 render provider for Meta creative)
- [ ] M14 — PDF export + remaining audience reports
- [ ] M15 — LinkedIn/TikTok (pending ToS spike)

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

## BWG Suite Integration (Not Yet Joined)

This plugin does **not** currently have a `bwg-suite-bridge.php` file, so it doesn't participate in the cross-plugin shared enrichment-data cache (`wp_bwg_data_cache`) that `bwg-auto-mailing-systems`, `bwg-comp-pl-one`, `entityiq`, `pagespeedtwo-sitescout`, and `webring-plus-v7` already share.

**Known duplicate credentials** (each independently configured here, with an identical-purpose credential also stored in at least one sibling plugin — no automatic sharing today):
- `bwg_ai_google_places_key` — also in `pagespeedtwo-sitescout` (`bwg_sa_google_places_key`), `entityiq` (`google_places_api_key`), and `webring-plus-v7`'s own settings.
- `bwg_ai_captcha_secret_key` (Cloudflare Turnstile) — also in `bwg-domain-hosting-control-record` (`dhcr_captcha_secret_key`) and `pagespeedtwo-sitescout`'s CPA Assessment module.

**Candidate for future bridge integration:** `ads-intel/class-bwg-ai-discovery.php`'s Google Places lookups are a plausible fit for the shared cache (write `places_basic`/`places_full` keyed by domain, same pattern just added to `webring-plus-v7`), since this plugin's discovery flow looks up real businesses by domain rather than doing free-text search. Not implemented in this pass — flagging as a scoped follow-up. See `1-map-synposises` (CAPABILITIES.md, "Duplicate credentials across the suite") for the full cross-repo matrix.

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

> Read `/home/user/bwg-ads-intelligence/CLAUDE.md` first — it is the AI context document for this project. Then read `docs/BUILD-PLAN.md` for the MVP milestone spec (M0–M10, complete) and `docs/PHASE-2-STATUS.md` + `docs/PHASE-2-BUILD-PLAN.md` for the current Phase 2 work. Then run `git log --oneline -10` to confirm build state.
>
> The project is: BWG Ads Intelligence System — a WordPress plugin for auditing treatment center advertisers' ad footprint. Originally spec'd as a two-repo architecture with a separate Node.js EntityIQ extension; that side was never built (see `docs/PHASE-2-STATUS.md`), so all ad-surface/vision/PDF work is now self-contained in this plugin, calling external APIs directly. All architectural decisions are locked in `docs/ARCHITECTURE.md`.
>
> **Current status: MVP (M0–M10 + Security Review) complete. M11 (Meta Ad Library fix), M12 (Google Ads Transparency + local screenshot storage), and M13 (Claude vision compliance) complete.**
>
> **Next task: M14 — PDF export + remaining audience reports (see `docs/PHASE-2-BUILD-PLAN.md`).**
>
> Do not add new features beyond `docs/PHASE-2-BUILD-PLAN.md` without a documented spec change.

---

*(Keep this prompt updated as milestones complete.)*
