<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * An admin of the site (the "admin" guard). Admins live in their own table and
 * are created with `php artisan admin:set-password`; there is no sign-up.
 */
#[Fillable(['username', 'password'])]
#[Hidden(['password', 'remember_token'])]
class Admin extends Authenticatable
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        // Assigning a plain password stores its bcrypt hash.
        return ['password' => 'hashed'];
    }
}
