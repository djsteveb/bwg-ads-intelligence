<?php

namespace BWG\ComplianceRules\SiteContent;

use BWG\ComplianceRules\Finding;
use BWG\ComplianceRules\Rule;
use BWG\ComplianceRules\Text;

/**
 * Ported from bwg-comp-pl-one's BWG_Compliance_Scanner::check_hipaa_notice().
 * Presence/absence keyword scan for HIPAA-related language -- it does not
 * assess whether a found notice is actually complete, only whether HIPAA
 * language appears at all (documented limitation carried over unchanged).
 */
final class HipaaNoticeRule implements Rule
{
    private const PATTERNS = [
        'HIPAA' => '/HIPAA/i',
        'Health Insurance Portability' => '/health insurance portability/i',
        'Protected Health Information' => '/protected health information/i',
        'PHI' => '/\bPHI\b/',
    ];

    public function evaluate(string $content, array $context = []): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $content);
        $allMatches = [];

        foreach (self::PATTERNS as $label => $pattern) {
            foreach ($lines as $lineNum => $line) {
                if (!preg_match($pattern, $line)) {
                    continue;
                }

                $contextBefore = $lineNum > 0 ? trim($lines[$lineNum - 1]) : '';
                $contextMatch = trim($line);
                $contextAfter = $lineNum < count($lines) - 1 ? trim($lines[$lineNum + 1]) : '';

                $contextBefore = Text::stripAllTags($contextBefore);
                $contextMatch = Text::stripAllTags($contextMatch);
                $contextAfter = Text::stripAllTags($contextAfter);

                // Skip empty or very short matches (likely just tag fragments).
                if (strlen($contextMatch) < 3) {
                    continue;
                }

                $maxLen = 200;
                if (strlen($contextBefore) > $maxLen) {
                    $contextBefore = substr($contextBefore, 0, $maxLen) . '...';
                }
                if (strlen($contextMatch) > $maxLen) {
                    $contextMatch = substr($contextMatch, 0, $maxLen) . '...';
                }
                if (strlen($contextAfter) > $maxLen) {
                    $contextAfter = substr($contextAfter, 0, $maxLen) . '...';
                }

                $allMatches[] = [
                    'keyword' => $label,
                    'line_number' => $lineNum + 1,
                    'context_before' => $contextBefore,
                    'context_match' => $contextMatch,
                    'context_after' => $contextAfter,
                ];
            }
        }

        if (empty($allMatches)) {
            return [
                new Finding(
                    'hipaa_notice.missing',
                    'high',
                    'privacy',
                    'No HIPAA compliance information was found on the website. Visitors should be informed about HIPAA compliance measures.',
                    null,
                    null,
                    null,
                    [
                        'title' => 'Missing HIPAA Compliance Notice',
                        'recommendation' => 'Add a visible notice about your HIPAA compliance status. Include information about how you protect patient privacy and the security measures in place.',
                    ]
                ),
            ];
        }

        $matchCount = count($allMatches);
        $contextDetails = '';
        foreach ($allMatches as $index => $match) {
            $contextDetails .= "\n\n--- Match " . ($index + 1) . ': "' . $match['keyword'] . '" found (HTML line ' . $match['line_number'] . ') ---';
            if (!empty($match['context_before'])) {
                $contextDetails .= "\n  Before: " . $match['context_before'];
            }
            $contextDetails .= "\n  >> " . $match['context_match'];
            if (!empty($match['context_after'])) {
                $contextDetails .= "\n  After:  " . $match['context_after'];
            }
        }

        $description = 'HIPAA-related language was detected on the website (' . $matchCount . ' instance' . ($matchCount > 1 ? 's' : '') . ' found). A manual review is recommended to verify the notice is complete, accurate, and meets compliance requirements. Simply mentioning HIPAA does not guarantee the notice contains all required elements.' . $contextDetails;

        return [
            new Finding(
                'hipaa_notice.manual_review_required',
                'medium',
                'privacy',
                $description,
                null,
                null,
                null,
                [
                    'title' => 'HIPAA Compliance Notice Requires Manual Review',
                    'recommendation' => 'Manually review each instance where HIPAA-related language appears on the website. Verify the notice includes: (1) how protected health information (PHI) is collected and used, (2) patient rights regarding their health information, (3) the organization\'s privacy and security measures, and (4) contact information for privacy-related inquiries. Consult with legal counsel to ensure the notice meets current HIPAA requirements.',
                    'match_count' => $matchCount,
                    'matches' => $allMatches,
                ]
            ),
        ];
    }
}
