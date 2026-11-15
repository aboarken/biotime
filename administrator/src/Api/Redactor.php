<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Administrator\Api;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Removes secrets from text before it is logged or displayed.
 */
final class Redactor
{
    /**
     * @param  list<string>  $secrets  Exact values to remove (password, tokens)
     */
    public static function scrub(string $text, array $secrets = [], int $maxLength = 300): string
    {
        foreach ($secrets as $secret) {
            if ($secret !== '') {
                $text = str_ireplace($secret, '[redacted]', $text);
            }
        }

        // "Authorization: JWT xyz" / "Authorization: Token xyz" / "Bearer xyz".
        $text = preg_replace('/\b(JWT|Token|Bearer)\s+[A-Za-z0-9._~+\/=-]{8,}/', '$1 [redacted]', $text) ?? '';

        // JSON or parameter style "token": "xyz", password=xyz, authorization: xyz.
        $text = preg_replace('/\b(token|password|passwd|authorization|secret)\b(["\']?\s*[:=]\s*["\']?)[^\s"\'&,;}]+/i', '$1$2[redacted]', $text) ?? '';

        // Anything that still looks like a JWT (three base64url parts).
        $text = preg_replace('/\beyJ[A-Za-z0-9_-]{5,}\.[A-Za-z0-9_-]{5,}\.[A-Za-z0-9_-]*/', '[redacted]', $text) ?? '';

        $text = trim(preg_replace('/\s+/', ' ', strip_tags($text)) ?? '');

        if (mb_strlen($text) > $maxLength) {
            $text = mb_substr($text, 0, $maxLength) . '…';
        }

        return $text;
    }
}
