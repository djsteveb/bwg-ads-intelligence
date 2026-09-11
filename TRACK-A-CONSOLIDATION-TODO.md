# Track A: healthcare-compliance consolidation TODOs

Context: prompted by Blee (AI-first marketing compliance platform, $27M raise).
Full scoping lives in `1-map-synposises`:
[HEALTHCARE-COMPLIANCE-CONSOLIDATION.md](https://github.com/djsteveb/1-map-synposises/blob/main/HEALTHCARE-COMPLIANCE-CONSOLIDATION.md).

"Track A" is the cheap, ship-now consolidation of what already exists across
this repo (`BWG_AI_Compliance`), `bwg-comp-pl-one` (`BWG_Compliance`), and
`BWG-Ads-Acount-Audit` (`BWG_MAA`) into one coherent healthcare-marketing
compliance offering — as distinct from "Track B," a new standalone
pre-publish-review gateway service (PRD to be written separately) that will
eventually call into this plugin's checks rather than duplicate them.

## TODOs for this repo

- [ ] **Start actually publishing to the shared cache.** This plugin joined
  the suite bridge (copies `bwg-suite-bridge.php`, consumes the shared
  `google_places_api_key`) but as of now has **zero `bwg_cache_set()` calls
  anywhere in the codebase** — ad-surface discovery and the M13 Claude-vision
  compliance results stay local to this plugin's own tables. Publish at least
  `ad_audit_score`/vision-compliance findings per domain so siblings
  (`bwg-comp-pl-one`, a future Track B gateway) can read them without
  re-running the analysis.
- [ ] **Resolve the duplicate vision/landing-page engine with `BWG_MAA`.**
  `BWG-Ads-Acount-Audit`'s `class-bwg-maa-vision.php` and
  `class-bwg-maa-landing-page.php` were built independently, days apart, doing
  essentially the same thing as this repo's own M13 (Claude-vision ad-creative
  compliance) and M12 (landing-page spider). Before either engine's output
  gets consumed by a consolidated product, decide which is canonical — don't
  build a third pass at the same problem, and don't feed both into downstream
  consumers as if they were independent signals.
- [ ] **Extract the 12-rule `BWG_AI_Compliance` ad-copy engine into the shared
  rule package** alongside `bwg-comp-pl-one`'s site-content compliance rules
  (see that repo's own TODO doc). One versioned rule source instead of two.
- [ ] **Generalize past addiction treatment** if the broader healthcare-
  marketing positioning (med spas, therapy, dental, telehealth) is the goal —
  today's rules (bait-availability, "beds available now", 42 CFR Part 2
  patterns) are written specifically for treatment-center ad copy.
- [ ] Keep the REST layer (`class-bwg-ai-rest.php`) in mind as the eventual
  integration point for Track B's gateway service, same as
  `BWG-Ads-Acount-Audit`'s `bwg-maa/v1` pattern.
