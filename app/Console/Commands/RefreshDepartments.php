<?php

namespace App\Console\Commands;

use App\Services\DepartmentWriter;
use Illuminate\Console\Command;

class RefreshDepartments extends Command
{
    protected $signature = 'personnel:refresh-departments {--date=}';

    protected $description = 'Activate dated personnel department changes';

    public function handle(DepartmentWriter $writer): int
    {
        $date = $this->option('date');
        if ($date !== null && (! is_string($date) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date))) {
            $this->error('Date must be YYYY-MM-DD.');

            return self::FAILURE;
        }
        $count = $writer->refreshDue($date);
        $this->info("Updated {$count} personnel departments.");

        return self::SUCCESS;
    }
}
