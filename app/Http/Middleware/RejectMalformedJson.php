<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\ApiRequest;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class RejectMalformedJson
{
    public function handle(Request $request, Closure $next): Response
    {
        if (ApiRequest::matches($request) && $request->isJson()) {
            $body = $request->getContent();

            if ($body !== '' && ! json_validate($body)) {
                throw new BadRequestHttpException('Malformed JSON body.');
            }
        }

        return $next($request);
    }
}
