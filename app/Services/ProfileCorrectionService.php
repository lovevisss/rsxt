<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;
use Throwable;

class ProfileCorrectionService
{
    public function __construct(private readonly ProfileCorrectionCatalog $catalog, private readonly ProfileCorrectionWriter $writer) {}

    /** @param array<string,mixed> $input
     * @param  array<int,UploadedFile>  $files
     */
    public function submit(User $user, array $input, string $reason, array $files): int
    {
        $fields = $this->catalog->fields();
        $options = $this->catalog->options();
        $keys = array_keys($input);
        if ($keys === [] || count($keys) > count($fields) || array_diff($keys, array_keys($fields))) {
            throw ValidationException::withMessages(['changes' => '请选择允许更正的档案字段。']);
        }
        $profile = DB::connection('legacy')->table('h50')->where('h5002', $user->username)
            ->where('yndelete', '0')->first(['Id', ...$keys]);
        abort_if($profile === null, 404);

        $changes = [];
        foreach ($input as $field => $value) {
            if (! is_string($value) && $value !== null) {
                throw ValidationException::withMessages(['changes' => '字段值格式无效。']);
            }
            $new = trim((string) $value);
            $new = $new === '' ? null : $new;
            $old = $profile->{$field} === '' ? null : $profile->{$field};
            if ($new === $old) {
                continue;
            }
            $this->validateValue($field, $new, $options);
            $changes[] = ['field_key' => $field, 'field_label' => $fields[$field]['label'], 'old_raw' => $old, 'new_raw' => $new];
        }
        if ($changes === []) {
            throw ValidationException::withMessages(['changes' => '请至少修改一项档案内容。']);
        }
        if ($files === [] && array_intersect(array_column($changes, 'field_key'), config('profile_corrections.evidence_required'))) {
            throw ValidationException::withMessages(['evidence' => '这些字段需要上传证明材料。']);
        }

        $stored = [];
        try {
            return DB::transaction(function () use ($user, $profile, $reason, $changes, $files, &$stored): int {
                if (DB::table('profile_correction_requests')->where('active_employee_number', $user->username)->exists()) {
                    throw ValidationException::withMessages(['changes' => '您已有一张待处理的更正申请。']);
                }
                $id = DB::table('profile_correction_requests')->insertGetId([
                    'applicant_user_id' => $user->Id, 'employee_number' => $user->username,
                    'legacy_profile_id' => $profile->Id, 'active_employee_number' => $user->username,
                    'status' => 'pending', 'reason' => $reason, 'created_at' => now(), 'updated_at' => now(),
                ]);
                foreach ($changes as $change) {
                    DB::table('profile_correction_changes')->insert(['request_id' => $id, ...$change]);
                }
                foreach ($files as $file) {
                    $path = 'profile-corrections/'.$id.'/'.Str::uuid();
                    if (Storage::disk('local')->putFileAs('profile-corrections/'.$id, $file, basename($path)) === false) {
                        throw new \RuntimeException('Unable to store correction evidence.');
                    }
                    $stored[] = $path;
                    DB::table('profile_correction_files')->insert([
                        'request_id' => $id, 'disk' => 'local', 'path' => $path,
                        'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                        'mime' => $file->getMimeType(), 'size' => $file->getSize(),
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
                $this->event($id, $user->Id, 'submitted', $reason);

                return $id;
            });
        } catch (QueryException $error) {
            Storage::disk('local')->delete($stored);
            if (in_array((string) $error->getCode(), ['23000', '19'], true)) {
                throw ValidationException::withMessages(['changes' => '您已有一张待处理的更正申请。']);
            }
            throw $error;
        } catch (Throwable $error) {
            Storage::disk('local')->delete($stored);
            throw $error;
        }
    }

    public function withdraw(stdClass $request, User $user): void
    {
        DB::transaction(function () use ($request, $user): void {
            $updated = DB::table('profile_correction_requests')->where('id', $request->id)
                ->where('applicant_user_id', $user->Id)->where('status', 'pending')
                ->update(['status' => 'withdrawn', 'active_employee_number' => null, 'updated_at' => now()]);
            abort_unless($updated === 1, 409);
            $this->event($request->id, $user->Id, 'withdrawn');
        });
    }

    public function reject(stdClass $request, User $reviewer, string $note): void
    {
        DB::transaction(function () use ($request, $reviewer, $note): void {
            $updated = DB::table('profile_correction_requests')->where('id', $request->id)->where('status', 'pending')
                ->update(['status' => 'rejected', 'active_employee_number' => null, 'reviewer_user_id' => $reviewer->Id,
                    'review_note' => $note, 'reviewed_at' => now(), 'updated_at' => now()]);
            abort_unless($updated === 1, 409);
            $this->event($request->id, $reviewer->Id, 'rejected', $note);
        });
    }

    public function approve(stdClass $request, User $reviewer): void
    {
        abort_unless($this->writer->available(), 503, '档案写入尚未启用。');
        DB::transaction(function () use ($request, $reviewer): void {
            $updated = DB::table('profile_correction_requests')->where('id', $request->id)->where('status', 'pending')
                ->update(['status' => 'applying', 'reviewer_user_id' => $reviewer->Id,
                    'reviewed_at' => now(), 'updated_at' => now()]);
            abort_unless($updated === 1, 409);
            $this->event($request->id, $reviewer->Id, 'approved');
        });
        $this->apply($request->id, $reviewer->Id);
    }

    public function retry(stdClass $request, User $reviewer): void
    {
        abort_unless($this->writer->available(), 503, '档案写入尚未启用。');
        abort_unless($request->status === 'apply_failed'
            || ($request->status === 'applying' && now()->diffInMinutes($request->updated_at) >= 5), 409);
        DB::transaction(function () use ($request, $reviewer): void {
            $updated = DB::table('profile_correction_requests')->where('id', $request->id)
                ->where('status', $request->status)->where('updated_at', $request->updated_at)
                ->update(['status' => 'applying', 'apply_error' => null, 'updated_at' => now()]);
            abort_unless($updated === 1, 409);
            $this->event($request->id, $reviewer->Id, 'retry_requested');
        });
        $this->apply($request->id, $reviewer->Id);
    }

    private function apply(int $id, int $actorId): void
    {
        $request = DB::table('profile_correction_requests')->where('id', $id)->first();
        if ($request === null) {
            throw new \RuntimeException('Correction request is unavailable.');
        }
        $changes = DB::table('profile_correction_changes')->where('request_id', $id)->get()->all();
        try {
            $this->writer->apply($request, $changes);
            DB::transaction(function () use ($id, $actorId): void {
                DB::table('profile_correction_requests')->where('id', $id)->where('status', 'applying')
                    ->update(['status' => 'applied', 'active_employee_number' => null,
                        'applied_at' => now(), 'apply_error' => null, 'updated_at' => now()]);
                $this->event($id, $actorId, 'applied');
            });
        } catch (ProfileCorrectionConflict $error) {
            DB::transaction(function () use ($id, $actorId, $error): void {
                DB::table('profile_correction_requests')->where('id', $id)->where('status', 'applying')
                    ->update(['status' => 'conflict', 'active_employee_number' => null,
                        'apply_error' => $error->getMessage(), 'updated_at' => now()]);
                $this->event($id, $actorId, 'conflict', $error->getMessage());
            });
        } catch (Throwable $error) {
            report($error);
            DB::transaction(function () use ($id, $actorId): void {
                DB::table('profile_correction_requests')->where('id', $id)->where('status', 'applying')
                    ->update(['status' => 'apply_failed', 'apply_error' => '写入失败，等待管理员重试。', 'updated_at' => now()]);
                $this->event($id, $actorId, 'apply_failed', '写入失败，等待管理员重试。');
            });
        }
    }

    /** @param array<string, array<int, array{value:string,label:string}>> $options */
    private function validateValue(string $field, ?string $value, array $options): void
    {
        if ($field === 'h5001' && $value === null) {
            throw ValidationException::withMessages(["changes.$field" => '姓名不能留空。']);
        }
        $max = match ($field) {
            'h5065' => 1,
            'h5001' => 50, // The matching legacy user.truename column is varchar(50).
            default => 255,
        };
        if ($value !== null && mb_strlen($value) > $max) {
            throw ValidationException::withMessages(["changes.$field" => "内容不能超过 {$max} 字。"]);
        }
        if ($value !== null && in_array($field, config('profile_corrections.dates'), true)) {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            if (! $date || $date->format('Y-m-d') !== $value) {
                throw ValidationException::withMessages(["changes.$field" => '请输入有效日期（YYYY-MM-DD）。']);
            }
        }
        if ($field === 'h5087' && $value !== null && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            throw ValidationException::withMessages(["changes.$field" => '请输入有效电子邮箱。']);
        }
        if (! $this->catalog->valid($field, $value, $options)) {
            throw ValidationException::withMessages(["changes.$field" => '请选择有效的字典值。']);
        }
    }

    private function event(int $requestId, ?int $actorId, string $action, ?string $note = null): void
    {
        DB::table('profile_correction_events')->insert([
            'request_id' => $requestId, 'actor_user_id' => $actorId,
            'action' => $action, 'note' => $note, 'created_at' => now(),
        ]);
    }
}
