<?php

namespace BWG\ComplianceRules;

/**
 * Declarative regex-based rule -- ported verbatim from
 * bwg-ads-intelligence's BWG_AI_Compliance::analyze_ad_copy() rule table
 * and evaluation loop. Covers every "pattern"/"absent"/"negate" style rule
 * (the ad-copy engine's full rule set is exactly this shape).
 */
final class PatternRule implements Rule
{
    private $id;
    private $severity;
    private $category;
    private $description;
    private $citation;
    private $pattern;
    private $absent;
    private $negate;
    private $minLengthForAbsent;

    public function __construct(
        string $id,
        string $severity,
        string $category,
        string $description,
        string $citation,
        ?string $pattern = null,
        ?string $absent = null,
        ?string $negate = null,
        int $minLengthForAbsent = 40
    ) {
        $this->id = $id;
        $this->severity = $severity;
        $this->category = $category;
        $this->description = $description;
        $this->citation = $citation;
        $this->pattern = $pattern;
        $this->absent = $absent;
        $this->negate = $negate;
        $this->minLengthForAbsent = $minLengthForAbsent;
    }

    public function evaluate(string $content, array $context = []): array
    {
        if (null !== $this->absent) {
            // "Absent" rules only fire on substantive copy -- very short
            // text (e.g. image-only ads) shouldn't generate noise.
            if (mb_strlen(trim($content)) < $this->minLengthForAbsent) {
                return [];
            }
            if (preg_match($this->absent, $content)) {
                return [];
            }
            return [new Finding($this->id, $this->severity, $this->category, $this->description, $this->citation)];
        }

        if (null !== $this->pattern && preg_match($this->pattern, $content, $m)) {
            // Negation: presence of a disclaimer suppresses the flag.
            if (null !== $this->negate && preg_match($this->negate, $content)) {
                return [];
            }
            $excerpt = Text::excerptAround($content, $m[0]);
            return [new Finding($this->id, $this->severity, $this->category, $this->description, $this->citation, $excerpt)];
        }

        return [];
    }
}
