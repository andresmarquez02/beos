<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\ApiRequest;
use Closure;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LimitPayloadSize
{
    public function handle(Request $request, Closure $next): Response
    {
        if (ApiRequest::matches($request)) {
            $maxBytes = (int) config('api.max_payload_kb') * 1024;
            $declared = (int) $request->header('Content-Length', '0');

            if ($declared > $maxBytes || strlen($request->getContent()) > $maxBytes) {
                throw new PostTooLargeException;
            }
        }

        return $next($request);
    }
}
