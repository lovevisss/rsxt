<?php

namespace App\Http\Controllers;

use App\Services\LeaveAccess;
use App\Services\LeaveWriter;
use App\Services\LegacyAccess;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use stdClass;

class LeaveController extends Controller
{
    public function __construct(
        private readonly LegacyAccess $legacy,
        private readonly LeaveAccess $access,
        private readonly LeaveWriter $writer,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($this->access->canApply($user) || $this->legacy->canOpen($user, 409), 403);
        $records = DB::connection($this->writer->readConnectionName())->table('a34')
            ->where('CreatorId', $user->Id)->whereIn('a3410', ['1', '2'])
            ->where(fn ($q) => $q->where('yndelete', '0')->orWhere('issubmit', 'y'))
            ->orderByDesc('Id')->paginate(25);

        return $this->listPage($request, '我的申请与审批进度', 'applications', $records->through(fn ($row) => [
            'id' => $row->Id, 'kind' => 'a34', 'name' => $this->label($row->a3401),
            'summary' => $row->a3413, 'date' => $row->a3404, 'status' => $this->status('a34', $row),
        ]));
    }

    public function details(Request $request): Response
    {
        abort_unless($this->legacy->canOpen($request->user(), 411), 403);
        $rows = DB::connection($this->writer->readConnectionName())->table('a35 as detail')
            ->leftJoin('a34 as application', 'application.Id', '=', 'detail.parentid')
            ->where('detail.a3511', (string) $request->user()->Id)->where('detail.yndelete', '0')
            ->orderByDesc('detail.Id')->paginate(25, [
                'detail.Id', 'detail.parentid', 'detail.a3501', 'detail.a3502', 'detail.a3503',
                'detail.a3504', 'detail.a3537', 'application.a3433', 'application.a3452', 'application.issubmit',
            ]);

        return $this->listPage($request, '我的请假明细', 'details', $rows->through(fn ($row) => [
            'id' => $row->parentid, 'kind' => 'a34', 'name' => $this->label($row->a3501),
            'summary' => $row->a3537 ?: $row->a3502.' 至 '.$row->a3503,
            'date' => $row->a3502, 'status' => $row->a3452 === 'y' ? '审批完成' : ($row->issubmit === 'y' ? ($row->a3433 ?: '待审批') : '草稿'),
        ]));
    }

    public function cancellations(Request $request): Response
    {
        abort_unless($this->access->canCancel($request->user()), 403);
        $records = DB::connection($this->writer->readConnectionName())->table('a38')
            ->where('CreatorId', $request->user()->Id)
            ->where(fn ($q) => $q->where('yndelete', '0')->orWhere('issubmit', 'y'))
            ->orderByDesc('Id')->paginate(25);

        return $this->listPage($request, '我的销假申请', 'cancellations', $records->through(fn ($row) => [
            'id' => $row->Id, 'kind' => 'a38', 'name' => $this->label($row->a3801),
            'summary' => $this->label($row->a3806), 'date' => $row->a3804,
            'status' => $this->status('a38', $row),
        ]));
    }

