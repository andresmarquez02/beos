<?php

use App\Enums\ErrorCode;
use App\Exceptions\ConflictException;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\ForceJsonResponse;
use App\Http\Middleware\LimitPayloadSize;
use App\Http\Middleware\RejectMalformedJson;
use App\Http\Middleware\SecurityHeaders;
use App\Support\ApiError;
use App\Support\ApiRequest;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend([
            AssignRequestId::class,
            ForceJsonResponse::class,
            SecurityHeaders::class,
            LimitPayloadSize::class,
            RejectMalformedJson::class,
        ]);
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontReport(ConflictException::class);
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => ApiRequest::matches($request));

        $exceptions->render(fn (ValidationException $e, Request $request) => ApiError::for(
            $request, 422, ErrorCode::ValidationError, 'The given data was invalid.', $e->errors(),
        ));
        $exceptions->render(fn (AuthenticationException $e, Request $request) => ApiError::for(
            $request, 401, ErrorCode::Unauthenticated, $e->getMessage(), headers: ['WWW-Authenticate' => 'Bearer'],
        ));
        $exceptions->render(fn (ConflictException $e, Request $request) => ApiError::for(
            $request, 409, ErrorCode::Conflict, $e->getMessage(),
        ));
        $exceptions->render(fn (HttpExceptionInterface $e, Request $request) => ApiError::fromHttpException($request, $e));
        $exceptions->render(fn (Throwable $e, Request $request) => ApiError::for(
            $request, 500, ErrorCode::ServerError, 'Server error.',
        ));
    })->create();
