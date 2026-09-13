<?php

namespace BWG\ComplianceRules\Tests;

use BWG\ComplianceRules\HealthcareVertical;
use BWG\ComplianceRules\RuleSets\AdCopyRuleSet;
use PHPUnit\Framework\TestCase;

final class AdCopyGenericHealthcareTest extends TestCase
{
    private function ruleIds(string $adCopy, string $vertical): array
    {
        $findings = AdCopyRuleSet::forVertical($vertical)->evaluate($adCopy);
        return array_map(static function ($f) {
            return $f->ruleId;
        }, $findings);
    }

    public function test_addiction_treatment_vertical_matches_default_create(): void
    {
        $copy = 'Beds available now, call today.';
        $this->assertSame(
            $this->ruleIds($copy, HealthcareVertical::ADDICTION_TREATMENT),
            array_map(static function ($f) { return $f->ruleId; }, AdCopyRuleSet::create()->evaluate($copy))
        );
    }

    public function test_generic_outcome_guarantee_fires_for_med_spa(): void
    {
        $this->assertContains(
            'generic_outcome_guarantee',
            $this->ruleIds('Our laser treatment guarantees results for every client.', HealthcareVertical::MED_SPA)
        );
    }

    public function test_generic_patient_testimonial_consent_fires_for_dental(): void
    {
        $this->assertContains(
            'generic_patient_testimonial_consent',
            $this->ruleIds('Read our patient testimonial about a pain-free root canal.', HealthcareVertical::DENTAL)
        );
    }

    public function test_generic_unlicensed_claim_fires_for_telehealth(): void
    {
        $this->assertContains(
            'generic_unlicensed_claim',
            $this->ruleIds('Our clinically proven treatment program works fast.', HealthcareVertical::TELEHEALTH)
        );
    }

    public function test_generic_before_after_fires_for_general_healthcare(): void
    {
        $this->assertContains(
            'generic_before_after',
            $this->ruleIds('See our before and after treatment photos.', HealthcareVertical::GENERAL_HEALTHCARE)
        );
    }

    public function test_addiction_specific_rules_never_fire_in_generic_set(): void
    {
        $copy = 'Beds available now, call today for immediate admission.';
        $findings = $this->ruleIds($copy, HealthcareVertical::MED_SPA);
        $this->assertNotContains('hipaa_bait_availability', $findings);
        $this->assertNotContains('policy_admissions_cta_no_disclaimer', $findings);
    }

    public function test_cfr_part2_pattern_never_fires_in_generic_set(): void
    {
        $copy = 'John struggled with substance use disorder before he found our program and the patient recovered fully.';
        $this->assertNotContains('hipaa_cfr_part2_pattern', $this->ruleIds($copy, HealthcareVertical::THERAPY_PRACTICE));
    }

    public function test_generic_accreditation_does_not_reference_addiction_specific_bodies(): void
    {
        // The generic best-practice accreditation rule fires on "no reference to
        // licensure/accreditation found" -- absence of SAMHSA/CARF/NAATP (which
        // aren't relevant outside addiction treatment) must not itself cause a
        // false positive/negative here.
        $copy = 'Our board-certified providers offer the best care in town.';
        $this->assertNotContains('generic_no_accreditation', $this->ruleIds($copy, HealthcareVertical::HOME_HEALTH));
    }

    public function test_unknown_vertical_falls_back_to_generic_rules(): void
    {
        $this->assertSame(
            $this->ruleIds('Our clinically proven treatment program works fast.', 'some_future_vertical'),
            $this->ruleIds('Our clinically proven treatment program works fast.', HealthcareVertical::GENERAL_HEALTHCARE)
        );
    }

    public function test_healthcare_vertical_labels_cover_every_known_vertical(): void
    {
        $labels = HealthcareVertical::labels();
        $this->assertArrayHasKey(HealthcareVertical::ADDICTION_TREATMENT, $labels);
        $this->assertArrayHasKey(HealthcareVertical::MED_SPA, $labels);
        $this->assertTrue(HealthcareVertical::isKnown(HealthcareVertical::DENTAL));
        $this->assertFalse(HealthcareVertical::isKnown('not_a_real_vertical'));
    }
}