    public function review(Request $request): Response
    {
        $canAct = $this->legacy->canOpen($request->user(), 412);
        abort_unless($canAct || $this->legacy->canOpen($request->user(), 413), 403);
        $db = DB::connection($this->writer->readConnectionName());
        $assignments = $db->table('gz_auditors')->where('auditorId', $request->user()->Id)
            ->whereIn('tablename', ['a34', 'a38'])->orderByDesc('Id')
            ->get(['tablename', 'tableId'])->unique(fn ($item) => $item->tablename.':'.$item->tableId);
        $completed = DB::table('leave_events')->where('source', $this->writer->sourceName())
            ->where('actor_user_id', $request->user()->Id)->whereIn('action', ['approved', 'returned'])
            ->get(['kind', 'legacy_id'])->map(fn ($event) => (object) [
                'tablename' => $event->kind, 'tableId' => $event->legacy_id,
            ]);
        $completedKeys = $completed->mapWithKeys(fn ($item) => [$item->tablename.':'.$item->tableId => true]);
        $assignments = $assignments->concat($completed)
            ->unique(fn ($item) => $item->tablename.':'.$item->tableId);
        $applications = $db->table('a34')->whereIn('Id', $assignments->where('tablename', 'a34')->pluck('tableId'))
            ->get()->keyBy('Id');
        $cancellations = $db->table('a38')->whereIn('Id', $assignments->where('tablename', 'a38')->pluck('tableId'))
            ->get()->keyBy('Id');
        $rows = [];
        foreach ($assignments as $assignment) {
            $row = $assignment->tablename === 'a34'
                ? $applications->get($assignment->tableId) : $cancellations->get($assignment->tableId);
            if (! $row || ($row->issubmit !== 'y'
                && ! $completedKeys->get($assignment->tablename.':'.$assignment->tableId))) {
                continue;
            }
            $kind = $assignment->tablename;
            $current = $kind === 'a34' ? $row->a3433 : $row->a3833;
            $pending = $canAct && $row->issubmit === 'y' && (int) $row->CreatorId !== (int) $request->user()->Id
                && $current === $request->user()->truename;
            $rows[] = [
                'id' => $row->Id, 'kind' => $kind,
                'name' => $this->label($kind === 'a34' ? $row->a3401 : $row->a3801),
                'summary' => $kind === 'a34' ? $row->a3413 : $this->label($row->a3806),
                'date' => $kind === 'a34' ? $row->a3404 : $row->a3804,
                'status' => $pending ? '待我审批' : $this->status($kind, $row),
                'pending' => $pending,
            ];
        }
        usort($rows, fn ($a, $b) => ($b['pending'] <=> $a['pending']) ?: ($b['id'] <=> $a['id']));

        return $this->listPage($request, '请假审批', 'review', $rows);
    }

    public function create(Request $request): Response
    {
        abort_unless($this->access->canApply($request->user()), 403);

        return $this->formPage($request, 'a34');
    }

    public function createCancellation(Request $request): Response
    {
        abort_unless($this->access->canCancel($request->user()), 403);

        return $this->formPage($request, 'a38');
    }

    public function edit(Request $request, string $kind, int $id): Response
    {
        $row = $this->visible($request, $kind, $id);
        abort_unless((int) $row->CreatorId === (int) $request->user()->Id && $row->issubmit !== 'y'
            && ($kind === 'a34' ? $this->access->canApply($request->user()) : $this->access->canCancel($request->user())), 403);

        return $this->formPage($request, $kind, $row);
    }

    public function show(Request $request, string $kind, int $id): Response
    {
        $row = $this->visible($request, $kind, $id);
        $db = DB::connection($this->writer->readConnectionName());
        $owner = (int) $row->CreatorId === (int) $request->user()->Id;
        $assigned = ($this->legacy->canOpen($request->user(), 412) || $this->legacy->canOpen($request->user(), 413))
            && $db->table('gz_auditors')->where('tablename', $kind)->where('tableId', $id)
                ->where('auditorId', $request->user()->Id)->exists();
        $assigned = $assigned || ($this->legacy->canOpen($request->user(), 413)
            && DB::table('leave_events')->where('source', $this->writer->sourceName())
                ->where('kind', $kind)->where('legacy_id', $id)
                ->where('actor_user_id', $request->user()->Id)
                ->whereIn('action', ['approved', 'returned'])->exists());
        $detailQuery = $kind === 'a34' ? $db->table('a35')->where('parentid', $id)->where('yndelete', '0') : null;
        if ($detailQuery && ! $owner && ! $assigned) {
            $detailQuery->where('a3511', (string) $request->user()->Id);
        }
        $details = $detailQuery ? $detailQuery->get() : collect();
        $auditors = $db->table('gz_auditors')->where('tablename', $kind)->where('tableId', $id)
            ->orderBy('step')->get(['auditorName', 'step', 'workflowType']);
        $events = DB::table('leave_events')->where('kind', $kind)
            ->where('source', $this->writer->sourceName())->where('legacy_id', $id)
            ->orderBy('id')->get(['actor_user_id', 'action', 'note', 'created_at']);
        $actorNames = $db->table('user')->whereIn('Id', $events->pluck('actor_user_id'))
            ->pluck('truename', 'Id');
        $current = $kind === 'a34' ? $row->a3433 : $row->a3833;
        $result = $kind === 'a34' ? $row->a3406 : $row->a3811;

        return Inertia::render('Personnel/Leave/Show', [
            'modules' => $this->legacy->availableModules($request->user()),
            'kind' => $kind, 'record' => [
                'id' => $id, 'name' => $this->label($kind === 'a34' ? $row->a3401 : $row->a3801),
                'department' => $this->department($kind === 'a34' ? $row->a3402 : $row->a3802),
                'summary' => $this->label($kind === 'a34' ? $row->a3413 : $row->a3806),
                'date' => $kind === 'a34' ? $row->a3404 : $row->a3804,
                'status' => $this->status($kind, $row), 'currentReviewer' => $current,
                'result' => $result, 'rejection' => $kind === 'a34' ? $row->a3412 : $row->a3812,
                'submittedAt' => $kind === 'a34' ? $row->a3440 : null,
                'originalDetailId' => $kind === 'a38' ? $row->a3814 : null,
                'daysEarly' => $kind === 'a38' ? $row->a3805 : null,
                'version' => $this->writer->version($row),
            ],
            'details' => $details->map(fn ($detail) => [
                'id' => $detail->Id, 'person' => $this->label($detail->a3506),
                'type' => $this->label($detail->a3501), 'start' => $detail->a3502,
                'end' => $detail->a3503, 'timeType' => $this->label($detail->a3504),
                'note' => $detail->a3505, 'duration' => $detail->a3537,
                'hasAttachment' => (string) $detail->a3509 !== '',
            ]),
            'auditors' => $auditors, 'events' => $events->map(fn ($event) => [
                'actor' => $actorNames[$event->actor_user_id] ?? (string) $event->actor_user_id,
                'action' => $event->action, 'note' => $event->note,
                'created_at' => $event->created_at,
            ]),
            'canEdit' => $owner && $row->issubmit !== 'y'
                && ($kind === 'a34' ? $this->access->canApply($request->user()) : $this->access->canCancel($request->user())),
            'canSubmit' => $owner && $row->issubmit !== 'y'
                && ($kind === 'a34' ? $this->access->canApply($request->user()) : $this->access->canCancel($request->user())),
            'canWithdraw' => $owner && $row->issubmit === 'y' && trim((string) $result) === ''
                && trim((string) $current) !== '审批结束',
            'canReview' => $this->access->canReview($request->user(), $kind, $row),
            'writerAvailable' => $this->writer->available(),
            'readOnlyPreview' => $this->readOnlyPreview($request),
        ]);
    }

