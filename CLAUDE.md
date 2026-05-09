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

All work goes on: `claude/read-files-plan-build-yho2p`

```bash
git checkout claude/read-files-plan-build-yho2p
# ... make changes ...
git add <specific files>
git commit -m "[M{N}] milestone name — summary"
git push -u origin claude/read-files-plan-build-yho2p
```

---

## Build Status

**Last completed milestone:** M4 — Email layer (send dispatcher, drip cron, all 7 email methods + HTML templates)

**Next milestone to build:** M5 — Phase 2 EntityIQ integration

**Milestones:**
- [x] Planning — docs written, todos set
- [x] M0 — Plugin scaffold (bootstrap, DB tables, uninstall)
- [x] M1 — Session layer + REST skeleton
- [x] M2 — Phase 1 Discovery Engine
- [x] M3 — Front-end form shortcode
- [x] M4 — Email layer
- [ ] M5 — Phase 2 EntityIQ integration
- [ ] M6 — Phase 3 Text compliance engine
- [ ] M7 — Phase 4 Screenshot gallery UI
- [ ] M8 — Phase 5 Access request funnel
- [ ] M9 — Executive report
- [ ] M10 — Admin panel
- [ ] Security review

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

> Read the file `/home/user/bwg-ads-intelligence/CLAUDE.md` first — it is the context document for this project. Then read `docs/BUILD-PLAN.md` for the ordered task list. Then check `git log --oneline -10` on branch `claude/read-files-plan-build-yho2p` to see what's been completed.
>
> The project is: BWG Ads Intelligence System — a WordPress plugin + Node.js EntityIQ extension for auditing treatment center advertisers' ad footprint. Two-codebase architecture; this repo contains the WordPress plugin. Architectural decisions are locked in `docs/ARCHITECTURE.md`.
>
> Pick up at the next incomplete milestone in `docs/BUILD-PLAN.md` and build it. Commit with message `[M{N}] milestone name — summary` and push to `claude/read-files-plan-build-yho2p` when done. Update the milestone checklist in `CLAUDE.md`.

---

*(Keep this prompt updated as milestones complete — replace "next incomplete milestone" with a specific one if the build state is complex.)*
