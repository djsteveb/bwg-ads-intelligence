# BWG Ads Intelligence System

Multi-platform ads audit, compliance analysis, and lead conversion engine for treatment center advertisers (TCA / Better Web Group).

## What This Is

A 7-phase audit platform that starts with a URL and expands into a full patient journey and admissions performance review — converting cold prospects into managed-service clients.

**Entry point:** Public-facing URL form (free lead gen)  
**Exit point:** Retained managed services ($3k–$10k/mo)

## Features

### Phase 1 — Discovery Engine
Auto-discovers everything knowable about a treatment center's digital footprint from a single URL: Google Business Profile match, social presence, pixel/tag IDs, tech stack, WHOIS/RDAP, NAP consistency, LegitScript certification status, state licensure signals.

### Phase 2 — Ad Library Surface
Pulls active and historical ads from every major transparency tool. MVP covers Meta Ad Library (API + scraper fallback). Phase 2 adds Google Transparency, LinkedIn, TikTok, Bing.

### Phase 3 — Compliance Analysis
Rule engine against ad copy for HIPAA violations, treatment outcome guarantee language, 42 CFR Part 2 patterns, platform policy violations. Phase 2 adds Claude vision image compliance.

### Phase 4 — Verification & Expansion
Screenshot gallery for user confirmation. Multi-account discovery — user can add additional FB pages, Business Managers, agency names to trigger new scan passes.

### Phase 5 — Access Request Funnel
Per-platform instruction cards, forwardable email templates, step-by-step access grant guides, export upload portal with Meta/Google CSV parsing, follow-up email drip.

### Phase 6 — Landing Page Spider *(Phase 2 build)*
Spider every landing URL: screenshot, archive, Core Web Vitals, pixel audit (HIPAA), form testing, ad→page message match score.

### Phase 7 — Journey & Admissions Audit *(Phase 2 build)*
Full funnel: awareness to admissions, call quality analysis, CRM integration, attribution modeling.

### Reports
Five audience reports from one underlying audit dataset:
- **Executive / Owner** — risk score, wasted spend estimate, top 3 actions
- **CMO / Marketing Dir** — benchmarks, channel mix, 90-day roadmap
- **Compliance / Legal** — HIPAA exposure itemized, platform flags cited
- **Agency Internal** — account map, access status, upsell flags
- **Admissions Director** — channel → call → admission path

MVP delivers: executive report (in-browser). Phase 2 adds all 5 + PDF export.

### Admin Dashboard
Session list, session detail, manual status overrides, settings, storage management (total usage, weekly breakdown, export by date range, delete by date range).

### Email Sequences
- Save-spot email (immediate, with access code)
- Ads preview email (on Phase 2 complete)
- Day 1 / Day 3 / Day 7 follow-up drip
- Per-platform access request templates (forwardable)
- Report ready email with link

---

## Repository Layout

```
bwg-ads-intelligence/
├── CLAUDE.md                        ← AI context file (read this first in new sessions)
├── ads-intelligence-system.html     ← Product blueprint (rendered design doc)
├── ads-intelligence-prd.md          ← Technical PRD (architecture, DB, endpoints)
├── docs/
│   ├── ARCHITECTURE.md              ← Locked architectural decisions
│   └── BUILD-PLAN.md                ← Ordered milestone build plan
└── bwg-ads-intel/                   ← WordPress plugin
    ├── bwg-ads-intel.php
    ├── includes/
    │   ├── fallbacks/               ← Stubs for bwg-speed-sitescout classes
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
    │   ├── class-bwg-ai-spider.php
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
```

**The EntityIQ Node.js extension** lives in a separate repository. See `docs/ARCHITECTURE.md` for the EntityIQ `.env` setup and `docs/BUILD-PLAN.md` (Milestone 5) for the full file spec to give to the EntityIQ agent.

---

## Architecture Overview

The system splits across two codebases:

| Layer | Technology | Handles |
|---|---|---|
| WordPress Plugin | PHP | User form, sessions, email sequences, admin, reports |
| EntityIQ extension | Node.js | Ad library scraping, screenshots, AI vision, PDF export |

The WP plugin fires async REST calls to EntityIQ for heavy data acquisition jobs. EntityIQ calls back via webhook on completion.

---

## Architectural Decisions

Full detail in `docs/ARCHITECTURE.md`. Summary:

