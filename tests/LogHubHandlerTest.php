<?php

namespace Ojcloveu\LogHub\Tests;

use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Ojcloveu\LogHub\Logging\LogHubHandler;
use Ojcloveu\LogHub\Spool\SpoolWriter;
use RuntimeException;

final class LogHubHandlerTest extends TestCase
{
    private string $temporaryDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->temporaryDirectory = sys_get_temp_dir().'/loghub-client-'.bin2hex(random_bytes(8));
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

    public function test_handler_writes_a_bounded_json_line_and_allows_local_logging(): void
    {
        $localStream = fopen('php://memory', 'w+b');
        $logger = new Logger('test', [
            new LogHubHandler(new SpoolWriter($this->temporaryDirectory), 512, 'testing'),
            new StreamHandler($localStream, Level::Debug),
        ]);

        $logger->error(str_repeat('message', 200), [
            'request_id' => 'request-123',
            'large' => str_repeat('x', 2_000),
        ]);

        rewind($localStream);
        $this->assertStringContainsString('messagemessage', stream_get_contents($localStream));

        $line = trim((string) file_get_contents($this->temporaryDirectory.'/active.jsonl'));
        $payload = json_decode($line, true, flags: JSON_THROW_ON_ERROR);

        $this->assertLessThanOrEqual(512, strlen($line));
        $this->assertSame('error', $payload['level']);
        $this->assertSame('request-123', $payload['request_id']);
        $this->assertSame(['_truncated' => true], $payload['context']);
    }

    public function test_spool_failure_never_breaks_other_log_handlers(): void
    {
        $spool = $this->createMock(SpoolWriter::class);
        $spool->expects($this->once())
            ->method('append')
            ->willThrowException(new RuntimeException('disk unavailable'));
        $localStream = fopen('php://memory', 'w+b');
        $logger = new Logger('test', [
            new LogHubHandler($spool),
            new StreamHandler($localStream, Level::Debug),
        ]);

        $logger->warning('Local logging survives.');

        rewind($localStream);
        $this->assertStringContainsString('Local logging survives.', stream_get_contents($localStream));
    }
}