    public function attachment(Request $request, int $id): mixed
    {
        $db = DB::connection($this->writer->readConnectionName());
        $detail = $db->table('a35')->where('Id', $id)->first();
        abort_if($detail === null, 404);
        $row = $this->visible($request, 'a34', (int) $detail->parentid);
        $assigned = ($this->legacy->canOpen($request->user(), 412) || $this->legacy->canOpen($request->user(), 413))
            && $db->table('gz_auditors')->where('tablename', 'a34')->where('tableId', $row->Id)
                ->where('auditorId', $request->user()->Id)->exists();
        $assigned = $assigned || ($this->legacy->canOpen($request->user(), 413)
            && DB::table('leave_events')->where('source', $this->writer->sourceName())
                ->where('kind', 'a34')->where('legacy_id', $row->Id)
                ->where('actor_user_id', $request->user()->Id)
                ->whereIn('action', ['approved', 'returned'])->exists());
        abort_unless((int) $row->CreatorId === (int) $request->user()->Id
            || (string) $detail->a3511 === (string) $request->user()->Id || $assigned, 403);
        $local = DB::table('leave_attachments')->where('source', $this->writer->sourceName())
            ->where('legacy_detail_id', $id)->latest('id')->first();
        if ($local && Storage::disk('public')->exists($local->path)) {
            return response(Storage::disk('public')->get($local->path))
                ->header('Content-Type', $local->mime)->header('Cache-Control', 'private, no-store')
                ->header('X-Content-Type-Options', 'nosniff');
        }
        preg_match('~https?://[^\s]+~u', (string) $detail->a3509, $matches);
        $url = $matches[0] ?? '';
        abort_if($url === '' || parse_url($url, PHP_URL_HOST) !== config('leave.legacy_attachment_host'), 404);

        return redirect()->away($url);
    }

