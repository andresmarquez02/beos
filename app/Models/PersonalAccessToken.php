<?php

declare(strict_types=1);

namespace App\Models;

use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

class PersonalAccessToken extends SanctumPersonalAccessToken
{
    /**
     * Rechaza todo token cuyo prefijo de id no pueda ser un bigint antes de que llegue a la base de datos.
     *
     * @param  string  $token
     * @return static|null
     */
    public static function findToken($token)
    {
        if (preg_match('/^[1-9][0-9]{0,17}\|/', $token) !== 1) {
            return null;
        }

        return parent::findToken($token);
    }
}
