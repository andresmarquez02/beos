<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\ApiRequest;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ForceJsonResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        if (ApiRequest::matches($request)) {
            $request->headers->set('Accept', 'application/json');
        }

        return $next($request);
    }
}
