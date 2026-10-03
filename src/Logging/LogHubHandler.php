<?php

namespace Ojcloveu\LogHub\Logging;

use DateTimeZone;
use Monolog\Formatter\NormalizerFormatter;
use Monolog\Handler\AbstractHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Ojcloveu\LogHub\Spool\SpoolWriter;
use Throwable;

final class LogHubHandler extends AbstractHandler
{
    private NormalizerFormatter $normalizer;

    public function __construct(
        private readonly SpoolWriter $spool,
        private readonly int $maxRecordBytes = 65_536,
        private readonly ?string $environment = null,
        Level|string|int $level = Level::Debug,
        bool $bubble = true,
    ) {
        parent::__construct($level, $bubble);

        $this->normalizer = new NormalizerFormatter;
    }

    public function handle(LogRecord $record): bool
    {
        if (! $this->isHandling($record)) {
            return false;
        }

        try {
            $this->spool->append($this->encode($record));
        } catch (Throwable) {
            // The host application's other log channels must remain unaffected.
        }

        return ! $this->getBubble();
    }

    private function encode(LogRecord $record): string
    {
        $normalized = $this->normalizer->format($record);
        $context = is_array($normalized['context'] ?? null) ? $normalized['context'] : [];
        $extra = is_array($normalized['extra'] ?? null) ? $normalized['extra'] : [];
        $limit = max(512, $this->maxRecordBytes);
        $payload = [
            'level' => strtolower($record->level->getName()),
            'message' => $record->message,
            'context' => $context,
            'environment' => $this->environment,
            'server' => gethostname() ?: null,
            'request_id' => $context['request_id'] ?? $extra['request_id'] ?? null,
            'user_id' => $context['user_id'] ?? $extra['user_id'] ?? null,
            'url' => $context['url'] ?? $extra['url'] ?? null,
            'ip' => $context['ip'] ?? $extra['ip'] ?? null,
            'user_agent' => $context['user_agent'] ?? $extra['user_agent'] ?? null,
            'trace' => $context['trace'] ?? null,
            'occurred_at' => $record->datetime
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s.v\Z'),
        ];

        $json = $this->toJson($payload);

        if (strlen($json) > $limit) {
            $payload['context'] = ['_truncated' => true];
            $payload['trace'] = null;
            $json = $this->toJson($payload);
        }

        while (strlen($json) > $limit && $payload['message'] !== '') {
            $excess = strlen($json) - $limit;
            $payload['message'] = mb_strcut(
                $payload['message'],
                0,
                max(0, strlen($payload['message']) - $excess - 1),
                'UTF-8',
            );
            $json = $this->toJson($payload);
        }

        if (strlen($json) > $limit) {
            return $this->toJson([
                'level' => strtolower($record->level->getName()),
                'message' => '',
                'context' => ['_truncated' => true],
                'occurred_at' => $payload['occurred_at'],
            ]);
        }

        return $json;
    }

    /** @param array<string, mixed> $payload */
    private function toJson(array $payload): string
    {
        return json_encode(
            $payload,
            JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );
    }
}
