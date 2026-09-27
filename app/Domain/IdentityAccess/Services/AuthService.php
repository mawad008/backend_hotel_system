<?php

namespace App\Domain\IdentityAccess\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\IdentityAccess\Exceptions\AccountInactiveException;
use App\Domain\IdentityAccess\Exceptions\InvalidCredentialsException;
use App\Domain\IdentityAccess\Models\User;
use App\Domain\IdentityAccess\Repositories\Contracts\UserRepositoryInterface;
use Illuminate\Support\Facades\Hash;

class AuthService
{
    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @return array{user: User, token: string}
     */
    public function attempt(string $email, string $password): array
    {
        $user = $this->users->findByEmail($email);

        if (! $user || ! Hash::check($password, $user->password)) {
            throw new InvalidCredentialsException;
        }

        if (! $user->is_active) {
            throw new AccountInactiveException;
        }

        $token = $user->createToken('api')->plainTextToken;

        $this->auditLogger->record($user, 'auth.login', $user);

        return ['user' => $user, 'token' => $token];
    }

    public function logout(User $user): void
    {
        $user->currentAccessToken()?->delete();

        $this->auditLogger->record($user, 'auth.logout', $user);
    }
}
