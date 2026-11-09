<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class ApiError
{
    private const array LOGGED = [ErrorCode::Unauthenticated, ErrorCode::Forbidden, ErrorCode::TooManyRequests];

    /**
     * @param  array<string, list<string>>|null  $errors
     * @param  array<string, mixed>  $headers
     */
    public static function for(
        Request $request,
        int $status,
        ErrorCode $code,
        string $message,
        ?array $errors = null,
        array $headers = [],
    ): ?JsonResponse {
        if (! ApiRequest::matches($request)) {
            return null;
        }

        if (in_array($code, self::LOGGED, true)) {
            Log::warning('API request rejected.', [
                'code' => $code->value,
                'status' => $status,
                'method' => $request->method(),
                'path' => $request->path(),
                'ip' => $request->ip(),
                'user_id' => $request->user()?->getAuthIdentifier(),
            ]);
        }

        $body = ['message' => $message, 'code' => $code->value];

        if ($errors !== null) {
            $body['errors'] = $errors;
        }

        $body['request_id'] = $request->attributes->get('request_id');

        return new JsonResponse($body, $status, $headers);
    }

    public static function fromHttpException(Request $request, HttpExceptionInterface $e): ?JsonResponse
    {
        $status = $e->getStatusCode();

        [$code, $message] = match (true) {
            $status === 403 => [ErrorCode::Forbidden, 'This action is unauthorized.'],
            $status === 404 => [ErrorCode::NotFound, 'Resource not found.'],
            $status === 405 => [ErrorCode::MethodNotAllowed, 'Method not allowed.'],
            $status === 413 => [ErrorCode::PayloadTooLarge, 'Payload too large.'],
            $status === 429 => [ErrorCode::TooManyRequests, 'Too many requests.'],
            $status >= 500 => [ErrorCode::ServerError, 'Server error.'],
            default => [ErrorCode::BadRequest, 'Bad request.'],
        };

        /** @var array<string, mixed> $headers */
        $headers = $e->getHeaders();

        return self::for($request, $status, $code, $message, headers: $headers);
    }
}
