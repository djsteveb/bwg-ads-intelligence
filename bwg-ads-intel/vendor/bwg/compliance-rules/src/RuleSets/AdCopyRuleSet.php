<?php

namespace BWG\ComplianceRules\RuleSets;

use BWG\ComplianceRules\PatternRule;
use BWG\ComplianceRules\RuleSet;

/**
 * The ad-copy compliance rule set -- ported verbatim from
 * bwg-ads-intelligence's BWG_AI_Compliance::$rules (its own TODO calls it
 * "12-rule", the table actually holds 15 across three severity tiers).
 * Every rule, pattern, and citation below is unchanged from the source;
 * only the representation moved from a raw array to PatternRule objects.
 */
final class AdCopyRuleSet
{
    public static function create(): RuleSet
    {
        return new RuleSet(self::rules());
    }

    /**
     * @return PatternRule[]
     */
    public static function rules(): array
    {
        return [
            // =================================================================
            // HIPAA / Legal -- high severity
            // =================================================================

            new PatternRule(
                'hipaa_outcome_guarantee',
                'high',
                'HIPAA / Legal',
                'Outcome guarantee for addiction treatment',
                'FTC Act § 5; HIPAA 45 CFR § 164; NAD Guidelines',
                '/\b(
                    100\s*%\s*(success|effective|recovery|cure|sobriety|sober|clean)
                    |guaranteed?\s+(sobriety|recovery|results?|cure|treatment|success)
                    |cure\s+(addiction|alcoholism|drug\s+abuse)
                    |permanently\s+(sober|clean|cured?)
                    |never\s+relapse\s+again
                    |addiction.free\s+for\s+(life|good|ever)
                )\b/ix'
            ),

            new PatternRule(
                'hipaa_patient_testimonial',
                'high',
                'HIPAA / Legal',
                'Patient testimonial that may disclose treatment status without consent',
                '42 CFR Part 2; HIPAA § 164.502',
                '/\b(
                    (real\s+)?(patient|client|resident)\s+(testimonial|story|review|result)
                    |before\s+(i|we|they|he|she)\s+(went|entered|started|tried|came|checked\s+in)\s+to\s+(treatment|rehab|detox|the\s+program|the\s+center|the\s+facility)
                    |i\s+was\s+(addicted|struggling\s+with|abusing|dependent\s+on)
                    |my\s+(addiction|recovery|sobriety)\s+story
                    |hear\s+from\s+our\s+(patients|clients|graduates|alumni)\b
                )\b/ix'
            ),