    public function save(Request $request, ?int $id = null): RedirectResponse
    {
        $this->requireWrite($request);
        abort_unless($this->access->canApply($request->user()), 403);
        $data = $request->validate([
            'version' => ['nullable', 'string'],
            'details' => ['required', 'array', 'min:1', 'max:20'],
            'details.*.id' => ['nullable', 'integer'],
            'details.*.person_id' => ['required', 'integer'],
            'details.*.leave_type' => ['required', 'integer'],
            'details.*.time_type' => ['required', 'integer'],
            'details.*.start' => ['required', 'date_format:Y-m-d'],
            'details.*.end' => ['required', 'date_format:Y-m-d'],
            'details.*.note' => ['nullable', 'string', 'max:255'],
            'details.*.attachment' => ['nullable', 'file', 'mimetypes:image/jpeg,image/png,application/pdf', 'max:10240'],
        ]);
        $db = DB::connection($this->writer->connectionName());
        $actor = $request->user();
        $department = $db->table('department')->where('Id', $actor->departmentId)->first(['objname']);
        abort_if($department === null, 422, '申请人部门无效。');
        $people = [];
        $types = $db->table('ud10')->get(['Id', 'name'])->keyBy('Id');
        $times = $db->table('ud09')->get(['Id', 'name'])->keyBy('Id');
        foreach ($data['details'] as $entry) {
            $person = $db->table('user as account')->join('h50 as person', 'person.h5002', '=', 'account.username')
                ->where('account.Id', $entry['person_id'])->where('account.departmentid', $actor->departmentId)
                ->where('account.isuse', 1)->where('person.yndelete', '0')
                ->first(['account.Id as userId', 'account.truename as name', 'person.Id as personId']);
            if (! $person || ! isset($types[$entry['leave_type']], $times[$entry['time_type']])) {
                throw ValidationException::withMessages(['details' => '请假人、假别或时间类型无效。']);
            }
            $start = CarbonImmutable::parse($entry['start']);
            $end = CarbonImmutable::parse($entry['end']);
            if ($end->lt($start) || ((int) $entry['time_type'] !== 3 && ! $end->isSameDay($start))) {
                throw ValidationException::withMessages(['details' => '结束日期不能早于开始日期，半天假只能选同一天。']);
            }
            if (! empty($entry['attachment']) && ! config('leave.public_attachment_url')) {
                throw ValidationException::withMessages(['details' => '附件共享地址尚未配置，暂不能上传。']);
            }
            $people[] = $person;
        }
        $recordId = $db->transaction(function () use ($db, $id, $data, $actor, $department, $people, $types, $times): int {
            if ($id) {
                $row = $db->table('a34')->where('Id', $id)->lockForUpdate()->first();
                abort_if(! $row, 404);
                abort_unless((int) $row->CreatorId === (int) $actor->Id, 403);
                abort_unless(in_array((string) $row->a3410, ['1', '2'], true), 403);
                abort_unless($row->issubmit !== 'y' && $this->writer->version($row, $this->writer->connectionName()) === ($data['version'] ?? ''), 409);
                $oldIds = $db->table('a35')->where('parentid', $id)->where('yndelete', '0')->pluck('Id')->all();
            } else {
                $oldIds = [];
                $id = $db->table('a34')->insertGetId([
                    'a3401' => $actor->Id.')'.$actor->truename, 'a3402' => $department->objname,
                    'a3404' => now()->toDateString(), 'CreatorId' => $actor->Id,
                    'issubmit' => 'n', 'a3452' => 'n', 'yndelete' => '0',
                ]);
            }
            $firstDate = min(array_column($data['details'], 'start'));
            $delegated = collect($people)->contains(fn ($person) => (int) $person->userId !== (int) $actor->Id);
            $db->table('a34')->where('Id', $id)->update([
                'a3410' => $delegated ? '2' : '1', 'a3413' => str_replace('-', '', substr($firstDate, 0, 7)),
                'a3412' => '', 'yndelete' => '0', 'updateTime' => now(),
            ]);
            $retained = [];
            foreach ($data['details'] as $index => $entry) {
                $person = $people[$index];
                $type = $types[$entry['leave_type']];
                $time = $times[$entry['time_type']];
                $days = (int) $entry['time_type'] === 3
                    ? CarbonImmutable::parse($entry['start'])->diffInDays(CarbonImmutable::parse($entry['end'])) + 1 : 0.5;
                $duration = $entry['start'].'——'.$entry['end'].'  '.$days.'天'.$type->name.';';
                $values = [
                    'parentid' => $id, 'a3501' => $type->Id.')'.$type->name,
                    'a3502' => $entry['start'], 'a3503' => $entry['end'],
                    'a3504' => $time->Id.')'.$time->name,
                    'a3505' => trim(strip_tags($entry['note'] ?? '')), 'a3506' => $person->personId.')'.$person->name,
                    'a3508' => $actor->Id.')'.$actor->truename, 'a3511' => (string) $person->userId,
                    'a3537' => $duration, 'a3552' => 'n', 'CreatorId' => $actor->Id, 'yndelete' => '0',
                ];
                if (! empty($entry['id'])) {
                    abort_unless(in_array((int) $entry['id'], $oldIds, true), 409);
                    $detailId = (int) $entry['id'];
                    $db->table('a35')->where('Id', $detailId)->update($values);
                } else {
                    $detailId = $db->table('a35')->insertGetId($values);
                }
                $retained[] = $detailId;
                if (! empty($entry['attachment'])) {
                    $file = $entry['attachment'];
                    $path = $file->storePublicly('leave', 'public');
                    abort_if($path === false, 503, '附件保存失败。');
                    $url = rtrim(config('leave.public_attachment_url'), '/').'/'.$path;
                    $name = basename($path);
                    abort_if(strlen($name.$url) > 255, 422, '附件地址过长。');
                    $db->table('a35')->where('Id', $detailId)->update(['a3509' => $name.$url]);
                    DB::table('leave_attachments')->insert([
                        'source' => $this->writer->sourceName(),
                        'legacy_detail_id' => $detailId, 'path' => $path,
                        'original_name' => $file->getClientOriginalName(),
                        'mime' => $file->getMimeType(), 'size' => $file->getSize(), 'created_at' => now(),
                    ]);
                }
            }
            if ($oldIds) {
                $db->table('a35')->where('parentid', $id)->whereIn('Id', array_diff($oldIds, $retained))
                    ->update(['yndelete' => '1']);
            }

            return $id;
        });
        $this->writer->event('a34', $recordId, $actor, $id ? 'saved' : 'created');

        return redirect()->route('personnel.leave.show', ['kind' => 'a34', 'id' => $recordId])
            ->with('success', '请假草稿已保存。');
    }

