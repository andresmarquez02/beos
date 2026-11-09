<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;

final class ApiRequest
{
    /**
     * Comprobación de prefijo por bytes: a diferencia de Request::is(), sigue funcionando cuando la ruta no es UTF-8 válido.
     */
    public static function matches(Request $request): bool
    {
        return str_starts_with(rawurldecode($request->path()), 'api/');
    }
}
