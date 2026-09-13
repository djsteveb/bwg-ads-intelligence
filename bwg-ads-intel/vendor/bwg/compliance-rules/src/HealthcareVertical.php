<?php

namespace BWG\ComplianceRules;

/**
 * Known healthcare-marketing verticals a client's ad-copy rules can be
 * scoped to (see AdCopyRuleSet::forVertical()). Not an enum (this package
 * targets PHP 7.4+ callers) -- just named string constants plus a
 * discovery list for building a settings dropdown.
 */
final class HealthcareVertical
{
    public const ADDICTION_TREATMENT = 'addiction_treatment';
    public const GENERAL_HEALTHCARE = 'general_healthcare';
    public const MED_SPA = 'med_spa';
    public const THERAPY_PRACTICE = 'therapy_practice';
    public const DENTAL = 'dental';
    public const HOME_HEALTH = 'home_health';
    public const TELEHEALTH = 'telehealth';

    /**
     * @return array<string, string> vertical key => human-readable label
     */
    public static function labels(): array
    {
        return [
            self::ADDICTION_TREATMENT => 'Addiction / Substance Use Treatment',
            self::GENERAL_HEALTHCARE => 'General Healthcare',
            self::MED_SPA => 'Med Spa / Aesthetics',
            self::THERAPY_PRACTICE => 'Therapy / Mental Health Practice',
            self::DENTAL => 'Dental',
            self::HOME_HEALTH => 'Home Health',
            self::TELEHEALTH => 'Telehealth',
        ];
    }

    public static function isKnown(string $vertical): bool
    {
        return array_key_exists($vertical, self::labels());
    }
}
