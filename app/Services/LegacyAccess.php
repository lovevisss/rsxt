<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class LegacyAccess
{
    public function canOpen(User $user, int $menuId): bool
    {
        if ((int) $user->IsSA === 1) {
            return true;
        }

        return DB::connection('legacy')->table('userroles as ur')
            ->join('treeroles as tr', 'tr.roleid', '=', 'ur.roleId')
            ->where('ur.userId', $user->getAuthIdentifier())
            ->where('tr.treeid', $menuId)
            ->exists();
    }

    public function canOpenModule(User $user, string $module): bool
    {
        if (in_array($module, ['position_admin', 'grade_admin', 'department_admin', 'admin_accounts'], true)) {
            return app(PositionAccess::class)->isAdmin($user);
        }
        $definition = config("personnel.modules.$module");

        if ($module === 'assessment_review') {
            return $this->canOpen($user, (int) $definition['menu_id'])
                && DB::connection('legacy')->table('gz_dpleaders')
                    ->where('userId', $user->Id)->where('yndelete', '0')->exists();
        }

        if (isset($definition['role_id'])) {
            return DB::connection('legacy')->table('userroles')
                ->where('userId', $user->getAuthIdentifier())->where('roleId', $definition['role_id'])->exists();
        }
        if (isset($definition['all_menu_ids'])) {
            foreach ($definition['all_menu_ids'] as $menuId) {
                if (! $this->canOpen($user, (int) $menuId)) {
                    return false;
                }
            }

            return true;
        }

        foreach ($definition['menu_ids'] ?? [$definition['menu_id']] as $menuId) {
            if ($this->canOpen($user, (int) $menuId)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, array<string, mixed>> */
    public function availableModules(User $user): array
    {
        return array_filter(
            config('personnel.modules'),
            fn (array $module, string $key) => $this->canOpenModule($user, $key),
            ARRAY_FILTER_USE_BOTH,
        );
    }
}
