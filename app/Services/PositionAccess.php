<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PositionAccess
{
    public function isAdmin(User $user): bool
    {
        if (app()->environment('testing') && ! Schema::hasTable('personnel_admins')) {
            return false;
        }

        return (int) $user->getAttribute('isUse') === 1
            && DB::table('personnel_admins')->where('employee_number', $user->username)->exists();
    }

    public function requireAdmin(User $user): void
    {
        abort_unless($this->isAdmin($user), 403);
    }
}
