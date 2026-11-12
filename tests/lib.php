<?php

declare(strict_types=1);

use Newsstand\Nntp\NntpException;
use Newsstand\Nntp\Transport;

final class Tests
{
    /** @var list<array{0: string, 1: Closure}> */
    public static array $cases = [];
}

final class AssertionFailed extends Exception
{
}

function test(string $name, Closure $fn): void
{
    Tests::$cases[] = [$name, $fn];
}

function eq(mixed $expected, mixed $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        throw new AssertionFailed(($msg !== '' ? "$msg\n" : '') . 'expected ' . var_export($expected, true) . "\n     got " . var_export($actual, true));
    }
}

function ok(bool $cond, string $msg = 'assertion failed'): void
{
    if (!$cond) {
        throw new AssertionFailed($msg);
    }
}

function throws(Closure $fn, string $class, string $msg = ''): Throwable
{
    try {
        $fn();
    } catch (Throwable $e) {
        if ($e instanceof $class) {
            return $e;
        }
        throw new AssertionFailed("expected $class, got " . $e::class . ': ' . $e->getMessage());
    }
    throw new AssertionFailed($msg !== '' ? $msg : "expected $class, nothing thrown");
}

/** A fresh temporary directory, removed when the test run ends. */
function tempDir(): string
{
    $dir = sys_get_temp_dir() . '/newsstand-test-' . bin2hex(random_bytes(4));
    mkdir($dir);
    register_shutdown_function(static function () use ($dir) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        @rmdir($dir);
    });
    return $dir;
}

function fixture(string $name): string
{
    return (string) file_get_contents(__DIR__ . '/fixtures/' . $name);
}

/** A scripted NNTP server: answers each command with a canned response, records what was sent. */
final class FakeTransport implements Transport
{
    /** @var list<string> */
    public array $sent = [];
    /** @var list<string>|null lines received after a 340 reply to POST, until the terminating dot */
    public ?array $posted = null;
    private bool $collecting = false;
    /** @var list<string> */
    private array $queue;

    /**
     * @param string $greeting
     * @param array<string, string|Closure> $responses command (exact, or prefix ending in *) => response text
     */
    public function __construct(string $greeting, private array $responses = [])
    {
        $this->queue = [$greeting];
    }

    public function writeLine(string $line): void
    {
        if ($this->collecting) {
            if ($line !== '.') {
                $this->posted[] = str_starts_with($line, '.') ? substr($line, 1) : $line;
                return;
            }
            $this->collecting = false;
        }
        $this->sent[] = $line;
        $response = $this->responses[$line] ?? null;
        if ($response === null) {
            foreach ($this->responses as $cmd => $r) {
                if (str_ends_with($cmd, '*') && str_starts_with($line, substr($cmd, 0, -1))) {
                    $response = $r;
                    break;
                }
            }
        }
        if ($response instanceof Closure) {
            $response = $response($line);
        }
        $response ??= $line === "." ? "240 Article received" : "500 What?";
        if (str_starts_with($response, "340")) {
            $this->collecting = true;
            $this->posted = [];
        }
        foreach (explode("\n", str_replace("\r\n", "\n", rtrim($response, "\n"))) as $l) {
            $this->queue[] = $l;
        }
    }

    public function readLine(): string
    {
        if ($this->queue === []) {
            throw new NntpException('Connection to the news server closed');
        }
        return array_shift($this->queue);
    }

    public function close(): void
    {
    }
}

/** Builds a multi-line NNTP response with dot-stuffing. */
function block(string $status, string $body): string
{
    $lines = $body === '' ? [] : explode("\n", str_replace("\r\n", "\n", rtrim($body, "\n")));
    $lines = array_map(static fn ($l) => str_starts_with($l, '.') ? '.' . $l : $l, $lines);
    return $status . "\n" . ($lines === [] ? '' : implode("\n", $lines) . "\n") . '.';
}
