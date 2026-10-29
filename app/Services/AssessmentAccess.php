<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use stdClass;

class AssessmentAccess
{
    public function __construct(private readonly LegacyAccess $legacyAccess, private readonly AssessmentWriter $writer) {}

    public function canViewOwn(User $user, stdClass $record): bool
    {
        return $this->legacyAccess->canOpenModule($user, 'assessments')
            && (string) $record->h2002 === (string) $user->username;
    }

    public function canReview(User $user, stdClass $record): bool
    {
        if (! $this->legacyAccess->canOpenModule($user, 'assessment_review')
            || (string) $record->h2002 === (string) $user->username) {
            return false;
        }

        return DB::connection($this->writer->readConnectionName())->table('gz_dpleaders as leader')
            ->join('department as department', 'department.Id', '=', 'leader.departmentid')
            ->where('leader.userId', $user->Id)
            ->where('leader.yndelete', '0')
            ->where('department.objname', $record->h2003)
            ->exists();
    }

    /** @return list<string> */
    public function reviewDepartmentCodes(User $user): array
    {
        if (! $this->legacyAccess->canOpenModule($user, 'assessment_review')) {
            return [];
        }

        return array_values(DB::connection($this->writer->readConnectionName())->table('gz_dpleaders as leader')
            ->join('department as department', 'department.Id', '=', 'leader.departmentid')
            ->where('leader.userId', $user->Id)->where('leader.yndelete', '0')
            ->pluck('department.objname')->map(fn ($code) => (string) $code)->all());
    }
}
