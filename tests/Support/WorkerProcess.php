<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

/**
 * Starts tests/Support/worker.php as a separate OS process with the same
 * environment (same test database) and lets the test synchronise with it.
 */
final class WorkerProcess
{
    /** @var resource */
    private $process;

    /** @var array<int, resource> */
    private array $pipes;

    /** @param list<string> $args */
    public function __construct(string $scenario, array $args)
    {
        $cmd = array_merge([PHP_BINARY, dirname(__DIR__).'/Support/worker.php', $scenario], $args);
        $env = array_merge(getenv(), $_ENV);
        $process = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2), $env);
        if (! is_resource($process)) {
            throw new RuntimeException('Could not start worker process');
        }
        $this->process = $process;
        $this->pipes = $pipes;
    }

    /** Blocks until the worker prints a line starting with $prefix; returns that line. */
    public function waitFor(string $prefix, float $timeoutSeconds = 15.0): string
    {
        $deadline = microtime(true) + $timeoutSeconds;
        stream_set_blocking($this->pipes[1], false);
        $buffer = '';
        while (microtime(true) < $deadline) {
            $chunk = fgets($this->pipes[1]);
            if ($chunk !== false) {
                $buffer .= $chunk;
                $line = trim($chunk);
                if (str_starts_with($line, $prefix)) {
                    return $line;
                }
                if (str_starts_with($line, 'ERROR')) {
                    throw new RuntimeException("Worker failed: {$line}\n".stream_get_contents($this->pipes[2]));
                }
                continue;
            }
            if (! proc_get_status($this->process)['running']) {
                throw new RuntimeException("Worker exited before '{$prefix}'. Output: {$buffer}\nStderr: ".stream_get_contents($this->pipes[2]));
            }
            usleep(10_000);
        }

        throw new RuntimeException("Timed out waiting for '{$prefix}'. Output so far: {$buffer}");
    }

    public function send(string $line): void
    {
        fwrite($this->pipes[0], $line."\n");
        fflush($this->pipes[0]);
    }

    public function finish(): int
    {
        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        return proc_close($this->process);
    }
}