| Decision | Choice |
|---|---|
| EntityIQ webhook auth | HMAC-SHA256 (`X-BWG-Signature` header) + timestamp replay protection |
| Screenshot storage | Local disk on EntityIQ server. Admin dashboard: total/weekly usage, export/delete by date range |
| bwg-speed-sitescout dependency | Soft — `class_exists()` detection, bundled stubs in `includes/fallbacks/` |
| Email provider | wp_mail default. Admin UI to switch to SendGrid or Postmark |
| Meta Ad Library API token | EntityIQ `.env` only — see `docs/ARCHITECTURE.md` for full env template |

---

## EntityIQ Setup Instructions

*For the agent building the EntityIQ extension in the other repository.*

The EntityIQ extension adds these files to the existing `entityiq-vone` service:

```
entityiq-vone/
├── routes/ads.js              ← Register on the Express app
└── lib/
    ├── ad-scraper.js
    ├── meta-ad-library.js
    ├── google-transparency.js
    ├── screenshot.js
    ├── vision-compliance.js
    └── pdf-report.js
```

**Routes to implement:**
```
POST   /ads/surface           Queue ad library fetch job, return 202 { job_id }
GET    /ads/surface/:jobId    Job status + partial results
POST   /ads/screenshot        Screenshot a URL (Playwright)
POST   /ads/vision            Claude vision compliance (stub for MVP)
POST   /ads/pdf               PDF generation (stub for MVP)
GET    /ads/storage/stats     Total + weekly storage breakdown
GET    /ads/storage/export    Download zip of screenshots by date range
DELETE /ads/storage           Delete screenshots by date range, webhook WP to null paths
```

**`.env` variables to add:**
```
META_AD_LIBRARY_TOKEN=<Meta developer app access token with ads_read>
META_AD_LIBRARY_FALLBACK=playwright
GOOGLE_ADS_TRANSPARENCY_KEY=<Google Cloud API key>
PLAYWRIGHT_HEADLESS=true
PLAYWRIGHT_CONCURRENCY=3
BWG_SCREENSHOT_DIR=/var/data/bwg-screenshots
BWG_WEBHOOK_SECRET=<openssl rand -hex 32>
BWG_WP_WEBHOOK_URL=https://your-wp-site.com/wp-json/bwg/v1/ai/entityiq-webhook
```

**Webhook signature (on every callback to WordPress):**
```js
const sig = 'sha256=' + crypto.createHmac('sha256', process.env.BWG_WEBHOOK_SECRET)
  .update(JSON.stringify(body) + timestamp)
  .digest('hex');
// Send headers: X-BWG-Signature, X-BWG-Timestamp
```

Full spec, job flow diagram, and storage dashboard requirements: `docs/ARCHITECTURE.md` and `docs/BUILD-PLAN.md` (Milestone 5).

---

## Dependency on bwg-speed-sitescout

When installed alongside `bwg-speed-sitescout`, this plugin reuses:
- `BWG_CPA_Discovery` — GBP matching, social detection, pixel/tag fingerprinting
- `BWG_SA_Scraper` — HTTP fetcher for landing page spider
- `BWG_SA_Module_PageSpeed` — Core Web Vitals (Phase 6)
- `BWG_Compliance` — HIPAA compliance checks
- `BWG_CPA_Rate_Limiter` — rate limiting

If running standalone, these classes are bundled as stubs in `includes/fallbacks/`. Detection via `class_exists()` — no hard dependency.

---

## Service Expansion Funnel

| Tier | Deliverable | Price |
|---|---|---|
| Free | Phase 1–2 public audit (URL entry) | Lead gen |
| Paid report | Compliance snapshot report (Phase 3) | $497–$997 |
| Full audit | All platforms, 5 reports, landing page spider | $2,500–$5,000 |
| Journey audit | Phase 7 admissions + call coaching | $7,500–$15,000 |
| Retained | Monthly managed services + intel | $3,000–$10,000/mo |

---

## Development

**Branch:** `claude/read-files-plan-build-yho2p`

**For AI agents continuing this build:**  
Read `CLAUDE.md` first — it contains the full context, build status, and a copy-paste prompt for new chat sessions.

**Build order:** Follow milestones in `docs/BUILD-PLAN.md`. Each milestone commits independently. After each commit, update the milestone checklist in `CLAUDE.md`.

**Security:** Every milestone must follow the security requirements in `docs/BUILD-PLAN.md` (Security Review section). A full security audit runs after M10 before any production deploy.
