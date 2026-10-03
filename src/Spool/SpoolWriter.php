<?php

namespace Ojcloveu\LogHub\Spool;

use RuntimeException;

class SpoolWriter
{
    public function __construct(
        private readonly string $path,
        private readonly int $maxFileBytes = 5_242_880,
        private readonly int $maxTotalBytes = 52_428_800,
        private readonly int $leaseStaleSeconds = 300,
    ) {}

    public function append(string $json): void
    {
        $this->withLock(function () use ($json): void {
            $this->recoverStaleLeases();
            $line = $json."\n";
            $activePath = $this->path.'/active.jsonl';
            $activeSize = is_file($activePath) ? (int) filesize($activePath) : 0;

            if ($activeSize > 0 && $activeSize + strlen($line) > max(1, $this->maxFileBytes)) {
                $this->rotateActive();
            }

            $this->enforceTotalLimit(strlen($line));
            $this->appendLine($activePath, $line);
        });
    }

    public function leaseBatch(int $maximumRecords): ?SpoolBatch
    {
        return $this->withLock(function () use ($maximumRecords): ?SpoolBatch {
            $this->recoverStaleLeases();
            $this->rotateActive();

            foreach ($this->readyFiles() as $readyPath) {
                $id = $this->idFromPath($readyPath);
                $metadata = $this->retryMetadata($id);

                if (($metadata['next_attempt_at'] ?? 0) > time()) {
                    continue;
                }

                $leasedPath = $this->path.'/sending-'.$id.'.jsonl';

                if (! rename($readyPath, $leasedPath)) {
                    continue;
                }

                touch($leasedPath);
                $lines = file($leasedPath, FILE_IGNORE_NEW_LINES);

                if ($lines === false) {
                    rename($leasedPath, $readyPath);

                    continue;
                }

                $consumedLines = array_slice($lines, 0, max(1, $maximumRecords));
                $records = [];

                foreach ($consumedLines as $line) {
                    $record = json_decode($line, true);

                    if (is_array($record)) {
                        $records[] = $record;
                    }
                }

                $batch = new SpoolBatch(
                    id: $id,
                    leasedPath: $leasedPath,
                    records: $records,
                    remainingLines: array_slice($lines, count($consumedLines)),
                    attempts: (int) ($metadata['attempts'] ?? 0),
                );

                if ($records === []) {
                    $this->acknowledgeUnlocked($batch);

                    continue;
                }

                return $batch;
            }

            return null;
        });
    }

    public function acknowledge(SpoolBatch $batch): void
    {
        $this->withLock(fn () => $this->acknowledgeUnlocked($batch));
    }

    public function release(SpoolBatch $batch, int $maximumRetrySeconds): void
    {
        $this->withLock(function () use ($batch, $maximumRetrySeconds): void {
            if (! is_file($batch->leasedPath)) {
                return;
            }

            $attempts = $batch->attempts + 1;
            $delay = min(max(1, $maximumRetrySeconds), 2 ** min($attempts - 1, 10));
            $this->atomicWrite($this->retryPath($batch->id), json_encode([
                'attempts' => $attempts,
                'next_attempt_at' => time() + $delay,
            ], JSON_THROW_ON_ERROR));
            rename($batch->leasedPath, $this->readyPath($batch->id));
        });
    }

    private function ensureDirectory(): void
    {
        if (! is_dir($this->path) && ! @mkdir($this->path, 0700, true) && ! is_dir($this->path)) {
            throw new RuntimeException('Unable to create the LogHub spool directory.');
        }
    }

