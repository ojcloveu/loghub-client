<?php

namespace Ojcloveu\LogHub\Tests;

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Ojcloveu\LogHub\Delivery\SpoolFlusher;
use Ojcloveu\LogHub\Spool\SpoolWriter;

final class SpoolDeliveryTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = sys_get_temp_dir().'/loghub-delivery-'.bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->path.'/*') ?: [] as $file) {
            unlink($file);
        }

        if (is_dir($this->path)) {
            rmdir($this->path);
        }

        parent::tearDown();
    }

    public function test_spool_rotates_and_enforces_total_size_limit(): void
    {
        $spool = new SpoolWriter($this->path, 80, 160);

        for ($index = 0; $index < 8; $index++) {
            $spool->append(json_encode(['message' => str_repeat((string) $index, 40)], JSON_THROW_ON_ERROR));
        }

        $total = array_sum(array_map('filesize', glob($this->path.'/*.jsonl') ?: []));

        $this->assertLessThanOrEqual(160, $total);
        $this->assertNotEmpty(glob($this->path.'/ready-*.jsonl'));
    }

    public function test_stale_lease_is_recovered_and_delivered_in_bounded_batches(): void
    {
        $spool = new SpoolWriter($this->path, 1_024, 10_240, 1);
        $spool->append(json_encode(['level' => 'info', 'message' => 'first'], JSON_THROW_ON_ERROR));
        $spool->append(json_encode(['level' => 'info', 'message' => 'second'], JSON_THROW_ON_ERROR));
        $batch = $spool->leaseBatch(1);
        touch($batch->leasedPath, time() - 2);
        Http::fake(['*' => Http::response(['queued' => 1], 202)]);

        $result = $this->flusher($spool, 1)->flush();

        $this->assertTrue($result->delivered);
        $this->assertSame(1, $result->records);
        $secondResult = $this->flusher($spool, 1)->flush();
        $this->assertTrue($secondResult->delivered);
        $this->assertSame(1, $secondResult->records);
        Http::assertSentCount(2);
    }

    public function test_failed_delivery_restores_batch_with_safe_retry_metadata(): void
    {
        $spool = new SpoolWriter($this->path);
        $spool->append(json_encode(['level' => 'error', 'message' => 'retry'], JSON_THROW_ON_ERROR));
        Http::fake(['*' => Http::sequence()
            ->push(['message' => 'Unavailable'], 503)
            ->push(['queued' => 1], 202)]);

        $result = $this->flusher($spool)->flush();

        $this->assertFalse($result->delivered);
        $this->assertSame(1, $result->records);
        $this->assertCount(1, glob($this->path.'/ready-*.jsonl') ?: []);
        $metadataFiles = glob($this->path.'/retry-*.json') ?: [];
        $this->assertCount(1, $metadataFiles);
        $metadata = json_decode(
            (string) file_get_contents($metadataFiles[0]),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $this->assertSame(1, $metadata['attempts']);
        $this->assertGreaterThan(time(), $metadata['next_attempt_at']);

        $metadata['next_attempt_at'] = time() - 1;
        file_put_contents($metadataFiles[0], json_encode($metadata, JSON_THROW_ON_ERROR));

        $recovered = $this->flusher($spool)->flush();

        $this->assertTrue($recovered->delivered);
        $this->assertSame(1, $recovered->records);
        $this->assertSame([], glob($this->path.'/ready-*.jsonl') ?: []);
        $this->assertSame([], glob($this->path.'/retry-*.json') ?: []);
    }

    public function test_concurrent_processes_append_complete_json_records_without_loss(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('The pcntl extension is required for process concurrency coverage.');
        }

        $processes = 4;
        $recordsPerProcess = 25;
        $children = [];

        for ($process = 0; $process < $processes; $process++) {
            $processId = pcntl_fork();

            if ($processId === 0) {
                try {
                    $spool = new SpoolWriter($this->path, 512, 1_048_576);

                    for ($record = 0; $record < $recordsPerProcess; $record++) {
                        $spool->append(json_encode([
                            'message' => $process.'-'.$record,
                        ], JSON_THROW_ON_ERROR));
                    }
                } catch (\Throwable) {
                    exit(1);
                }

                exit(0);
            }

            $this->assertGreaterThan(0, $processId);
            $children[] = $processId;
        }

        foreach ($children as $processId) {
            pcntl_waitpid($processId, $status);
            $this->assertSame(0, pcntl_wexitstatus($status));
        }

        $spool = new SpoolWriter($this->path, 512, 1_048_576);
        $messages = [];

        while (($batch = $spool->leaseBatch(17)) !== null) {
            foreach ($batch->records as $record) {
                $messages[] = $record['message'];
            }

            $spool->acknowledge($batch);
        }

        $this->assertCount($processes * $recordsPerProcess, $messages);
        $this->assertCount($processes * $recordsPerProcess, array_unique($messages));
    }

    private function flusher(SpoolWriter $spool, int $batchSize = 100): SpoolFlusher
    {
        return new SpoolFlusher(
            spool: $spool,
            http: app(Factory::class),
            endpoint: 'https://loghub.example',
            apiKey: 'project.secret',
            batchSize: $batchSize,
            connectTimeout: 0.2,
            timeout: 0.5,
            maximumRetrySeconds: 60,
        );
    }
}
