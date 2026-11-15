<?php

declare(strict_types=1);

namespace Nowo\LoginThrottleBundle\Tests\Database\Support;

/**
 * One attempt-worker.php process (a concurrent request on its own connection), read line by line with timeouts.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2025 Nowo.tech
 */
final class WorkerProcess
{
    /** @var resource */
    private $process;

    /** @var resource */
    private $input;

    /** @var resource */
    private $output;

    private string $buffer = '';

    /**
     * @param list<string> $arguments
     */
    public function __construct(string $url, string $command, array $arguments)
    {
        $process = proc_open(
            [\PHP_BINARY, __DIR__ . '/attempt-worker.php', $url, $command, ...$arguments],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', 'php://stderr', 'w']],
            $pipes,
        );
        if (!\is_resource($process)) {
            throw new \RuntimeException('The worker could not be started.');
        }
        $this->process = $process;
        $this->input = $pipes[0];
        $this->output = $pipes[1];
        stream_set_blocking($this->output, false);
    }

    public function send(string $line = 'GO'): void
    {
        fwrite($this->input, $line . "\n");
        fflush($this->input);
    }

    /**
     * The next line, or null when none arrived within $seconds.
     */
    public function line(float $seconds = 30.0): ?string
    {
        $deadline = microtime(true) + $seconds;
        while (false === ($end = strpos($this->buffer, "\n"))) {
            $left = $deadline - microtime(true);
            if ($left <= 0) {
                return null;
            }
            $read = [$this->output];
            $write = $except = null;
            if (false === stream_select($read, $write, $except, (int) $left, (int) (($left - (int) $left) * 1_000_000))) {
                return null;
            }
            $chunk = fread($this->output, 8192);
            if (\is_string($chunk) && '' !== $chunk) {
                $this->buffer .= $chunk;
            } elseif (feof($this->output)) {
                return null;
            }
        }
        $line = substr($this->buffer, 0, $end);
        $this->buffer = substr($this->buffer, $end + 1);

        return $line;
    }

    public function expect(string $expected, float $seconds = 30.0): void
    {
        $line = $this->line($seconds);
        if ($expected !== $line) {
            throw new \RuntimeException(\sprintf('Expected "%s" from the worker, got %s.', $expected, var_export($line, true)));
        }
    }

    /**
     * Every line until DONE.
     *
     * @return list<string>
     */
    public function rest(float $seconds = 60.0): array
    {
        $lines = [];
        $deadline = microtime(true) + $seconds;
        while (null !== ($line = $this->line(max(0.1, $deadline - microtime(true)))) && 'DONE' !== $line) {
            $lines[] = $line;
        }

        return $lines;
    }

    public function close(): void
    {
        if (\is_resource($this->input)) {
            fclose($this->input);
        }
        if (\is_resource($this->output)) {
            fclose($this->output);
        }
        // bounded: a worker still waiting in a statement or a lock does not read stdin
        $deadline = microtime(true) + 5.0;
        while (proc_get_status($this->process)['running'] && microtime(true) < $deadline) {
            usleep(20_000);
        }
        if (proc_get_status($this->process)['running']) {
            proc_terminate($this->process, 9);
        }
        proc_close($this->process);
    }
}