            new PatternRule(
                'hipaa_bait_availability',
                'high',
                'HIPAA / Legal',
                'Bait-and-switch availability claim ("beds available now")',
                'FTC Act § 5 (bait advertising); LegitScript Certification Standards § 4',
                '/\b(
                    beds?\s+available\s+(now|today|immediately|tonight)
                    |immediate\s+(admission|intake|enrollment|placement|bed)
                    |admit\s+(today|now|immediately|tonight)
                    |open\s+beds?\s*(now|today|available)?
                    |same[\s\-]day\s+(admission|intake|treatment|detox)
                    |walk[\s\-]in(s)?\s+(welcome|accepted|available)
                    |available\s+(now|today)\s+(for\s+)?(admission|treatment|intake|enrollment)
                )\b/ix'
            ),

            new PatternRule(
                'hipaa_cfr_part2_pattern',
                'high',
                'HIPAA / Legal',
                'Possible 42 CFR Part 2 disclosure — substance use + named individual',
                '42 CFR Part 2 (Confidentiality of Substance Use Disorder Patient Records)',
                '/\b(substance\s+use\s+disorder|drug\s+(abuse|dependency|use\s+disorder)|alcohol\s+(abuse|dependency|use\s+disorder)|opioid\s+(addiction|disorder|dependence|use\s+disorder))\b.{0,250}\b(name|patient|client|individual|person|resident|he|she|they)\b/is'
            ),

            new PatternRule(
                'hipaa_unlicensed_claim',
                'high',
                'HIPAA / Legal',
                'Medical or clinical authority claim without verifiable credentials',
                'FTC Act § 5; State medical practice acts',
                '/\b(
                    medically\s+(supervised|proven|approved|endorsed)\s+(detox|treatment|program|withdrawal)
                    |doctor[\s\-]supervised\s+(detox|withdrawal|treatment)
                    |clinically\s+proven\s+(treatment|program|method|approach)
                    |fda[\s\-]approved\s+(treatment|program|method|therapy)\b(?!\s+medication)
                )\b/ix'
            ),

            // =================================================================
            // Platform policy -- medium severity
            // =================================================================

            new PatternRule(
                'policy_before_after',
                'medium',
                'Platform policy',
                'Before/after comparison language prohibited by Meta and Google health ad policies',
                'Meta Advertising Policies — Health & Wellness; Google Ads Healthcare Policy',
                '/\b(
                    before\s+(treatment|rehab|detox|recovery)\s+(vs\.?|versus|compared\s+to)\s+after
                    |after\s+(treatment|rehab|detox|recovery)\s+(vs\.?|versus|compared\s+to)\s+before
                    |before\s+and\s+after\s+(treatment|rehab|recovery|sobriety|getting\s+(sober|clean))
                    |transformation\s+(story|journey)\s+(from|before|after)\s+(addiction|rehab|treatment)
                )\b/ix'
            ),

            new PatternRule(
                'policy_admissions_cta_no_disclaimer',
                'medium',
                'Platform policy',
                'Admissions CTA without "call for availability" or results-may-vary disclaimer',
                'Google Healthcare Advertising Policy; Meta Special Ad Category — Health',
                '/\b(
                    call\s+(now|today|us)\s+(for\s+)?(help|treatment|rehab|detox|admission|availability)
                    |get\s+(help|treatment|admitted)\s+(now|today|immediately|tonight)
                    |start\s+(treatment|recovery|rehab)\s+(now|today|immediately|tonight)
                    |enroll\s+(now|today|immediately)
                    |begin\s+(treatment|your\s+recovery)\s+(now|today|immediately)
                )\b/ix',
                null,
                '/\b(
                    call\s+for\s+availability
                    |results?\s+may\s+vary
                    |not\s+all\s+(patients?|individuals?)\s+qualify
                    |consult\s+(a\s+)?doctor
                    |individual\s+results?\s+(may\s+)?vary
                    |subject\s+to\s+availability
                )\b/ix'
            ),

            new PatternRule(
                'policy_insurance_guarantee',
                'medium',
                'Platform policy',
                'Insurance coverage guarantee or "free treatment" claim',
                'FTC Act § 5; CMS Anti-Kickback considerations; LegitScript Standards',
                '/\b(
                    insurance\s+covers?\s+(everything|all\s+costs?|100\s*%|fully|completely|the\s+entire\s+cost)
                    |fully?\s+covered\s+by\s+(your\s+)?insurance
                    |no\s+out[\s\-]of[\s\-]pocket\s+(cost|expense)
                    |(100\s*%\s+)?free\s+treatment
                    |treatment\s+at\s+no\s+(cost|charge)
                    |zero\s+(cost|copay|deductible)\s+treatment
                )\b/ix'
            ),

            new PatternRule(
                'policy_platform_cert_claim',
                'medium',
                'Platform policy',
                'Platform certification or partnership claim (requires LegitScript verification)',
                'Google Healthcare Advertising Policy — LegitScript certification required',
                '/\b(
                    google[\s\-](certified|approved|trusted\s+partner)
                    |meta[\s\-](certified|approved|partner)
                    |facebook[\s\-](certified|approved)
                    |bing[\s\-](certified|approved)
                )\b/ix'
            ),

            new PatternRule(
                'policy_personal_hardship_targeting',
                'medium',
                'Platform policy',
                'Personal hardship targeting language prohibited by Meta Special Ad Category rules',
                'Meta Special Ad Categories — Health; Meta Advertising Standards § 4',
                '/\b(
                    (struggling|suffering)\s+with\s+(addiction|alcohol|drugs?|opioids?|substance)
                    |feel\s+(hopeless|helpless|out\s+of\s+control)\s+(with\s+)?(addiction|alcohol|drugs?)
                    |hit\s+(rock\s+bottom|your\s+lowest)
                    |lose\s+(everything|your\s+(family|job|home))\s+(to\s+)?(addiction|alcohol|drugs?)
                    |loved\s+one\s+(battling|struggling\s+with|addicted\s+to)
                )\b/ix'
            ),

            // =================================================================
            // Best practice -- low severity
            // =================================================================

            new PatternRule(
                'bp_no_phone_number',
                'low',
                'Best practice',
                'No phone number in ad copy',
                'LegitScript Advertising Standards; NAATP Best Practices',
                null,
                '/(\+?1[\s.\-]?)?\(?\d{3}\)?[\s.\-]?\d{3}[\s.\-]?\d{4}/'
            ),

            new PatternRule(
                'bp_no_accreditation',
                'low',
                'Best practice',
                'No accreditation or licensure reference',
                'NAATP Best Practices; LegitScript Standards',
                null,
                '/\b(JCAHO|TJC|The\s+Joint\s+Commission|CARF|SAMHSA|NAATP|LegitScript|state[\s\-]licensed|licensed\s+by\s+the\s+state|accredited|Joint\s+Commission)\b/i'
            ),

            new PatternRule(
                'bp_no_insurance_mention',
                'low',
                'Best practice',
                'No mention of insurance or payment options',
                'NAATP Best Practices',
                null,
                '/\b(insurance|medicaid|medicare|private\s+pay|self[\s\-]pay|financing|payment\s+plan|sliding[\s\-]scale|most\s+insurance|verify\s+(your\s+)?insurance)\b/i'
            ),

            new PatternRule(
                'bp_excessive_urgency',
                'low',
                'Best practice',
                'Excessive urgency / scarcity language',
                'FTC Advertising Guidelines; Meta & Google ad quality guidelines',
                '/\b(
                    act\s+now
                    |limited[\s\-]time\s+(offer|only|deal)
                    |last\s+chance
                    |don\'?t\s+wait(\s+another\s+(day|minute|second))?
                    |hurry[\s,!]
                    |offer\s+expires?
                    |today\s+only
                    |spots?\s+(are\s+)?filling\s+up\s+fast
                    |only\s+\d+\s+spots?\s+(left|remaining|available)
                    |limited\s+(spots?|openings?|availability)\s+(left|remaining)
                )\b/ix'
            ),

            new PatternRule(
                'bp_no_contact_cta',
                'low',
                'Best practice',
                'No clear contact call-to-action',
                'NAATP Best Practices',
                null,
                '/\b(call|contact|visit|chat|reach\s+out|get\s+in\s+touch|speak\s+(with|to)|talk\s+(with|to)|learn\s+more|find\s+out|click|apply)\b/i'
            ),
        ];
    }
}