    public function saveCancellation(Request $request, ?int $id = null): RedirectResponse
    {
        $this->requireWrite($request);
        abort_unless($this->access->canCancel($request->user()), 403);
        $data = $request->validate([
            'version' => ['nullable', 'string'], 'detail_id' => ['required', 'integer'],
            'date' => ['required', 'date_format:Y-m-d'],
        ]);
        $db = DB::connection($this->writer->connectionName());
        $actor = $request->user();
        $detail = $db->table('a35 as detail')->join('a34 as application', 'application.Id', '=', 'detail.parentid')
            ->join('user as subject', 'subject.Id', '=', 'detail.a3511')
            ->where('detail.Id', $data['detail_id'])->where('detail.yndelete', '0')
            ->where('detail.a3552', 'y')->where('application.a3452', 'y')
            ->where('subject.departmentid', $actor->departmentId)
            ->first(['detail.*', 'subject.truename as subjectName']);
        abort_if($detail === null, 422, '请先选择同部门已审核的请假明细。');
        $date = CarbonImmutable::parse($data['date']);
        if ($date->lt(CarbonImmutable::parse($detail->a3502)) || $date->gt(CarbonImmutable::parse($detail->a3503))) {
            throw ValidationException::withMessages(['date' => '销假日期须在原请假期间内。']);
        }
        $days = (int) $date->diffInDays(CarbonImmutable::parse($detail->a3503)) + 1;
        $department = $db->table('department')->where('Id', $actor->departmentId)->value('objname');
        abort_if(! $department, 422, '申请人部门无效。');
        $recordId = $db->transaction(function () use ($db, $id, $data, $actor, $detail, $department, $days): int {
            if ($id) {
                $row = $db->table('a38')->where('Id', $id)->lockForUpdate()->first();
                abort_if(! $row, 404);
                abort_unless((int) $row->CreatorId === (int) $actor->Id, 403);
                abort_unless($row->issubmit !== 'y' && $this->writer->version($row, $this->writer->connectionName()) === ($data['version'] ?? ''), 409);
            } else {
                $duplicate = $db->table('a38')->where('a3814', (string) $detail->Id)
                    ->where('issubmit', 'y')->where('a3813', '!=', 'y')->exists();
                abort_if($duplicate, 409, '该请假记录已有销假申请。');
                $id = $db->table('a38')->insertGetId([
                    'a3801' => $actor->Id.')'.$actor->truename, 'a3802' => $department,
                    'CreatorId' => $actor->Id, 'issubmit' => 'n', 'yndelete' => '0',
                ]);
            }
            $db->table('a38')->where('Id', $id)->update([
                'a3804' => $data['date'], 'a3805' => (string) $days,
                'a3806' => $detail->Id.')'.$detail->a3537,
                'a3807' => $detail->a3502, 'a3808' => $detail->a3503,
                'a3809' => $detail->a3508, 'a3810' => $detail->a3501,
                'a3814' => (string) $detail->Id, 'a3812' => '', 'a3813' => 'n',
                'yndelete' => '0', 'updateTime' => now(),
            ]);

            return $id;
        });
        $this->writer->event('a38', $recordId, $actor, $id ? 'saved' : 'created');

        return redirect()->route('personnel.leave.show', ['kind' => 'a38', 'id' => $recordId])
            ->with('success', '销假草稿已保存。');
    }

