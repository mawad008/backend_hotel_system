<?php

namespace App\Domain\IdentityAccess\Models;

use Database\Factories\PermissionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Permission extends Model
{
    use HasFactory;

    protected $fillable = [
        'name_en',
        'name_ar',
        'slug',
        'description_en',
        'description_ar',
    ];

    /**
     * The permission's module/group, derived from the slug prefix before the
     * first dot (e.g. "hotels.manage" -> "hotels"). Every seeded slug
     * follows this convention, so no separate DB column is needed.
     */
    public function group(): string
    {
        return explode('.', $this->slug, 2)[0];
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'permission_role');
    }

    protected static function newFactory(): PermissionFactory
    {
        return PermissionFactory::new();
    }
}
