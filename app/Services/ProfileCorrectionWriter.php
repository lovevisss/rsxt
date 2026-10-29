<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use stdClass;

class ProfileCorrectionWriter
{
    public function available(): bool
    {
        try {
            $this->connectionName();

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    public function connectionName(): string
    {
        $target = config('profile_corrections.write_target');
        if ($target === 'stage') {
            $stage = config('database.connections.legacy_stage_writer');
            $production = config('database.connections.legacy');
            if ($stage['host'] === $production['host'] && $stage['database'] === $production['database']) {
                throw new RuntimeException('The isolated stage database is not configured safely.');
            }

            return 'legacy_stage_writer';
        }
        if ($target === 'production' && config('profile_corrections.production_writes_enabled')
            && config('database.connections.legacy_profile_writer.username') !== '') {
            return 'legacy_profile_writer';
        }

        throw new RuntimeException('Profile correction writes are disabled.');
    }

    /** @param array<int, stdClass> $changes */
    public function apply(stdClass $request, array $changes): void
    {
        $connection = DB::connection($this->connectionName());
        $newName = null;
        foreach ($changes as $change) {
            if ($change->field_key === 'h5001') {
                $newName = $change->new_raw;
                $identity = $connection->table('user')->where('Id', $request->applicant_user_id)
                    ->where('username', $request->employee_number)->first();
                if ($identity === null) {
                    throw new ProfileCorrectionConflict('The matching portal identity is unavailable.');
                }
            }
        }
        $connection->transaction(function () use ($connection, $request, $changes): void {
            $profile = $connection->table('h50')->where('Id', $request->legacy_profile_id)
                ->where('h5002', $request->employee_number)->where('yndelete', '0')
                ->lockForUpdate()->first();
            if ($profile === null) {
                throw new ProfileCorrectionConflict('The original personnel record is unavailable.');
            }
            $allOld = true;
            $allNew = true;
            $updates = [];
            foreach ($changes as $change) {
                $current = $profile->{$change->field_key} === '' ? null : $profile->{$change->field_key};
                $allOld = $allOld && $current === $change->old_raw;
                $allNew = $allNew && $current === $change->new_raw;
                $updates[$change->field_key] = $change->new_raw;
            }
            if (! $allOld && ! $allNew) {
                throw new ProfileCorrectionConflict('The personnel record changed after this request was submitted.');
            }
            if ($allOld) {
                $connection->table('h50')->where('Id', $request->legacy_profile_id)
                    ->where('h5002', $request->employee_number)
                    ->update([...$updates, 'updateTime' => now()]);
            }
        });
        if ($newName !== null) {
            // The legacy user table is MyISAM, so this step runs after the h50 transaction.
            // A failure remains retryable: h50 then matches all new values on the next attempt.
            $identity = $connection->table('user')->where('Id', $request->applicant_user_id)
                ->where('username', $request->employee_number)->first();
            if ($identity === null) {
                throw new RuntimeException('The matching portal identity could not be synchronized.');
            }
            if ($identity->truename !== $newName) {
                $connection->table('user')->where('Id', $request->applicant_user_id)
                    ->where('username', $request->employee_number)->update(['truename' => $newName]);
            }
        }
    }
}
