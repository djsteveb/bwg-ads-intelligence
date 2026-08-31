# Phase 2 Scope — Status

This doc tracks the "Phase 2" scope tier: everything intentionally deferred out of MVP. It exists because the codebase overloads the word "phase" two ways, which is easy to conflate:

1. **Audit workflow phases (1–7)** — the numbered steps of the audit itself (Discovery, Ad Surface, Compliance, Verification, Access Funnel, Landing Page Spider, Journey/Admissions Audit). Every one of these has an MVP scope and a larger Phase 2 scope.
2. **"Phase 2" the scope tier** — the set of features explicitly cut from MVP across those workflow phases, to be built later. That's what this doc covers.

**Status as of 2026-08-31: MVP (M0–M10 + security review) is complete and feature-frozen. Nothing in the Phase 2 scope tier below has been started.** Current build focus is QA / staging deployment of the MVP, not Phase 2 work — see `CLAUDE.md` build status.

---

## Deferred items

| Item | Belongs to workflow phase | Current state | Where it lives |
|---|---|---|---|
| Google Ads Transparency Center scraping | Phase 2 — Ad Surface | Stub, returns `[]` | `entityiq-extension/lib/google-transparency.js` |
| LinkedIn / TikTok / Bing ad scraping | Phase 2 — Ad Surface | Stub, returns `[]` | `entityiq-extension/lib/ad-scraper.js` |
| Claude vision image compliance | Phase 3 — Compliance | Stub, returns `{ flags: [], analyzed: false, reason: 'deferred' }` | `entityiq-extension/lib/vision-compliance.js` |
| PDF report export | Report generation | Stub, returns `{ pdf_path: null, reason: 'deferred' }` | `entityiq-extension/lib/pdf-report.js` |
| 4 additional audience reports (CMO, Compliance/Legal, Agency Internal, Admissions Director) | Report generation | Not built — only Executive report exists, in-browser only | n/a (report templates not yet written) |
| Phase 6 — Landing Page Spider | Workflow phase 6 | Not built | `bwg-ads-intel/ads-intel/class-bwg-ai-spider.php` does not exist |
| Phase 7 — Journey & Admissions Audit (call quality, CRM integration, attribution modeling) | Workflow phase 7 | Not built — no spec beyond PRD mention | n/a |
| Chrome extension | Cross-cutting | Not built | n/a |
| Competitor surveillance / continuous monitoring | Cross-cutting | Not built | n/a |
| "Ads preview" drip email (fires on Phase 2 Ad Surface completion) | Email layer | Not built — depends on Google/LinkedIn/TikTok/Bing surface above | `readme.md` §Email Sequences |

## What MVP already covers, for contrast

- **Phase 1 Discovery** — full (GBP match, social, pixels, WHOIS/RDAP, LegitScript, NAP consistency)
- **Phase 2 Ad Surface** — Meta Ad Library only (API + scraper fallback)
- **Phase 3 Compliance** — text/rule-engine only (HIPAA phrasing, outcome guarantees, 42 CFR Part 2 patterns, platform policy) — no image analysis
- **Phase 4 Verification & Expansion** — screenshot gallery + confirm UI, multi-account discovery
- **Phase 5 Access Request Funnel** — full (instruction cards, email templates, CSV upload, follow-up drip)
- **Reports** — Executive report only, in-browser, no PDF
- **Admin** — session list/detail, settings, storage dashboard

## Notes for whoever picks up Phase 2

- All four EntityIQ stub files (`google-transparency.js`, `ad-scraper.js`, `vision-compliance.js`, `pdf-report.js`) already define the return shape the WP plugin expects — implementing them is swap-in, not a new contract, *if* something implements them (see below — nothing currently does).
- No Phase 2 architectural decisions are locked yet in `docs/ARCHITECTURE.md`. Before starting any item above, a scope/architecture pass should happen first, per `CLAUDE.md`'s "Do not add new features without a documented spec change."
- Phase 6 (spider) and Phase 7 (admissions audit) have no build-plan milestones written yet — they'll need their own `docs/BUILD-PLAN.md` sections before implementation starts.

## Investigation: does the real EntityIQ repo (`djsteveb/entityiq`) plan or contain any of this?

Checked 2026-08-31 by cloning `djsteveb/entityiq` directly and searching `TODO.md`, `HISTORY.md`, `BWG_SUITE_HANDOFF.md`, `README.md`, and every file under `lib/` and `routes/`.

**No.** There is no `PLAN.md` in that repo, and zero mentions anywhere of Google Ads Transparency, Meta/LinkedIn/TikTok/Bing ad scraping, or vision-based ad compliance. This is not a "not built yet, but planned" situation — the real EntityIQ has diverged into a different product:

**EntityIQ is a Local SEO schema generator + entity intelligence platform**, not an ad-surface scraper. Its actual build phases (1 through 4g per its `TODO.md`) cover schema/JSON-LD generation and validation, Google Business Profile OAuth audits, BrightLocal citation checks, DataForSEO local-pack rank tracking, Google Knowledge Graph / Wikidata entity resolution, and its own PDF/slides report output — none of it ad-related. The `entityiq-extension/` spec in *this* repo describes routes and stub files (`routes/ads.js`, `lib/google-transparency.js`, `lib/vision-compliance.js`, `lib/pdf-report.js`, etc.) that were never carried over into the real EntityIQ codebase. The roadmaps drifted apart at some point after the original cross-repo plan was written.

