<?php

namespace Ojcloveu\LogHub\Tests;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use RuntimeException;

final class RequestIdMiddlewareTest extends TestCase
{
    private string $temporaryDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->temporaryDirectory = sys_get_temp_dir().'/loghub-client-'.bin2hex(random_bytes(8));
        config()->set('loghub-client.spool.path', $this->temporaryDirectory);
        config()->set('logging.channels.loghub', ['driver' => 'loghub']);
        Route::get('/request-id', function () {
            Log::channel('loghub')->warning('Inside request.');

            return response()->json([
                'request_id' => request()->attributes->get('request_id'),
            ]);
        });
        Route::get('/request-id-error', fn () => throw new RuntimeException('Request failed.'));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->temporaryDirectory.'/*') ?: [] as $file) {
            unlink($file);
        }

        if (is_dir($this->temporaryDirectory)) {
            rmdir($this->temporaryDirectory);
        }

        parent::tearDown();
    }

    public function test_valid_incoming_request_id_is_propagated_and_spooled(): void
    {
        $response = $this->withHeader('X-Request-ID', 'upstream:request-123')->getJson('/request-id');

        $response->assertOk()
            ->assertHeader('X-Request-ID', 'upstream:request-123')
            ->assertJsonPath('request_id', 'upstream:request-123');

        $this->assertSame('upstream:request-123', $this->spooledRecords()[0]['request_id']);
    }

    public function test_invalid_request_id_is_replaced_and_context_is_cleaned_up(): void
    {
        $response = $this->withHeader('X-Request-ID', 'invalid request id')->getJson('/request-id');
        $requestId = $response->headers->get('X-Request-ID');

        $response->assertOk()->assertJsonPath('request_id', $requestId);
        $this->assertIsString($requestId);
        $this->assertMatchesRegularExpression('/\A[0-9a-f-]{36}\z/', $requestId);

        Log::channel('loghub')->warning('Outside request.');
        $records = $this->spooledRecords();

        $this->assertSame($requestId, $records[0]['request_id']);
        $this->assertNull($records[1]['request_id']);
    }

    public function test_context_is_cleaned_up_when_the_request_throws(): void
    {
        $this->withoutExceptionHandling();

        try {
            $this->withHeader('X-Request-ID', 'failed-request')->get('/request-id-error');
            $this->fail('The route should throw an exception.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Request failed.', $exception->getMessage());
        }

        Log::channel('loghub')->warning('After failed request.');

        $this->assertNull($this->spooledRecords()[0]['request_id']);
    }

    /** @return list<array<string, mixed>> */
    private function spooledRecords(): array
    {
        $lines = file($this->temporaryDirectory.'/active.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        $this->assertNotFalse($lines);

        return array_map(
            fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
            $lines,
        );
    }
}
