<?php

declare(strict_types=1);

namespace Newsstand\Nntp;

use Newsstand\Config;

/**
 * A small NNTP reader client (RFC 3977) for INN's nnrpd.
 *
 * Multi-line responses are returned as generators; consume them fully before sending the next command.
 */
final class Client
{
    private bool $postingAdvertised = false;
    private ?string $currentGroup = null;

    public function __construct(private readonly Transport $transport)
    {
        [$code, $text] = $this->readStatus();
        if ($code !== 200 && $code !== 201) {
            throw new NntpException("News server refused the connection: $code $text", $code);
        }
        // INN's loopback listener greets with 200 but adds "(no posting)" until a login succeeds.
        $this->postingAdvertised = $code === 200 && stripos($text, 'no posting') === false;
    }

    public static function connect(Config $config): self
    {
        $client = new self(SocketTransport::connect(
            $config->string('nntp_host'),
            $config->int('nntp_port'),
            $config->int('nntp_timeout'),
        ));
        $client->modeReader();
        return $client;
    }

    /** True when the greeting did not say "(no posting)". Not proof that POST will work. */
    public function postingAdvertised(): bool
    {
        return $this->postingAdvertised;
    }

    public function modeReader(): void
    {
        [$code, $text] = $this->command('MODE READER');
        if ($code === 200 || $code === 201) {
            $this->postingAdvertised = $code === 200 && stripos($text, 'no posting') === false;
        }
        // 500/501 means the server is already a reader; that is fine.
    }

    /** @return array{count: int, low: int, high: int, name: string} */
    public function group(string $name): array
    {
        $this->assertGroupName($name);
        [$code, $text] = $this->command("GROUP $name");
        $this->expect($code, $text, 211);
        $parts = preg_split('/\s+/', trim($text));
        $this->currentGroup = $name;
        return [
            'count' => (int) ($parts[0] ?? 0),
            'low' => (int) ($parts[1] ?? 0),
            'high' => (int) ($parts[2] ?? 0),
            'name' => $parts[3] ?? $name,
        ];
    }

    /** @return \Generator<int, array{name: string, high: int, low: int, status: string}> */
    public function listActive(?string $wildmat = null): \Generator
    {
        [$code, $text] = $this->command($wildmat === null ? 'LIST ACTIVE' : "LIST ACTIVE $wildmat");
        $this->expect($code, $text, 215);
        foreach ($this->readBlock() as $line) {
            $parts = preg_split('/\s+/', trim($line));
            if (count($parts) < 4) {
                continue;
            }
            yield ['name' => $parts[0], 'high' => (int) $parts[1], 'low' => (int) $parts[2], 'status' => $parts[3]];
        }
    }

    /** @return \Generator<string, string> group name => raw description bytes (charset unknown) */
    public function listNewsgroups(?string $wildmat = null): \Generator
    {
        [$code, $text] = $this->command($wildmat === null ? 'LIST NEWSGROUPS' : "LIST NEWSGROUPS $wildmat");
        $this->expect($code, $text, 215);
        foreach ($this->readBlock() as $line) {
            if (preg_match('/^(\S+)\s+(.*)$/', $line, $m)) {
                yield $m[1] => trim($m[2]);
            }
        }
    }

    /**
     * Overview data for a range of the current group. Fields follow RFC 3977's fixed order.
     *
     * @return list<OverviewEntry>
     */
    public function over(int $low, int $high): array
    {
        $this->requireGroup();
        if ($low > $high) {
            return [];
        }
        [$code, $text] = $this->command($low === $high ? "OVER $low" : "OVER $low-$high");
        if ($code === 423) {
            return [];
        }
        $this->expect($code, $text, 224);
        $entries = [];
        foreach ($this->readBlock() as $line) {
            $entry = OverviewEntry::fromLine($line);
            if ($entry !== null) {
                $entries[] = $entry;
            }
        }
        return $entries;
    }

    /** The full article as raw bytes, lines joined with "\n". */
    public function article(int $number, int $maxBytes = 0): string
    {
        return $this->fetch('ARTICLE', $number, 220, $maxBytes);
    }

    public function head(int $number): string
    {
        return $this->fetch('HEAD', $number, 221, 0);
    }

    public function quit(): void
    {
        try {
            $this->transport->writeLine('QUIT');
            $this->transport->readLine();
        } catch (NntpException) {
            // The connection is going away either way.
        }
        $this->transport->close();
    }

    /** @return array{0: int, 1: string} */
    public function command(string $line): array
    {
        if (preg_match('/[\r\n\0]/', $line)) {
            throw new NntpException('Refusing to send a command containing line breaks');
        }
        $this->transport->writeLine($line);
        return $this->readStatus();
    }

    private function fetch(string $verb, int $number, int $ok, int $maxBytes): string
    {
        $this->requireGroup();
        if ($number < 1) {
            throw new NntpException('No such article', 423);
        }
        [$code, $text] = $this->command("$verb $number");
        $this->expect($code, $text, $ok);
        $lines = [];
        $size = 0;
        foreach ($this->readBlock() as $line) {
            $size += strlen($line) + 1;
            if ($maxBytes > 0 && $size > $maxBytes) {
                // Stop reading; the caller must drop this connection because the response is unfinished.
                throw new NntpException('Article is too large to display', -1);
            }
            $lines[] = $line;
        }
        return implode("\n", $lines);
    }

    /** @return \Generator<int, string> */
    private function readBlock(): \Generator
    {
        while (true) {
            $line = $this->transport->readLine();
            if ($line === '.') {
                return;
            }
            if (str_starts_with($line, '.')) {
                $line = substr($line, 1);
            }
            yield $line;
        }
    }

    /** @return array{0: int, 1: string} */
    private function readStatus(): array
    {
        $line = $this->transport->readLine();
        if (!preg_match('/^([1-5]\d\d)(?:[ \t]+(.*))?$/', $line, $m)) {
            throw new NntpException('Unexpected reply from the news server');
        }
        return [(int) $m[1], $m[2] ?? ''];
    }

    private function expect(int $code, string $text, int $wanted): void
    {
        if ($code !== $wanted) {
            throw new NntpException("$code $text", $code);
        }
    }

    private function requireGroup(): void
    {
        if ($this->currentGroup === null) {
            throw new NntpException('No group selected', 412);
        }
    }

    private function assertGroupName(string $name): void
    {
        if (!GroupName::isValid($name)) {
            throw new NntpException('Invalid group name', 411);
        }
    }
}
