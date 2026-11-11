<?php

declare(strict_types=1);

namespace Newsstand\Mime;

use Newsstand\Text\Charset;

/**
 * An article prepared for display: headers, the readable text (UTF-8, format=flowed undone) split into
 * quote-level blocks, and a list of attachments. Attachments are listed, never displayed.
 *
 * Nothing here is obfuscated: addresses are disguised when a page is rendered (see Privacy\Obfuscator).
 */
final class Article
{
    /** @var list<array{name: string, type: string, size: int}> */
    public array $attachments = [];
    public string $text = '';
    /** @var list<array{level: int, signature: bool, text: string}> */
    public array $blocks = [];

    private function __construct(public readonly Headers $headers)
    {
    }

    public static function fromRaw(string $raw): self
    {
        $root = MimeParser::parse($raw);
        $article = new self($root->headers);
        $texts = [];
        $article->collect($root, $texts);
        $text = implode("\n\n", $texts);
        $text = $article->extractEncodedBinaries($text);
        $article->text = $text;
        $article->blocks = BodyFormatter::blocks($text);
        return $article;
    }

    /** @param list<string> $texts */
    private function collect(Part $part, array &$texts): void
    {
        if ($part->type === 'multipart/alternative' && $part->children !== []) {
            $chosen = null;
            foreach (['text/plain', 'text/html'] as $preferred) {
                foreach ($part->children as $child) {
                    if ($child->type === $preferred && !$child->isAttachment()) {
                        $chosen = $child;
                        break 2;
                    }
                }
            }
            $this->collect($chosen ?? $part->children[0], $texts);
            return;
        }
        if ($part->isMultipart() && $part->children !== []) {
            foreach ($part->children as $child) {
                $this->collect($child, $texts);
            }
            return;
        }
        if (!$part->isAttachment() && ($part->type === 'text/plain' || $part->isMultipart())) {
            $text = Charset::toUtf8($part->decodedBody(), $part->charset());
            if (strtolower($part->params['format'] ?? '') === 'flowed') {
                $text = Flowed::decode($text, strtolower($part->params['delsp'] ?? '') === 'yes');
            }
            $texts[] = rtrim($text, "\n");
            return;
        }
        if (!$part->isAttachment() && $part->type === 'text/html') {
            $texts[] = self::htmlToText(Charset::toUtf8($part->decodedBody(), $part->charset()));
            return;
        }
        $this->attachments[] = [
            'name' => $part->filename() ?? ($part->type === 'message/rfc822' ? 'forwarded message' : 'unnamed'),
            'type' => $part->type,
            'size' => strlen($part->decodedBody()),
        ];
    }

    /** Lists uuencoded and yEnc blocks inside text bodies as attachments and removes them from the text. */
    private function extractEncodedBinaries(string $text): string
    {
        $text = preg_replace_callback(
            '/^begin [0-7]{3,4} ([^\n]+)\n(.*?)^end[ \t]*$\n?/ms',
            function (array $m): string {
                $this->attachments[] = ['name' => trim($m[1]), 'type' => 'application/octet-stream (uuencoded)', 'size' => (int) (strlen($m[2]) * 3 / 4)];
                return '';
            },
            $text,
        ) ?? $text;
        return preg_replace_callback(
            '/^=ybegin [^\n]*?name=([^\n]+)\n(.*?)^=yend[^\n]*$\n?/ms',
            function (array $m): string {
                $this->attachments[] = ['name' => trim($m[1]), 'type' => 'application/octet-stream (yEnc)', 'size' => strlen($m[2])];
                return '';
            },
            $text,
        ) ?? $text;
    }

    public static function htmlToText(string $html): string
    {
        $html = preg_replace('#<(script|style|head)\b.*?</\1\s*>#is', '', $html) ?? '';
        $html = preg_replace('#<br\s*/?>#i', "\n", $html) ?? '';
        $html = preg_replace('#</(p|div|li|tr|h[1-6]|blockquote)\s*>#i', "\n", $html) ?? '';
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t]+\n/", "\n", $text) ?? $text;
        return trim(preg_replace("/\n{3,}/", "\n\n", $text) ?? $text);
    }
}
