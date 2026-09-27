<?php

namespace App\Domain\IdentityAccess\Repositories;

use App\Domain\IdentityAccess\Models\Permission;
use App\Domain\IdentityAccess\Repositories\Contracts\PermissionRepositoryInterface;
use Illuminate\Support\Collection;

class EloquentPermissionRepository implements PermissionRepositoryInterface
{
    public function all(): Collection
    {
        return Permission::all();
    }
}
