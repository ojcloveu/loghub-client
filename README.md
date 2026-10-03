# LogHub Client for Laravel

A fail-open Laravel logging client for LogHub. It writes normalized Monolog records to a bounded local JSONL spool before attempting delivery, so application logging remains available during network or LogHub outages.

## Requirements

- PHP 8.2 or later
- Laravel 11, 12, or 13
- Monolog 3

## Installation

Install the package with Composer:

```bash
composer require ojcloveu/loghub-client
php artisan vendor:publish --tag=loghub-client-config
```

Laravel discovers `Ojcloveu\LogHub\LogHubServiceProvider` automatically.

## Logging Channel

Add LogHub as an additional channel so local logging remains independent:

```php
// config/logging.php
'channels' => [
    'stack' => [
        'driver' => 'stack',
        'channels' => ['single', 'loghub'],
    ],

    'loghub' => [
        'driver' => 'loghub',
        'level' => env('LOGHUB_CLIENT_LEVEL', 'debug'),
        'bubble' => true,
    ],
],
```

## Configuration

Set the LogHub endpoint and the one-time project API key:

```dotenv
LOGHUB_CLIENT_URL=https://logs.example.com
LOGHUB_CLIENT_API_KEY=lh_PROJECT_KEY
LOGHUB_CLIENT_ENVIRONMENT=production
```

Optional delivery settings are available in the published `config/loghub-client.php` file. They include spool size limits, batch size, request timeouts, retry limits, queue integration, and scheduled flushing.

## Delivering Logs

Flush the durable local spool directly:

```bash
php artisan loghub:flush
php artisan loghub:flush --max-batches=3
```

To run an overlap-protected flush every minute through Laravel's scheduler:

```dotenv
LOGHUB_CLIENT_SCHEDULE_ENABLED=true
```

Your host must run `php artisan schedule:run` every minute.

To dispatch flushes through a host queue instead:

```dotenv
LOGHUB_CLIENT_QUEUE_ENABLED=true
LOGHUB_CLIENT_QUEUE_CONNECTION=redis
LOGHUB_CLIENT_QUEUE=loghub
```

A worker must consume the configured connection and queue. The default spool mode does not require the host application's database or Redis.

## Request IDs

Request-ID middleware is enabled by default. It accepts a valid `X-Request-ID` header or generates a ULID, shares the ID with Laravel's log context, returns it in the response, and clears the context after the request.

Valid upstream IDs contain 1-128 letters, numbers, periods, underscores, colons, or hyphens. Configure or disable this behavior through `loghub-client.request_id`.

## Failure Behavior

The handler catches delivery and spool errors so LogHub never breaks the host application's other log channels. Failed deliveries remain in the bounded spool with retry metadata. Keep a local or stderr channel in the logging stack for independent diagnostics.

## Testing

```bash
composer install
composer test
```

## License

LogHub Client is open-sourced software licensed under the MIT license.