    public function submit(Request $request, string $kind, int $id): RedirectResponse
    {
        $this->requireWrite($request);
        abort_unless($kind === 'a34' ? $this->access->canApply($request->user())
            : $this->access->canCancel($request->user()), 403);
        $this->own($request, $kind, $id);
        $version = $request->validate(['version' => ['required', 'string']])['version'];
        $db = DB::connection($this->writer->connectionName());
        $db->transaction(function () use ($db, $kind, $id, $version, $request): void {
            $row = $db->table($kind)->where('Id', $id)->lockForUpdate()->first();
            abort_unless($row->issubmit !== 'y' && $this->writer->version($row, $this->writer->connectionName()) === $version, 409);
            if ($kind === 'a34') {
                abort_unless($db->table('a35')->where('parentid', $id)->where('yndelete', '0')->exists(), 422);
            } else {
                $original = $db->table('a35 as detail')
                    ->join('a34 as application', 'application.Id', '=', 'detail.parentid')
                    ->where('detail.Id', $row->a3814)->where('detail.yndelete', '0')
                    ->where('detail.a3552', 'y')->where('application.a3452', 'y')
                    ->first(['detail.a3502', 'detail.a3503']);
                abort_unless($original && $row->a3804 >= $original->a3502
                    && $row->a3804 <= $original->a3503, 409, '原请假记录已变化。');
                abort_if($db->table('a38')->where('a3814', $row->a3814)
                    ->where('Id', '!=', $id)->where('issubmit', 'y')->where('a3813', '!=', 'y')->exists(),
                    409, '该请假记录已有销假申请。');
            }
            $department = $kind === 'a34' ? $row->a3402 : $row->a3802;
            $route = $this->writer->route($request->user(), (string) $department);
            $db->table('gz_auditors')->where('tablename', $kind)->where('tableId', $id)->delete();
            $this->writer->createAuditors($db, $kind, $id, $route['steps'], $route['type'], (int) $request->user()->Id);
            $names = implode(',', array_column($route['steps'], 'name'));
            $db->table($kind)->where('Id', $id)->update($kind === 'a34' ? [
                'issubmit' => 'y', 'a3430' => $names, 'a3433' => $route['steps'][0]['name'],
                'a3440' => now()->format('Y-m-d H:i:s'), 'yndelete' => '1', 'updateTime' => now(),
            ] : [
                'issubmit' => 'y', 'a3830' => $names, 'a3833' => $route['steps'][0]['name'],
                'yndelete' => '1', 'updateTime' => now(),
            ]);
        });
        $this->writer->event($kind, $id, $request->user(), 'submitted');

        return back()->with('success', '已提交审批。');
    }

    public function withdraw(Request $request, string $kind, int $id): RedirectResponse
    {
        $this->requireWrite($request);
        abort_unless($kind === 'a34' ? $this->access->canApply($request->user())
            : $this->access->canCancel($request->user()), 403);
        $this->own($request, $kind, $id);
        $version = $request->validate(['version' => ['required', 'string']])['version'];
        $db = DB::connection($this->writer->connectionName());
        $db->transaction(function () use ($db, $kind, $id, $version): void {
            $row = $db->table($kind)->where('Id', $id)->lockForUpdate()->first();
            $result = $kind === 'a34' ? $row->a3406 : $row->a3811;
            $current = $kind === 'a34' ? $row->a3433 : $row->a3833;
            abort_unless($this->writer->version($row, $this->writer->connectionName()) === $version && $row->issubmit === 'y'
                && trim((string) $result) === '' && $current !== '审批结束', 409);
            $db->table($kind)->where('Id', $id)->update($kind === 'a34' ? [
                'issubmit' => 'n', 'a3433' => '', 'yndelete' => '0', 'updateTime' => now(),
            ] : ['issubmit' => 'n', 'a3833' => '', 'yndelete' => '0', 'updateTime' => now()]);
            $db->table('gz_auditors')->where('tablename', $kind)->where('tableId', $id)->delete();
        });
        $this->writer->event($kind, $id, $request->user(), 'withdrawn');

        return back()->with('success', '已撤销提交。');
    }

