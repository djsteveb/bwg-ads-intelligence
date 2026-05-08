# BWG Ads Intelligence System

Multi-platform ads audit, compliance analysis, and lead conversion engine for treatment center advertisers (TCA / Better Web Group).

## What This Is

A 7-phase audit platform that starts with a URL and expands into a full patient journey and admissions performance review — converting cold prospects into managed-service clients.

**Entry point:** Public-facing URL form (free lead gen)  
**Exit point:** Retained managed services ($3k–$10k/mo)

## Repository Layout

```
bwg-ads-intelligence/
├── ads-intelligence-system.html     ← Product blueprint (rendered design doc)
├── ads-intelligence-prd.md          ← Technical PRD (architecture, DB, endpoints)
├── bwg-ads-intel/                   ← WordPress plugin (to be built)
│   ├── bwg-ads-intel.php            ← Plugin bootstrap
│   ├── includes/                    ← Shared utilities
│   ├── ads-intel/                   ← Core audit module
│   │   ├── class-bwg-ai-session.php
│   │   ├── class-bwg-ai-discovery.php
│   │   ├── class-bwg-ai-rest.php
│   │   ├── class-bwg-ai-compliance.php
│   │   ├── class-bwg-ai-report.php
│   │   └── assets/
│   └── admin/
└── entityiq-extension/              ← Node.js additions to EntityIQ service
    ├── routes/ads.js                ← Ad library fetch routes
    ├── lib/ad-scraper.js            ← Meta / Google / LinkedIn scrapers
    ├── lib/screenshot.js            ← Playwright screenshot capture
    ├── lib/vision-compliance.js     ← Claude vision compliance analysis
    └── lib/pdf-report.js            ← Puppeteer multi-audience PDF export
```

## Architecture Overview

The system splits across two codebases:

| Layer | Technology | Handles |
|---|---|---|
| WordPress Plugin | PHP | User form, sessions, email sequences, admin, reports |
| EntityIQ extension | Node.js | Ad library scraping, screenshots, AI vision, PDF export |

The WP plugin fires async REST calls to EntityIQ for heavy data acquisition jobs; EntityIQ calls back via webhook when complete.

## Dependency on bwg-speed-sitescout

When installed alongside `bwg-speed-sitescout`, this plugin reuses:
- `BWG_CPA_Discovery` — GBP matching, social detection, pixel/tag fingerprinting
- `BWG_SA_Scraper` — HTTP fetcher for landing page spider
- `BWG_SA_Module_PageSpeed` — Core Web Vitals (Phase 6)
- `BWG_Compliance` — HIPAA compliance checks
- `BWG_CPA_Rate_Limiter` — rate limiting

If running standalone, these classes will be bundled in `includes/`.

## Related Docs

- `ads-intelligence-system.html` — full product blueprint with all 7 phases, report formats, and s
