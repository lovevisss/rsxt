<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use stdClass;

class LeaveAccess
{
    public function __construct(private readonly LegacyAccess $legacy, private readonly LeaveWriter $writer) {}

    public function canApply(User $user): bool
    {
        return $this->legacy->canOpen($user, 408);
    }

    public function canCancel(User $user): bool
    {
        return $this->legacy->canOpen($user, 426);
    }

    public function canReview(User $user, string $kind, stdClass $row): bool
    {
        if (! $this->legacy->canOpen($user, 412) || (int) $row->CreatorId === (int) $user->Id
            || $row->issubmit !== 'y') {
            return false;
        }
        $current = trim((string) ($kind === 'a34' ? $row->a3433 : $row->a3833));
        if ($current === '' || $current === '审批结束' || $current !== (string) $user->truename) {
            return false;
        }

        return DB::connection($this->writer->readConnectionName())->table('gz_auditors')
            ->where('tablename', $kind)->where('tableId', $row->Id)
            ->where('auditorId', $user->Id)->where('auditorName', $current)->exists();
    }

    public function canSee(User $user, string $kind, stdClass $row): bool
    {
        if ((int) $row->CreatorId === (int) $user->Id) {
            return $kind === 'a34' ? $this->legacy->canOpen($user, 409) || $this->canApply($user)
                : $this->canCancel($user);
        }
        if ($this->canReview($user, $kind, $row)) {
            return true;
        }
        if (($this->legacy->canOpen($user, 412) || $this->legacy->canOpen($user, 413))
            && DB::connection($this->writer->readConnectionName())->table('gz_auditors')
                ->where('tablename', $kind)->where('tableId', $row->Id)
                ->where('auditorId', $user->Id)->exists()) {
            return true;
        }
        if ($this->legacy->canOpen($user, 413) && DB::table('leave_events')
            ->where('source', $this->writer->sourceName())->where('kind', $kind)
            ->where('legacy_id', $row->Id)->where('actor_user_id', $user->Id)
            ->whereIn('action', ['approved', 'returned'])->exists()) {
            return true;
        }
        if ($kind === 'a34' && $this->legacy->canOpen($user, 411)) {
            return DB::connection($this->writer->readConnectionName())->table('a35')
                ->where('parentid', $row->Id)->where('a3511', (string) $user->Id)
                ->where('yndelete', '0')->exists();
        }

        return false;
    }
}