    public function approve(Request $request, string $kind, int $id): RedirectResponse
    {
        return $this->decide($request, $kind, $id, 'approve');
    }

    public function returnForChanges(Request $request, string $kind, int $id): RedirectResponse
    {
        return $this->decide($request, $kind, $id, 'return');
    }

    private function decide(Request $request, string $kind, int $id, string $decision): RedirectResponse
    {
        $this->requireWrite($request);
        abort_unless(in_array($decision, ['approve', 'return'], true), 404);
        $row = $this->visible($request, $kind, $id);
        abort_unless($this->access->canReview($request->user(), $kind, $row), 403);
        $data = $request->validate([
            'version' => ['required', 'string'],
            'note' => [$decision === 'return' ? 'required' : 'nullable', 'string', 'max:200'],
        ]);
        $note = trim(strip_tags($data['note'] ?? ''));
        if ($decision === 'return' && $note === '') {
            throw ValidationException::withMessages(['note' => '请填写退回原因。']);
        }
        $db = DB::connection($this->writer->connectionName());
        $db->transaction(function () use ($db, $kind, $id, $decision, $data, $note, $request): void {
            $row = $db->table($kind)->where('Id', $id)->lockForUpdate()->first();
            abort_unless($this->writer->version($row, $this->writer->connectionName()) === $data['version']
                && $this->access->canReview($request->user(), $kind, $row), 409);
            $current = $db->table('gz_auditors')->where('tablename', $kind)->where('tableId', $id)
                ->where('auditorId', $request->user()->Id)->orderBy('step')->first();
            abort_if(! $current, 409);
            $message = $request->user()->truename.'的审批结果是：'.($decision === 'approve' ? '情况属实，审核通过' : '退回修改')
                .($note !== '' ? '（'.$note.'）' : '').'('.now()->format('Y-m-d H:i:s').')';
            if ($decision === 'return') {
                $db->table($kind)->where('Id', $id)->update($kind === 'a34' ? [
                    'issubmit' => 'n', 'a3412' => $note, 'a3433' => '',
                    'yndelete' => '0', 'updateTime' => now(),
                ] : [
                    'issubmit' => 'n', 'a3812' => $note, 'a3813' => 'y',
                    'a3833' => '', 'yndelete' => '0', 'updateTime' => now(),
                ]);
                $db->table('gz_auditors')->where('tablename', $kind)->where('tableId', $id)->delete();

                return;
            }
            $next = $db->table('gz_auditors')->where('tablename', $kind)->where('tableId', $id)
                ->where('step', '>', $current->step)->orderBy('step')->first();
            $resultField = $kind === 'a34' ? 'a3406' : 'a3811';
            $update = [
                $resultField => trim((string) $row->{$resultField}."\n".$message),
                $kind === 'a34' ? 'a3433' : 'a3833' => $next ? $next->auditorName : '审批结束',
                'updateTime' => now(),
            ];
            abort_if(mb_strlen($update[$resultField]) > ($kind === 'a34' ? 1000 : 255), 422, '审批结果已达到旧库字段长度上限。');
            if (! $next && $kind === 'a34') {
                $update['a3452'] = 'y';
                $db->table('a35')->where('parentid', $id)->where('yndelete', '0')->update(['a3552' => 'y']);
            }
            $db->table($kind)->where('Id', $id)->update($update);
        });
        $this->writer->event($kind, $id, $request->user(), $decision === 'approve' ? 'approved' : 'returned', $note ?: null);

        return back()->with('success', $decision === 'approve' ? '审批已完成。' : '已退回申请人。');
    }

    private function own(Request $request, string $kind, int $id): stdClass
    {
        $row = $this->visible($request, $kind, $id);
        abort_unless((int) $row->CreatorId === (int) $request->user()->Id, 403);
        abort_if($kind === 'a34' && ! in_array((string) $row->a3410, ['1', '2'], true), 403);

        return $row;
    }

    private function listPage(Request $request, string $title, string $mode, mixed $records): Response
    {
        return Inertia::render('Personnel/Leave/Index', [
            'modules' => $this->legacy->availableModules($request->user()),
            'title' => $title, 'mode' => $mode, 'records' => $records,
        ]);
    }

