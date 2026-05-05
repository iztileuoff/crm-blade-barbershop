<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'phone', 'password', 'role'])]
#[Hidden(['password'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'role' => \App\Enums\Role::class,
        ];
    }

    public function isAdmin(): bool
    {
        return in_array($this->role, [\App\Enums\Role::ADMIN, \App\Enums\Role::SUPER_ADMIN], true);
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === \App\Enums\Role::SUPER_ADMIN;
    }
}
