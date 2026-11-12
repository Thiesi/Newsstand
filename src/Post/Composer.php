<?php

declare(strict_types=1);

namespace Newsstand\Post;

use Newsstand\Nntp\GroupName;

/**
 * Builds the article Newsstand posts: UTF-8 text/plain, wrapped at 72 characters, with correct
 * References, Followup-To and RFC 2047-encoded headers. Also prepares quoted replies.
 */
final class Composer
{
    public const WIDTH = 72;
    public const MAX_SUBJECT = 200;
    public const MAX_BODY_BYTES = 65536;

    /** "a, b c" → ['a', 'b', 'c'], lower-cased, duplicates removed. Invalid names are kept for the error message. */
    public static function parseGroups(string $value): array
    {
        $groups = preg_split('/[\s,]+/', strtolower(trim($value)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return array_values(array_unique($groups));
    }

    public static function replySubject(string $subject): string
    {
        $subject = trim($subject);
        return preg_match('/^re:/i', $subject) ? $subject : 'Re: ' . $subject;
    }

    /**
     * The quoted original for a reply: attribution line, "> " prefixes, signature dropped.
     * $who is the author's display name (or address); the page shows it obfuscated like everything else.
     */
    public static function quote(string $text, string $who): string
    {
        $text = str_replace("\r\n", "\n", $text);
        $sig = strpos($text, "\n-- \n");
        if ($sig !== false) {
            $text = substr($text, 0, $sig);
        } elseif (str_starts_with($text, "-- \n")) {
            $text = '';
        }
        $lines = explode("\n", rtrim($text));
        $quoted = array_map(static fn (string $l): string => $l === '' ? '>' : (str_starts_with($l, '>') ? '>' . $l : '> ' . $l), $lines);
        $who = trim($who) !== '' ? trim($who) : 'Someone';
        return "$who wrote:\n" . implode("\n", $quoted) . "\n\n";
    }

    /**
     * Normalises the body the user typed: LF line ends, no trailing spaces (except the "-- " signature
     * separator), and lines the user wrote (not quotes) wrapped at 72 characters without breaking words.
     */
    public static function wrap(string $body): string
    {
        $body = str_replace(["\r\n", "\r"], "\n", $body);
        $out = [];
        foreach (explode("\n", $body) as $line) {
            $line = $line === '-- ' ? $line : rtrim($line, " \t");
            if (str_starts_with($line, '>') || mb_strlen($line) <= self::WIDTH) {
                $out[] = $line;
                continue;
            }
            preg_match('/^\s*/', $line, $m);
            $indent = $m[0];
            $current = '';
            foreach (preg_split('/ +/', ltrim($line)) ?: [] as $word) {
                if ($current !== '' && mb_strlen($current . ' ' . $word) > self::WIDTH) {
                    $out[] = $current;
                    $current = $indent . $word;
                } else {
                    $current = $current === '' ? $indent . $word : $current . ' ' . $word;
                }
            }
            $out[] = $current;
        }
        return trim(implode("\n", $out), "\n");
    }

    /**
     * Problems with what the user entered, as plain-English messages keyed by field. Empty when fine.
     *
     * @param list<string> $groups
     * @param list<string> $followupTo
     * @param \Closure(string): ?string $status group name => active-file status, or null when unknown
     * @return array<string, string>
     */
    public static function validate(array $groups, array $followupTo, string $subject, string $body, int $maxGroups, \Closure $status): array
    {
        $errors = [];
        if ($groups === []) {
            $errors['newsgroups'] = 'Choose at least one newsgroup.';
        } elseif (count($groups) > $maxGroups) {
            $errors['newsgroups'] = "You can post to at most $maxGroups groups at once.";
        } else {
            foreach ($groups as $g) {
                $s = GroupName::isValid($g) ? $status($g) : null;
                if ($s === null) {
                    $errors['newsgroups'] = "There is no newsgroup called “{$g}” on this server.";
                    break;
                }
                if (!in_array($s, ['y', 'm'], true)) {
                    $errors['newsgroups'] = "Posting to {$g} is not allowed.";
                    break;
                }
            }
        }
        if (count($followupTo) > $maxGroups) {
            $errors['followup_to'] = "Follow-ups can go to at most $maxGroups groups.";
        } else {
            foreach ($followupTo as $g) {
                if ($g !== 'poster' && (!GroupName::isValid($g) || $status($g) === null)) {
                    $errors['followup_to'] = "There is no newsgroup called “{$g}” on this server.";
                    break;
                }
            }
        }
        if (!mb_check_encoding($subject, 'UTF-8') || !mb_check_encoding($body, 'UTF-8')) {
            $errors['body'] = 'The text contains characters that could not be read. Please retype it.';
            return $errors;
        }
        if (trim($subject) === '') {
            $errors['subject'] = 'Enter a subject.';
        } elseif (mb_strlen($subject) > self::MAX_SUBJECT) {
            $errors['subject'] = 'The subject is too long (at most ' . self::MAX_SUBJECT . ' characters).';
        } elseif (preg_match('/[\x00-\x1F\x7F]/', $subject)) {
            $errors['subject'] = 'The subject cannot contain line breaks or control characters.';
        }
        if (trim($body) === '') {
            $errors['body'] = 'Write something before posting.';
        } elseif (strlen($body) > self::MAX_BODY_BYTES) {
            $errors['body'] = 'The text is too long (at most 64 KB).';
        } elseif (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $body)) {
            $errors['body'] = 'The text contains control characters. Please remove them.';
        } elseif (self::onlyQuotes($body)) {
            $errors['body'] = 'Add your own text: the post only contains quoted lines.';
        }
        return $errors;
    }

    /**
     * The article as lines, ready for Client::post().
     *
     * @param list<string> $groups
     * @param list<string> $followupTo
     * @param list<string> $references Message-IDs, oldest first
     * @return list<string>
     */
    public static function build(
        string $address,
        string $name,
        array $groups,
        string $subject,
        string $body,
        string $messageId,
        array $references = [],
        array $followupTo = [],
        ?int $date = null,
    ): array {
        $headers = [
            'From' => self::mailbox($name, $address),
            'Newsgroups' => implode(',', $groups),
            'Subject' => self::encodeHeader(trim($subject)),
            'Date' => gmdate('D, d M Y H:i:s', $date ?? time()) . ' +0000',
            'Message-ID' => $messageId,
        ];
        if ($references !== []) {
            $headers['References'] = implode(' ', self::trimReferences($references));
        }
        if ($followupTo !== []) {
            $headers['Followup-To'] = implode(',', $followupTo);
        }
        $headers += [
            'MIME-Version' => '1.0',
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Transfer-Encoding' => '8bit',
            'User-Agent' => 'Newsstand',
        ];
        $lines = [];
        foreach ($headers as $field => $value) {
            foreach (self::fold("$field: $value") as $l) {
                $lines[] = $l;
            }
        }
        $lines[] = '';
        foreach (explode("\n", self::wrap($body)) as $l) {
            $lines[] = $l;
        }
        return $lines;
    }

    /** A unique Message-ID on our own domain. It contains no user data. */
    public static function messageId(string $domain): string
    {
        $left = base_convert((string) time(), 10, 36) . '.' . bin2hex(random_bytes(8));
        return '<' . $left . '@' . strtolower($domain) . '>';
    }

    /** Message-IDs from a References header; malformed ones are dropped. @return list<string> */
    public static function parseReferences(string $value): array
    {
        preg_match_all('/<[^<>\s@]+@[^<>\s@]+>/', $value, $m);
        return array_values(array_unique($m[0]));
    }

    private static function onlyQuotes(string $body): bool
    {
        foreach (explode("\n", str_replace("\r\n", "\n", $body)) as $line) {
            $line = trim($line);
            if ($line !== '' && !str_starts_with($line, '>') && !preg_match('/ wrote:$/', $line)) {
                return false;
            }
        }
        return true;
    }

    private static function mailbox(string $name, string $address): string
    {
        $name = trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', $name) ?? '');
        if ($name === '') {
            return $address;
        }
        if (preg_match('/[^\x20-\x7E]/', $name)) {
            return self::encodeHeader($name) . " <$address>";
        }
        if (preg_match('/^[A-Za-z0-9!#$%&\'*+\/=?^_`{|}~ -]+$/', $name)) {
            return "$name <$address>";
        }
        return '"' . addcslashes($name, '"\\') . "\" <$address>";
    }

    /** RFC 2047 B-encoding for non-ASCII text, in encoded-words short enough to fold. */
    private static function encodeHeader(string $text): string
    {
        if (!preg_match('/[^\x20-\x7E]/', $text)) {
            return $text;
        }
        $words = [];
        $chunk = '';
        foreach (mb_str_split($text) as $char) {
            if (strlen($chunk . $char) > 45) {
                $words[] = '=?UTF-8?B?' . base64_encode($chunk) . '?=';
                $chunk = '';
            }
            $chunk .= $char;
        }
        if ($chunk !== '') {
            $words[] = '=?UTF-8?B?' . base64_encode($chunk) . '?=';
        }
        return implode(' ', $words);
    }

    /** Folds a header line at spaces so lines stay under 78 characters where possible. @return list<string> */
    private static function fold(string $line): array
    {
        if (strlen($line) <= 78) {
            return [$line];
        }
        $out = [];
        $current = '';
        foreach (explode(' ', $line) as $i => $part) {
            if ($current !== '' && strlen($current . ' ' . $part) > 78) {
                $out[] = $current;
                $current = ' ' . $part;
            } else {
                $current = $i === 0 ? $part : $current . ' ' . $part;
            }
        }
        $out[] = $current;
        return $out;
    }

    /**
     * Keeps References under about 900 characters as RFC 5537 suggests: the first ID and the most recent
     * ones are kept, the middle is dropped.
     *
     * @param list<string> $refs
     * @return list<string>
     */
    private static function trimReferences(array $refs): array
    {
        while (count($refs) > 2 && strlen(implode(' ', $refs)) > 900) {
            array_splice($refs, 1, 1);
        }
        return $refs;
    }
}
