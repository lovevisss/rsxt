<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class InventoryLegacy extends Command
{
    protected $signature = 'personnel:inventory {--output= : CSV output path, defaults to docs/legacy-inventory.csv}';

    protected $description = 'Create a personal-data-free menu and permission inventory from the legacy database';

    public function handle(): int
    {
        $menus = DB::connection('legacy')->table('tree')
            ->where('yndelete', '0')
            ->orderBy('seqNo')
            ->orderBy('Id')
            ->get(['Id', 'parentId', 'caption', 'action'])
            ->keyBy('Id');

        $coverage = DB::connection('legacy')->table('treeroles as tr')
            ->join('userroles as ur', 'ur.roleId', '=', 'tr.roleid')
            ->join('user as u', 'u.Id', '=', 'ur.userId')
            ->where('u.isUse', 1)
            ->select('tr.treeid')
            ->selectRaw('COUNT(DISTINCT u.Id) AS users_count')
            ->selectRaw('COUNT(DISTINCT tr.roleid) AS roles_count')
            ->groupBy('tr.treeid')
            ->get()
            ->keyBy('treeid');

        $option = $this->option('output');
        $output = is_string($option) && $option !== '' ? $option : base_path('docs/legacy-inventory.csv');
        $directory = dirname($output);
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException("Cannot create directory: {$directory}");
        }

        $file = fopen($output, 'wb');
        if ($file === false) {
            throw new RuntimeException("Cannot open inventory file: {$output}");
        }

        try {
            fwrite($file, "\xEF\xBB\xBF");
            fputcsv($file, ['menu_id', 'parent_menu', 'menu_name', 'active_users_with_access', 'roles_with_access', 'implementation', 'verification']);

            foreach ($menus as $menu) {
                if ((int) $menu->Id === 1 || (int) $menu->Id === 5) {
                    continue;
                }
                $isLeaf = ! $menus->contains(fn ($candidate) => (int) $candidate->parentId === (int) $menu->Id);
                $module = in_array((int) $menu->Id, array_column(config('personnel.modules'), 'menu_id'), true);
                fputcsv($file, [
                    $menu->Id,
                    $menus->has($menu->parentId) ? $menus[$menu->parentId]->caption : '',
                    $menu->caption,
                    $coverage->has($menu->Id) ? $coverage[$menu->Id]->users_count : 0,
                    $coverage->has($menu->Id) ? $coverage[$menu->Id]->roles_count : 0,
                    $module ? 'employee_read_only' : ($isLeaf ? 'not_implemented' : 'navigation'),
                    $module ? 'employee_screen_checked' : 'requires_role_and_workflow_review',
                ]);
            }
        } finally {
            fclose($file);
        }

        $this->info("Inventory written: {$output}");

        return self::SUCCESS;
    }
}
