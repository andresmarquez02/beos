<?php

declare(strict_types=1);

namespace App\Enums;

enum ErrorCode: string
{
    case ValidationError = 'validation_error';
    case Unauthenticated = 'unauthenticated';
    case Forbidden = 'forbidden';
    case NotFound = 'not_found';
    case MethodNotAllowed = 'method_not_allowed';
    case Conflict = 'conflict';
    case PayloadTooLarge = 'payload_too_large';
    case TooManyRequests = 'too_many_requests';
    case BadRequest = 'bad_request';
    case ServerError = 'server_error';
}
