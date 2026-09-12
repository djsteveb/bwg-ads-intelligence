<?php

namespace BWG\ComplianceRules;

/**
 * A single compliance rule result. Framework-independent -- each consuming
 * plugin's own adapter maps this back to whatever array shape its existing
 * downstream code (storage, admin UI, reports) already expects, so no
 * shared shape has to satisfy both plugins' historical formats directly.
 */
final class Finding
{
    /** @var string Stable, unique rule identifier. */
    public $ruleId;

    /** @var string 'high'|'medium'|'low' */
    public $severity;

    /** @var string Human-readable rule category/group. */
    public $category;

    /** @var string Primary description of what was found. */
    public $description;

    /** @var string|null Regulation/policy citation, when the rule has one. */
    public $citation;

    /** @var string|null Surrounding text context around the match, when applicable. */
    public $excerpt;

    /** @var int|null 0-100 confidence score, for rules that produce one. */
    public $confidence;

    /**
     * Anything else a specific rule needs to carry that isn't common
     * across rules (e.g. a title distinct from description, a
     * recommendation, raw per-line match data, confidence breakdowns).
     * Each plugin's adapter reads whatever keys its own rule needs.
     *
     * @var array<string, mixed>
     */
    public $details;

    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        string $ruleId,
        string $severity,
        string $category,
        string $description,
        ?string $citation = null,
        ?string $excerpt = null,
        ?int $confidence = null,
        array $details = []
    ) {
        $this->ruleId = $ruleId;
        $this->severity = $severity;
        $this->category = $category;
        $this->description = $description;
        $this->citation = $citation;
        $this->excerpt = $excerpt;
        $this->confidence = $confidence;
        $this->details = $details;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'rule_id' => $this->ruleId,
            'severity' => $this->severity,
            'category' => $this->category,
            'description' => $this->description,
            'citation' => $this->citation,
            'excerpt' => $this->excerpt,
            'confidence' => $this->confidence,
            'details' => $this->details,
        ];
    }
}
