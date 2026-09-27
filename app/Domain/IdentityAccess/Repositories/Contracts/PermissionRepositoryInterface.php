<?php

namespace App\Domain\IdentityAccess\Repositories\Contracts;

use Illuminate\Support\Collection;

interface PermissionRepositoryInterface
{
    public function all(): Collection;
}
