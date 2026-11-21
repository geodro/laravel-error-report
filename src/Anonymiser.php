<?php

namespace Geodro\ErrorReport;

// Masks anything in free text that could trace a user or a session.
// The report gets pasted into an agent, so this errs on the side of masking.
class Anonymiser
{
    private const PATTERNS = [
        '/\beyJ[\w-]+\.[\w-]+\.[\w-]+/' => '[jwt]',
        '/\bBearer\s+\S+/i' => 'Bearer [token]',
        '/\?[^\s\'"?#]*=[^\s\'"#]*/' => '?[query]',
        '/[\w.+-]+@[\w-]+(\.[\w-]+)+/' => '[email]',
        '/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i' => '[uuid]',
        '/\b(?:\d{1,3}\.){3}\d{1,3}\b/' => '[ip]',
        '/\b(?:[0-9a-f]{1,4}:){4,7}[0-9a-f]{1,4}\b/i' => '[ip]',
        '/\b(?=[\w-]*\d)(?=[\w-]*[a-z])[\w-]{12,}\b/i' => '[token]',
        '/\b\d[\d -]{10,}\d\b/' => '[number]',
        // Ids, phone numbers, order numbers. SQLSTATE codes stay readable.
        '/(?<!SQLSTATE\[)\b\d{4,}\b/' => '[number]',
    ];

    public static function text(string $text): string
    {
        return preg_replace(array_keys(self::PATTERNS), array_values(self::PATTERNS), $text) ?? '[unreadable]';
    }

    // Driver messages quote the offending values ("Duplicate entry 'x' for key", Postgres
    // "Key (col)=(value)"), so drop every quoted or bracketed value.
    public static function driverMessage(string $text): string
    {
        $text = preg_replace(["/'[^']*'|\"[^\"]*\"/", '/\(([^()]*)\)=\([^()]*\)/'], ["'?'", '($1)=(?)'], $text) ?? '';

        return self::text($text);
    }

    // Placeholders are kept; inline string literals are dropped. Double quotes are identifiers in Postgres.
    public static function sql(string $sql): string
    {
        return self::text(preg_replace("/'(?:[^']|'')*'/", '?', $sql) ?? '');
    }
}
