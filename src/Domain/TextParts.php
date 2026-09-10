<?php
declare(strict_types=1);

namespace Broadcast\Domain;

final class TextParts
{
    /** Keep every character, including paragraph separators; count conservatively in UTF-16 units. */
    public static function split(string $text): array
    {
        $parts = [];
        while ($text !== '') {
            $candidate = mb_substr($text, 0, 4096);
            while (strlen(mb_convert_encoding($candidate, 'UTF-16LE', 'UTF-8')) > 8192) {
                $candidate = mb_substr($candidate, 0, mb_strlen($candidate) - 1);
            }
            if (mb_strlen($candidate) < mb_strlen($text)) {
                $paragraph = mb_strrpos($candidate, "\n\n");
                if ($paragraph !== false && $paragraph > 0) { $candidate = mb_substr($candidate, 0, $paragraph + 2); }
            }
            $parts[] = $candidate;
            $text = mb_substr($text, mb_strlen($candidate));
        }
        return $parts;
    }
}