### Sharing that does exist between the two repos

Credential-sharing only, no feature-sharing:
- EntityIQ's `google_places_api_key` shared-credential resolver (`bwg_suite_find_shared_credential()`) falls back to Ads Intelligence's `bwg_ai_google_places_key` (and SpeedScout's, and Webring's) when EntityIQ's own key isn't configured — the mirror image of what `CLAUDE.md` already documents from the Ads Intelligence side.
- `BWG_SUITE_HANDOFF.md` independently flags this same key as a "known duplicate credential" from EntityIQ's side.
- EntityIQ's shared cache table (`wp_bwg_data_cache`) data types are all SEO/entity-related (`schema_score`, `entity_ids`, `gbp_audit`, `brightlocal_citations`, `dataforseo_local_pack`, `social_presence`, `site_metadata`) — nothing ad-surface related is written or read by either plugin.

### Other EntityIQ capabilities that could be useful (not for the Phase 2 items above, but worth knowing about)

- `lib/scraper.js` — an SSRF-guarded, Cheerio-based website scraper extracting business name/phone/address/hours/social links via schema.org itemprops + OG tags. Overlaps conceptually with this plugin's Phase 1 Discovery (NAP consistency, social discovery) — worth comparing logic against `class-bwg-ai-discovery.php`, though it's Node.js and would need porting, not copying, to reuse in this PHP plugin.
- `lib/pdf.js` — a working Puppeteer `htmlToPdf()` helper, already deployed inside EntityIQ's Node service. A reasonable template for this plugin's eventual PDF export, but only usable if EntityIQ (or a similar Node service you control) actually exposes a route for it — nothing does today.
- `lib/claude.js` — existing `@anthropic-ai/sdk` usage pattern in EntityIQ, useful as a reference for eventually wiring Claude vision compliance, though EntityIQ doesn't do image/vision analysis today.

### Can any of this be pulled into the plugin to drop the EntityIQ dependency?

No — there's no ad-scraping, vision-compliance, or ads-PDF code anywhere in EntityIQ to pull in, so there's nothing to vendor. Dropping the cross-system dependency isn't a copy-paste job either way; it's new work regardless of which side builds it. The actual choice is:

1. **Keep depending on EntityIQ** — but its real roadmap doesn't include ad-surface work today, so this means *adding* new routes to EntityIQ (new work there, not something "already there" to wire up).
2. **Build it self-contained inside `bwg-ads-intel`** — implement Meta/Google/LinkedIn/TikTok/Bing scraping and PDF generation directly in PHP (or a small dedicated Node service owned by this plugin), skipping EntityIQ's Local-SEO-focused service entirely.

This is a scope decision, not a technical one — flagging it for whoever owns both repos rather than assuming a direction here.

## Decision (2026-08-31): build self-contained, not in EntityIQ

Also checked the rest of the BWG suite (via `1-map-synposises/CAPABILITIES.md`, the cross-repo capability/conflict audit) for any sibling plugin that already has ad-library scraping, vision compliance, or ad-PDF generation to borrow from. **None exists anywhere in the suite.** The closest-sounding repos turned out to be unrelated in purpose or unusable in stack:

- `-BWG-AdAutomate-AI-Orchestrator-V2B` — generates new ads (Gemini + fal.ai), doesn't audit existing ones. Confirmed standalone, not a WordPress plugin.
- `BWG-Marketing-Intelligence-Connector` / `Marketing-Data-Clean-Room-Hub` — integrate with Meta/Google/Microsoft Ads APIs, but for the advertiser's **own** account spend/attribution data via OAuth, not public ad-transparency-library scraping of arbitrary businesses. Both are Python (FastAPI), not WordPress/PHP.
- `bwg-auto-mailing-systems` / `webring-plus-v7` — produce a `screenshot_url` shared-cache field, but for general site screenshots, not ad creatives or ad-library pages.
- No plugin in the suite uses Claude/Anthropic vision, or any headless-browser (Puppeteer/Playwright) capability at all — EntityIQ's only Puppeteer use is HTML→PDF, not screenshotting.

**Decision: build all of it inside `bwg-ads-intel` itself**, calling external commercial APIs directly (Meta Graph API, a headless-render-as-a-service for JS-rendered ad libraries, the Anthropic API for vision, a pure-PHP PDF library) rather than depending on EntityIQ or any other sibling plugin. Full technical plan: `docs/PHASE-2-BUILD-PLAN.md`.

That investigation also surfaced a bigger problem than originally scoped: **the current MVP Meta Ad Library integration doesn't actually work** — it calls EntityIQ endpoints (`/ads/surface`) that don't exist anywhere in the real `djsteveb/entityiq` repo. See `docs/PHASE-2-BUILD-PLAN.md` for the fix, which is now the top priority, ahead of any net-new Phase 2 feature.

**Update (2026-08-31):** a second sibling repo, `djsteveb/BWG-Ads-Acount-Audit` (plugin slug `bwg-meta-audit`), turned out to be more built than the last suite audit recorded — it has working, tested PHP code for Claude vision-based ad-creative analysis and a zero-dependency browser print-to-PDF export, both directly relevant to Phase 2's vision-compliance and PDF-export items. `docs/PHASE-2-BUILD-PLAN.md` now points to those as ported references rather than designing them from scratch.
