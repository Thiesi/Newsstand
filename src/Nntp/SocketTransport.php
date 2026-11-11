<?php

declare(strict_types=1);

namespace Newsstand\Nntp;

/**
 * Plain TCP connection to INN's loopback listener.
 *
 * THE ONE HARD RULE: Newsstand only ever connects to the loopback interface. Roanoke's public address
 * (News.NetWorkXXIII.de, 91.134.122.80) is trusted to post without login, so connecting there would let
 * anonymous web visitors post. The check below refuses every other host, so a wrong config value fails
 * loudly instead of opening that hole. Development uses an SSH tunnel to a local port.
 */
final class SocketTransport implements Transport
{
    public const ALLOWED_HOSTS = ['127.0.0.1', '::1'];

    /** @var resource */
    private $stream;

    private function __construct($stream)
    {
        $this->stream = $stream;
    }

    public static function connect(string $host, int $port, int $timeout): self
    {
        if (!in_array($host, self::ALLOWED_HOSTS, true)) {
            throw new NntpException("Refusing to connect to $host: Newsstand may only connect to the loopback interface");
        }
        if ($port < 1 || $port > 65535) {
            throw new NntpException("Invalid NNTP port $port");
        }
        $target = str_contains($host, ':') ? "tcp://[$host]:$port" : "tcp://$host:$port";
        $stream = @stream_socket_client($target, $errno, $error, $timeout);
        if ($stream === false) {
            throw new NntpException("Cannot connect to the news server: $error");
        }
        stream_set_timeout($stream, $timeout);
        return new self($stream);
    }

    public function writeLine(string $line): void
    {
        $data = $line . "\r\n";
        while ($data !== '') {
            $written = @fwrite($this->stream, $data);
            if ($written === false || $written === 0) {
                throw new NntpException('Connection to the news server lost while writing');
            }
            $data = substr($data, $written);
        }
    }

    public function readLine(): string
    {
        $line = fgets($this->stream);
        if ($line === false) {
            $meta = stream_get_meta_data($this->stream);
            throw new NntpException($meta['timed_out'] ? 'The news server timed out' : 'Connection to the news server closed');
        }
        return rtrim($line, "\r\n");
    }

    public function close(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
    }
}
