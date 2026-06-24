<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class LogApiRequests
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Log incoming request
        Log::channel('api')->info('API Request', [
            'method' => $request->getMethod(),
            'path' => $request->path(),
            'url' => $request->url(),
            'ip' => $request->ip(),
            'headers' => $this->sanitizeHeaders($request->headers->all()),
            'body' => $this->sanitizeBody($request->all()),
            'timestamp' => now()->toDateTimeString(),
        ]);

        $response = $next($request);

        // Log response
        Log::channel('api')->info('API Response', [
            'method' => $request->getMethod(),
            'path' => $request->path(),
            'status' => $response->getStatusCode(),
            'content_type' => $response->headers->get('content-type'),
            'body' => $this->getResponseContent($response),
            'timestamp' => now()->toDateTimeString(),
        ]);

        return $response;
    }

    /**
     * Sanitize sensitive headers.
     */
    private function sanitizeHeaders(array $headers): array
    {
        $sensitive = ['authorization', 'cookie', 'x-api-key'];
        foreach ($headers as $key => $value) {
            if (in_array(strtolower($key), $sensitive)) {
                $headers[$key] = ['***REDACTED***'];
            }
        }
        return $headers;
    }

    /**
     * Sanitize sensitive body fields.
     */
    private function sanitizeBody(array $body): array
    {
        $sensitive = ['password', 'token', 'secret', 'api_key'];
        foreach ($body as $key => $value) {
            if (in_array(strtolower($key), $sensitive)) {
                $body[$key] = '***REDACTED***';
            }
        }
        return $body;
    }

    /**
     * Extract response content safely.
     */
    private function getResponseContent(Response $response): string
    {
        try {
            $content = $response->getContent();
            if (is_string($content)) {
                // Limit to 1000 chars to keep logs manageable
                return strlen($content) > 1000 ? substr($content, 0, 1000) . '...' : $content;
            }
        } catch (\Exception $e) {
            return 'Unable to extract content';
        }
        return '';
    }
}
