<?php

namespace App\Auth;

use App\Models\User;
use App\Services\LegacyAccess;
use Illuminate\Contracts\Auth\Authenticatable;
use Lovevisss\LaravelZufedfcCas\Contracts\CasIdentityResolver as ResolverContract;
use Lovevisss\LaravelZufedfcCas\Data\CasIdentity;

final class CasIdentityResolver implements ResolverContract
{
    public function __construct(private readonly LegacyAccess $access) {}

    public function resolve(CasIdentity $identity): ?Authenticatable
    {
        $user = User::query()
            ->where('username', $identity->account)
            ->where('isUse', 1)
            ->first();

        return $user !== null && $this->access->availableModules($user) !== [] ? $user : null;
    }
}
