<?php

namespace App\Console\Commands;

use App\Services\GradeWriter;
use Illuminate\Console\Command;

class RefreshGradeLevels extends Command
{
    protected $signature = 'personnel:refresh-grade-levels {--date=}';

    protected $description = 'Recompute current grade levels after appointment end dates';

    public function handle(GradeWriter $writer): int
    {
        $date = $this->option('date');
        if ($date !== null && (! is_string($date) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date))) {
            $this->error('Date must be YYYY-MM-DD.');

            return self::FAILURE;
        }
        $count = $writer->refreshExpired($date);
        $this->info("Updated {$count} personnel profiles.");

        return self::SUCCESS;
    }
}