    private function formPage(Request $request, string $kind, ?stdClass $row = null): Response
    {
        abort_unless(in_array($kind, ['a34', 'a38'], true), 404);
        $db = DB::connection($this->writer->readConnectionName());
        $departmentId = $request->user()->departmentId;
        $people = $db->table('user as account')->join('h50 as person', 'person.h5002', '=', 'account.username')
            ->where('account.departmentid', $departmentId)->where('account.isuse', 1)
            ->where('person.yndelete', '0')->orderBy('account.truename')
            ->get(['account.Id as userId', 'account.truename as name', 'person.Id as personId']);
        $types = $db->table('ud10')->orderBy('xuhao')->get(['Id as id', 'name']);
        $times = $db->table('ud09')->orderBy('xuhao')->get(['Id as id', 'name']);
        $eligible = $kind === 'a38' ? $db->table('a35 as detail')
            ->join('a34 as application', 'application.Id', '=', 'detail.parentid')
            ->whereIn('detail.a3511', $people->pluck('userId')->map(fn ($id) => (string) $id)->all())
            ->where('detail.a3552', 'y')->where('detail.yndelete', '0')
            ->where('application.a3452', 'y')->orderByDesc('detail.Id')
            ->get(['detail.Id as id', 'detail.a3506', 'detail.a3537', 'detail.a3502', 'detail.a3503'])
            ->map(fn ($item) => ['id' => $item->id, 'label' => $this->label($item->a3506).' · '.$item->a3537,
                'start' => $item->a3502, 'end' => $item->a3503]) : [];
        $details = $row && $kind === 'a34' ? $db->table('a35')->where('parentid', $row->Id)
            ->where('yndelete', '0')->get()->map(fn ($detail) => [
                'id' => $detail->Id, 'person_id' => (int) $detail->a3511,
                'leave_type' => (int) $detail->a3501, 'time_type' => (int) $detail->a3504,
                'start' => $detail->a3502, 'end' => $detail->a3503, 'note' => $detail->a3505,
                'hasAttachment' => (string) $detail->a3509 !== '',
            ]) : [];

        return Inertia::render('Personnel/Leave/Form', [
            'modules' => $this->legacy->availableModules($request->user()), 'kind' => $kind,
            'record' => $row ? ['id' => $row->Id, 'version' => $this->writer->version($row),
                'detail_id' => $kind === 'a38' ? $row->a3814 : null,
                'date' => $kind === 'a38' ? $row->a3804 : null] : null,
            'details' => $details, 'people' => $people, 'types' => $types, 'times' => $times,
            'eligible' => $eligible, 'writerAvailable' => $this->writer->available(),
            'readOnlyPreview' => $this->readOnlyPreview($request),
        ]);
    }

    private function visible(Request $request, string $kind, int $id): stdClass
    {
        abort_unless(in_array($kind, ['a34', 'a38'], true), 404);
        $row = DB::connection($this->writer->readConnectionName())->table($kind)->where('Id', $id)->first();
        abort_if($row === null, 404);
        abort_if($row->yndelete === '1' && $row->issubmit !== 'y', 404);
        abort_unless($this->access->canSee($request->user(), $kind, $row), 403);

        return $row;
    }

    private function label(?string $raw): string
    {
        return preg_replace('/^\d+\)/u', '', (string) $raw) ?? '';
    }

    private function department(?string $code): string
    {
        return (string) (DB::connection($this->writer->readConnectionName())->table('department')
            ->where('objname', $code)->value('name') ?? $code);
    }

    private function status(string $kind, stdClass $row): string
    {
        if ($row->issubmit !== 'y') {
            return trim((string) ($kind === 'a34' ? $row->a3412 : $row->a3812)) !== '' ? '已退回' : '草稿';
        }
        if (($kind === 'a34' ? $row->a3433 : $row->a3833) === '审批结束') {
            return '审批完成';
        }

        return '审批中';
    }

    private function readOnlyPreview(Request $request): bool
    {
        return $request->session()->get('personnel_preview') === true
            || (app()->environment('local') && config('personnel.local_preview')
                && ! config('personnel.cas.validate_url'));
    }

    private function requireWrite(Request $request): void
    {
        abort_if($this->readOnlyPreview($request), 403, '本机预览仅供只读核对。');
        abort_unless($this->writer->available(), 503, '请假写入尚未启用。');
    }
}
