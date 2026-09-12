<?php

namespace BWG\ComplianceRules;

/**
 * A collection of rules evaluated together. Merges every rule's findings
 * and sorts them high -> medium -> low, matching the sort order both
 * source plugins already applied to their own flags/violations.
 */
final class RuleSet
{
    /** @var Rule[] */
    private $rules;

    /**
     * @param Rule[] $rules
     */
    public function __construct(array $rules)
    {
        $this->rules = $rules;
    }

    /**
     * @param array<string, mixed> $context
     * @param string[] $skipRuleIds Rule ids to skip -- e.g. a sibling
     *   plugin already reported them (matches BWG_AI_Compliance's own
     *   "don't duplicate a rule the sibling plugin already flagged" logic).
     * @return Finding[]
     */
    public function evaluate(string $content, array $context = [], array $skipRuleIds = []): array
    {
        $findings = [];
        foreach ($this->rules as $rule) {
            foreach ($rule->evaluate($content, $context) as $finding) {
                if (in_array($finding->ruleId, $skipRuleIds, true)) {
                    continue;
                }
                $findings[] = $finding;
            }
        }

        $order = ['high' => 0, 'medium' => 1, 'low' => 2];
        usort($findings, static function (Finding $a, Finding $b) use ($order) {
            return ($order[$a->severity] ?? 9) <=> ($order[$b->severity] ?? 9);
        });

        return $findings;
    }

    /**
     * @return Rule[]
     */
    public function rules(): array
    {
        return $this->rules;
    }
}