    private function withLock(callable $callback): mixed
    {
        $this->ensureDirectory();
        $lock = fopen($this->path.'/spool.lock', 'c+b');

        if ($lock === false || ! flock($lock, LOCK_EX)) {
            throw new RuntimeException('Unable to lock the LogHub spool.');
        }

        try {
            return $callback();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function rotateActive(): void
    {
        $activePath = $this->path.'/active.jsonl';

        if (! is_file($activePath) || filesize($activePath) === 0) {
            return;
        }

        if (! rename($activePath, $this->readyPath($this->newId()))) {
            throw new RuntimeException('Unable to rotate the LogHub spool.');
        }
    }

    private function recoverStaleLeases(): void
    {
        foreach (glob($this->path.'/sending-*.jsonl') ?: [] as $leasedPath) {
            if ((int) filemtime($leasedPath) > time() - max(1, $this->leaseStaleSeconds)) {
                continue;
            }

            rename($leasedPath, $this->readyPath($this->idFromPath($leasedPath)));
        }

        foreach (glob($this->path.'/*.tmp-*') ?: [] as $temporaryPath) {
            if ((int) filemtime($temporaryPath) <= time() - max(1, $this->leaseStaleSeconds)) {
                unlink($temporaryPath);
            }
        }
    }

    private function enforceTotalLimit(int $incomingBytes): void
    {
        $maximum = max(max(1, $this->maxFileBytes), $this->maxTotalBytes);

        while ($this->totalBytes() + $incomingBytes > $maximum) {
            $oldest = $this->readyFiles()[0] ?? null;

            if ($oldest === null) {
                return;
            }

            $id = $this->idFromPath($oldest);
            unlink($oldest);
            $this->removeRetryMetadata($id);
        }
    }

    /** @return list<string> */
    private function readyFiles(): array
    {
        $files = glob($this->path.'/ready-*.jsonl') ?: [];
        sort($files, SORT_STRING);

        return array_values($files);
    }

    private function totalBytes(): int
    {
        $total = 0;

        foreach (glob($this->path.'/*.jsonl') ?: [] as $file) {
            $total += (int) filesize($file);
        }

        return $total;
    }

    private function appendLine(string $path, string $line): void
    {
        $stream = fopen($path, 'ab');

        if ($stream === false) {
            throw new RuntimeException('Unable to open the LogHub spool.');
        }

        try {
            $written = 0;

            while ($written < strlen($line)) {
                $bytes = fwrite($stream, substr($line, $written));

                if ($bytes === false || $bytes === 0) {
                    throw new RuntimeException('Unable to write the LogHub spool.');
                }

                $written += $bytes;
            }

            $this->flush($stream);
        } finally {
            fclose($stream);
        }
    }

    private function atomicWrite(string $path, string $contents): void
    {
        $temporaryPath = $path.'.tmp-'.bin2hex(random_bytes(4));
        $stream = fopen($temporaryPath, 'xb');

        if ($stream === false) {
            throw new RuntimeException('Unable to create a temporary spool file.');
        }

        try {
            $written = 0;

            while ($written < strlen($contents)) {
                $bytes = fwrite($stream, substr($contents, $written));

                if ($bytes === false || $bytes === 0) {
                    throw new RuntimeException('Unable to write a temporary spool file.');
                }

                $written += $bytes;
            }

            $this->flush($stream);
        } finally {
            fclose($stream);
        }

        if (! rename($temporaryPath, $path)) {
            @unlink($temporaryPath);

            throw new RuntimeException('Unable to commit a spool file.');
        }
    }

    private function acknowledgeUnlocked(SpoolBatch $batch): void
    {
        if (! is_file($batch->leasedPath)) {
            return;
        }

        if ($batch->remainingLines !== []) {
            $this->atomicWrite(
                $this->readyPath($batch->id),
                implode("\n", $batch->remainingLines)."\n",
            );
        }

        unlink($batch->leasedPath);
        $this->removeRetryMetadata($batch->id);
    }

    /** @param resource $stream */
    private function flush($stream): void
    {
        if (! fflush($stream) || (function_exists('fsync') && ! fsync($stream))) {
            throw new RuntimeException('Unable to synchronize the LogHub spool.');
        }
    }

    /** @return array{attempts?: int, next_attempt_at?: int} */
    private function retryMetadata(string $id): array
    {
        $contents = @file_get_contents($this->retryPath($id));
        $metadata = is_string($contents) ? json_decode($contents, true) : null;

        return is_array($metadata) ? $metadata : [];
    }

    private function removeRetryMetadata(string $id): void
    {
        $path = $this->retryPath($id);

        if (is_file($path)) {
            unlink($path);
        }
    }

    private function newId(): string
    {
        return sprintf('%020d-%s', (int) (microtime(true) * 1_000_000), bin2hex(random_bytes(4)));
    }

    private function idFromPath(string $path): string
    {
        return preg_replace('/^(?:ready|sending)-|\.jsonl$/', '', basename($path));
    }

    private function readyPath(string $id): string
    {
        return $this->path.'/ready-'.$id.'.jsonl';
    }

    private function retryPath(string $id): string
    {
        return $this->path.'/retry-'.$id.'.json';
    }
}
