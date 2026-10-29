<?php

namespace App\Http\Controllers;

use App\Services\AssessmentAccess;
use App\Services\AssessmentDocument;
use App\Services\AssessmentSummary;
use App\Services\AssessmentWriter;
use App\Services\LegacyAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use stdClass;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class AssessmentController extends Controller
{
    private const DISPLAY_FIELDS = [
        'h2029' => '考核年度', 'h2001' => '姓名', 'h2002' => '职工编号',
        'h2003' => '考核所在部门', 'h2004' => '性别', 'h2005' => '出生年月',
        'h2006' => '政治面貌', 'h2007' => '文化程度', 'h2008' => '行政职务',
        'h2009' => '专业技术职务', 'h2010' => '工作岗位',
        'h2011' => '籍贯', 'h2012' => '民族', 'h2015' => '参加工作时间',
        'h2016' => '最高学位', 'h2017' => '所聘岗位类型',
        'h2018' => '所聘岗位等级（专技）', 'h2028' => '工人技术资格',
        'h2034' => '当前部门', 'h2035' => '教学工作量', 'h2036' => '科研工作量',
        'h2013' => '部门考核结果', 'h2014' => '学校考核结果',
    ];

    private const DICTIONARIES = [
        'h2006' => 'd210', 'h2009' => 'd218', 'h2011' => 'd202',
        'h2012' => 'd204', 'h2017' => 'd306', 'h2018' => 'd307',
    ];

    public function __construct(
        private readonly LegacyAccess $legacyAccess,
        private readonly AssessmentAccess $access,
        private readonly AssessmentSummary $summary,
        private readonly AssessmentWriter $writer,
        private readonly AssessmentDocument $document,
    ) {}

    public function index(Request $request): InertiaResponse
    {
        abort_unless($this->legacyAccess->canOpenModule($request->user(), 'assessments'), 403);
        $rows = DB::connection($this->writer->readConnectionName())->table('h20')
            ->where('h2002', $request->user()->username)->where('yndelete', '0')
            ->orderByDesc('Id')->get(['Id', 'h2029', 'h2025', 'h2030', 'h2013', 'h2026', 'h2031', 'h2014']);

        return Inertia::render('Personnel/Assessments/Index', [
            'modules' => $this->legacyAccess->availableModules($request->user()),
            'records' => $rows->map(fn ($row) => [
                'id' => $row->Id, 'year' => $this->label($row->h2029),
                'status' => $this->status($row),
                'departmentResult' => $this->result($row->h2013),
                'schoolResult' => $this->result($row->h2014),
            ]),
        ]);
    }

    public function reviewIndex(Request $request): InertiaResponse
    {
        $departments = $this->access->reviewDepartmentCodes($request->user());
        abort_if($departments === [], 403);
        $rows = DB::connection($this->writer->readConnectionName())->table('h20')->whereIn('h2003', $departments)
            ->where('yndelete', '0')->where('h2025', 'y')->where('h2030', 'n')
            ->where('h2026', 'n')->where('h2031', 'n')
            ->where('h2002', '!=', $request->user()->username)
            ->orderByDesc('Id')->paginate(30, ['Id', 'h2001', 'h2002', 'h2003', 'h2029']);

        return Inertia::render('Personnel/Assessments/Review', [
            'modules' => $this->legacyAccess->availableModules($request->user()),
            'records' => $rows->through(fn ($row) => [
                'id' => $row->Id, 'name' => $this->label($row->h2001),
                'employeeNumber' => $row->h2002, 'department' => $this->department($row->h2003),
                'year' => $this->label($row->h2029),
            ]),
        ]);
    }

    public function show(Request $request, int $id): InertiaResponse
    {
        $row = $this->visibleRecord($request, $id);
        $owner = $this->access->canViewOwn($request->user(), $row);
        $reviewer = $this->access->canReview($request->user(), $row);
        $values = [];
        foreach (self::DISPLAY_FIELDS as $key => $name) {
            $raw = (string) ($row->{$key} ?? '');
            $values[] = ['key' => $key, 'label' => $name, 'value' => $this->displayValue($key, $raw)];
        }
        $editable = $owner && $row->h2025 !== 'y' && $row->h2030 !== 'y'
            && $row->h2026 !== 'y' && $row->h2031 !== 'y';
        $pending = $row->h2025 === 'y' && $row->h2030 !== 'y'
            && $row->h2026 !== 'y' && $row->h2031 !== 'y';

        return Inertia::render('Personnel/Assessments/Show', [
            'modules' => $this->legacyAccess->availableModules($request->user()),
            'record' => [
                'id' => $row->Id, 'year' => $this->label($row->h2029),
                'name' => $this->label($row->h2001), 'status' => $this->status($row),
                'version' => $this->writer->version($row),
                'summary' => $this->summary->clean($row->Memo), 'fields' => $values,
            ],
            'events' => DB::table('assessment_events')->where('legacy_assessment_id', $row->Id)
                ->where('employee_number', $row->h2002)->where('status', 'applied')
                ->orderBy('id')->get(['action', 'note', 'result_code', 'created_at']),
            'resultOptions' => DB::connection($this->writer->readConnectionName())->table('d519')->where('yndelete', '0')
                ->get(['objname', 'objjc'])->map(fn ($option) => ['code' => (string) $option->objname, 'label' => $option->objjc]),
            'canEdit' => $editable, 'canWithdraw' => $owner && $pending,
            'canReview' => $reviewer && $pending, 'reviewerView' => ! $owner && $reviewer,
            'writerAvailable' => $this->writer->available(),
            'readOnlyPreview' => $this->isReadOnlyPreview($request),
        ]);
    }

    public function save(Request $request, int $id): RedirectResponse
    {
        $row = $this->ownWritableRecord($request, $id);
        $data = $request->validate(['version' => ['required', 'string'], 'summary' => ['required', 'string', 'max:90000']]);
        $clean = $this->summary->clean($data['summary']);
        if (! $this->summary->hasContent($clean)) {
            throw ValidationException::withMessages(['summary' => '请填写个人总结。']);
        }
        $this->applyChange($row, $request, $data['version'], 'saved', ['Memo' => $clean]);

        return back()->with('success', '个人总结已保存。');
    }

    public function submit(Request $request, int $id): RedirectResponse
    {
        $row = $this->ownWritableRecord($request, $id);
        $version = $request->validate(['version' => ['required', 'string']])['version'];
        if (! $this->summary->hasContent($row->Memo)) {
            throw ValidationException::withMessages(['summary' => '请先保存个人总结。']);
        }
        $this->applyChange($row, $request, $version, 'submitted', ['h2025' => 'y']);

        return back()->with('success', '已提交部门审核。');
    }

    public function withdraw(Request $request, int $id): RedirectResponse
    {
        $row = $this->ownWritableRecord($request, $id);
        $version = $request->validate(['version' => ['required', 'string']])['version'];
        $this->applyChange($row, $request, $version, 'withdrawn', ['h2025' => 'n']);

        return back()->with('success', '已撤销提交。');
    }

    public function approve(Request $request, int $id): RedirectResponse
    {
        $row = $this->reviewableRecord($request, $id);
        $data = $request->validate([
            'version' => ['required', 'string'],
            'result' => ['required', 'string', 'max:20'],
        ]);
        if (! DB::connection($this->writer->readConnectionName())->table('d519')->where('objname', $data['result'])
            ->where('yndelete', '0')->exists()) {
            throw ValidationException::withMessages(['result' => '请选择有效的考核等次。']);
        }
        $this->applyChange($row, $request, $data['version'], 'approved', [
            'h2013' => $data['result'], 'h2030' => 'y',
        ], result: $data['result']);

        return back()->with('success', '部门考核已完成。');
    }

    public function returnForChanges(Request $request, int $id): RedirectResponse
    {
        $row = $this->reviewableRecord($request, $id);
        $data = $request->validate([
            'version' => ['required', 'string'], 'note' => ['required', 'string', 'max:2000'],
        ]);
        $this->applyChange($row, $request, $data['version'], 'returned', [
            'h2025' => 'n', 'h2030' => 'n', 'h2013' => '',
        ], note: $data['note']);

        return back()->with('success', '已退回员工修改。');
    }

    public function download(Request $request, int $id): BinaryFileResponse
    {
        $row = $this->visibleRecord($request, $id);
        $values = [];
        foreach (array_keys(self::DISPLAY_FIELDS) as $key) {
            $raw = (string) ($row->{$key} ?? '');
            $values[$key] = $this->displayValue($key, $raw);
        }
        $path = $this->document->generate($values, $this->summary->clean($row->Memo));
        $name = preg_replace('/[^\pL\pN_-]+/u', '_', $this->label($row->h2001)) ?: '职工';
        $year = preg_replace('/\D+/', '', $this->label($row->h2029)) ?: '年度';

        return response()->download($path, $name.$year.'年度考核.docx', [
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ])->deleteFileAfterSend(true);
    }

    private function visibleRecord(Request $request, int $id): stdClass
    {
        $row = DB::connection($this->writer->readConnectionName())->table('h20')->where('Id', $id)->where('yndelete', '0')->first();
        abort_if($row === null, 404);
        abort_unless($this->access->canViewOwn($request->user(), $row)
            || $this->access->canReview($request->user(), $row), 403);

        return $row;
    }

    private function ownWritableRecord(Request $request, int $id): stdClass
    {
        $this->requireWritableSession($request);
        $row = $this->visibleRecord($request, $id);
        abort_unless($this->access->canViewOwn($request->user(), $row), 403);

        return $row;
    }

    private function reviewableRecord(Request $request, int $id): stdClass
    {
        $this->requireWritableSession($request);
        $row = $this->visibleRecord($request, $id);
        abort_unless($this->access->canReview($request->user(), $row), 403);

        return $row;
    }

    private function requireWritableSession(Request $request): void
    {
        abort_if($this->isReadOnlyPreview($request), 403, '本机预览仅供只读核对。');
        abort_unless($this->writer->available(), 503, '年度考核写入尚未启用。');
    }

    private function isReadOnlyPreview(Request $request): bool
    {
        return $request->session()->get('personnel_preview') === true
            || (app()->environment('local') && config('personnel.local_preview')
                && ! config('cas.service_url'));
    }

    /** @param array<string, string> $updates */
    private function applyChange(stdClass $row, Request $request, string $version, string $action, array $updates, ?string $note = null, ?string $result = null): void
    {
        $eventId = DB::table('assessment_events')->insertGetId([
            'legacy_assessment_id' => $row->Id, 'employee_number' => $row->h2002,
            'actor_user_id' => $request->user()->Id, 'action' => $action,
            'status' => 'pending', 'note' => $note, 'result_code' => $result, 'created_at' => now(),
        ]);
        try {
            $this->writer->change($row->Id, $row->h2002, $version, $action, $updates);
        } catch (Throwable $error) {
            DB::table('assessment_events')->where('id', $eventId)->update(['status' => 'failed']);
            throw $error;
        }
        DB::table('assessment_events')->where('id', $eventId)->update(['status' => 'applied']);
    }

    private function label(?string $value): string
    {
        $value = trim((string) $value);

        return preg_match('/^\d+\)(.+)$/u', $value, $matches) ? trim($matches[1]) : $value;
    }

    private function displayValue(string $field, string $raw): string
    {
        if ($raw === '' || preg_match('/^\d+\).+$/u', $raw)) {
            return $this->label($raw);
        }
        if ($field === 'h2003' || $field === 'h2034') {
            return $this->department($raw);
        }
        if ($field === 'h2013' || $field === 'h2014') {
            return $this->result($raw);
        }
        if (isset(self::DICTIONARIES[$field])) {
            return (string) (DB::connection($this->writer->readConnectionName())
                ->table(self::DICTIONARIES[$field])->where('objname', $raw)->value('objjc') ?? $raw);
        }

        return $raw;
    }

    private function department(?string $code): string
    {
        return (string) (DB::connection($this->writer->readConnectionName())->table('department')->where('objname', $code)->value('name') ?? $this->label($code));
    }

    private function result(?string $code): string
    {
        return (string) (DB::connection($this->writer->readConnectionName())->table('d519')->where('objname', $code)->value('objjc') ?? $this->label($code));
    }

    private function status(stdClass $row): string
    {
        return match (true) {
            $row->h2031 === 'y' => '学校审核完成',
            $row->h2026 === 'y' => '已提交人力部',
            $row->h2030 === 'y' => '部门审核完成',
            $row->h2025 === 'y' => '待部门审核',
            default => '待填写',
        };
    }
}
