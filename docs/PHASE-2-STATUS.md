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

- All four EntityIQ stub files (`google-transparency.js`, `ad-scraper.js`, `vision-compliance.js`, `pdf-report.js`) already define the return shape the WP plugin expects — implementing them is swap-in, not a new contract.
- No Phase 2 architectural decisions are locked yet in `docs/ARCHITECTURE.md`. Before starting any item above, a scope/architecture pass should happen first, per `CLAUDE.md`'s "Do not add new features without a documented spec change."
- Phase 6 (spider) and Phase 7 (admissions audit) have no build-plan milestones written yet — they'll need their own `docs/BUILD-PLAN.md` sections before implementation starts.
