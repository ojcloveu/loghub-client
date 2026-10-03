<?php

namespace Ojcloveu\LogHub\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Log\LogManager;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final readonly class AssignRequestId
{
    public function __construct(private LogManager $logs) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $header = (string) config('loghub-client.request_id.header', 'X-Request-ID');
        $incoming = $request->headers->get($header);
        $requestId = is_string($incoming) && preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/', $incoming) === 1
            ? $incoming
            : (string) Str::uuid();

        $request->attributes->set('request_id', $requestId);
        $previousContext = $this->logs->sharedContext();
        $this->logs->shareContext(['request_id' => $requestId]);

        try {
            $response = $next($request);
            $response->headers->set($header, $requestId);

            return $response;
        } finally {
            $sharedContext = $this->logs->sharedContext();
            unset($sharedContext['request_id']);

            if (array_key_exists('request_id', $previousContext)) {
                $sharedContext['request_id'] = $previousContext['request_id'];
            }

            $this->logs->withoutContext(['request_id']);
            $this->logs->flushSharedContext();

            if ($sharedContext !== []) {
                $this->logs->shareContext($sharedContext);
            }
        }
    }
}
