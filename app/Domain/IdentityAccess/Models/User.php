<?php

namespace App\Domain\IdentityAccess\Models;

use App\Domain\HotelGroup\Models\Hotel;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role_id',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function hotels(): BelongsToMany
    {
        return $this->belongsToMany(Hotel::class, 'user_hotel_access')->withTimestamps();
    }

    public function hasRole(string $slug): bool
    {
        return $this->role?->slug === $slug;
    }

    public function isGroupOwner(): bool
    {
        return $this->hasRole(Role::GROUP_OWNER);
    }

    public function hasPermission(string $slug): bool
    {
        return $this->role !== null && $this->role->hasPermission($slug);
    }

    public function authorizedHotelIds(): array
    {
        return $this->hotels()->pluck('hotels.id')->all();
    }

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }
}
