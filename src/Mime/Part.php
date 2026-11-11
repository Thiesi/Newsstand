<?php

declare(strict_types=1);

namespace Newsstand\Mime;

/** One MIME entity: headers, raw (still transfer-encoded) body, and child parts for multiparts. */
final class Part
{
    public readonly string $type;
    /** @var array<string, string> */
    public readonly array $params;
    public readonly string $disposition;
    /** @var array<string, string> */
    public readonly array $dispositionParams;

    /** @param list<Part> $children */
    public function __construct(
        public readonly Headers $headers,
        public readonly string $body,
        public array $children = [],
        string $defaultType = 'text/plain',
    ) {
        [$type, $params] = HeaderParams::parse($headers->get('Content-Type'));
        if (!preg_match('#^[a-z0-9!\#$&^_.+-]+/[a-z0-9!\#$&^_.+-]+$#', $type)) {
            $type = $defaultType;
            $params = $type === 'text/plain' ? $params : [];
        }
        $this->type = $type;
        $this->params = $params;
        [$this->disposition, $this->dispositionParams] = HeaderParams::parse($headers->get('Content-Disposition'));
    }

    public function isMultipart(): bool
    {
        return str_starts_with($this->type, 'multipart/');
    }

    public function charset(): ?string
    {
        return $this->params['charset'] ?? null;
    }

    public function filename(): ?string
    {
        $name = $this->dispositionParams['filename'] ?? $this->params['name'] ?? null;
        return $name === null || trim($name) === '' ? null : basename(str_replace('\\', '/', $name));
    }

    public function isAttachment(): bool
    {
        return $this->disposition === 'attachment' || ($this->filename() !== null && $this->disposition !== 'inline');
    }

    /** The body with its Content-Transfer-Encoding removed. */
    public function decodedBody(): string
    {
        $cte = strtolower(trim((string) $this->headers->get('Content-Transfer-Encoding')));
        return match ($cte) {
            'base64' => (string) base64_decode(preg_replace('/[^A-Za-z0-9+\/=]/', '', $this->body) ?? '', false),
            'quoted-printable' => str_replace("\r\n", "\n", quoted_printable_decode(str_replace("\n", "\r\n", $this->body))),
            default => $this->body,
        };
    }
}
