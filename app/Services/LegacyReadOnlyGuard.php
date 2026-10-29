<?php

namespace App\Services;

use Illuminate\Database\Connection;
use RuntimeException;

class LegacyReadOnlyGuard
{
    public function attach(Connection $connection): void
    {
        $connection->beforeExecuting(function (string $sql): void {
            if (config('personnel.legacy_write_enabled')) {
                return;
            }

            $query = ltrim($sql);
            $reads = preg_match('/^(SELECT|SHOW|DESCRIBE|EXPLAIN|PRAGMA)\b/i', $query) === 1;
            $fileWrite = preg_match('/\bINTO\s+(?:OUTFILE|DUMPFILE)\b/i', $query) === 1;
            if (! $reads || $fileWrite || str_contains($query, ';')) {
                throw new RuntimeException('Legacy business database writes are disabled until role and workflow acceptance is complete.');
            }
        });
    }
}
