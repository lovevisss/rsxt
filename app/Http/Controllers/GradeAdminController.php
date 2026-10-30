<?php

namespace App\Http\Controllers;

use App\Services\GradeSpreadsheet;
use App\Services\GradeWriter;
use App\Services\LegacyAccess;
use App\Services\PositionAccess;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use stdClass;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GradeAdminController extends Controller
{
    public function __construct(private readonly PositionAccess $access, private readonly GradeWriter $writer,
        private readonly GradeSpreadsheet $excel, private readonly LegacyAccess $legacy) {}

    public function index(Request $request): Response
    {
        $this->access->requireAdmin($request->user());
        $filters = $this->filters($request);
        $records = $this->filtered($filters)->orderByDesc('h17.Id')->paginate(20)->withQueryString();
        $grades = $this->grades();
        $labels = collect($grades)->pluck('label', 'code')->all();
        $records->through(fn (stdClass $row) => $this->display($row, $labels));

        return Inertia::render('Personnel/Admin/Grades', [
            'modules' => $this->legacy->availableModules($request->user()), 'records' => $records,
            'filters' => $filters, 'grades' => $grades,
            'departments' => DB::connection($this->writer->readConnectionName())->table('department')
                ->where('isuse', 1)->orderBy('name')->get(['objname as code', 'name as label']),
            'writerAvailable' => $this->writerAvailable(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->access->requireAdmin($request->user());
        $result = $this->writer->createMany([$this->validatedRow($request->all())], $request->user());

        return back()->with('success', $result['created'] ? '岗位等级已新增。' : '相同记录已存在，未重复新增。');
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $this->access->requireAdmin($request->user());
        $version = $request->validate(['version' => ['required', 'string', 'size:64']])['version'];
        $this->writer->change($id, $this->validatedRow($request->all()), $version, $request->user());

        return back()->with('success', '岗位等级已更新。');
    }

    public function destroy(Request $request, int $id): RedirectResponse
    {
        $this->access->requireAdmin($request->user());
        $version = $request->validate(['version' => ['required', 'string', 'size:64']])['version'];
        $this->writer->delete($id, $version, $request->user());

        return back()->with('success', '岗位等级已删除。');
    }

    public function template(Request $request): StreamedResponse
    {
        $this->access->requireAdmin($request->user());

        return $this->excel->download([], $this->grades(), true);
    }

    public function export(Request $request): StreamedResponse
    {
        $this->access->requireAdmin($request->user());
        $rows = $this->filtered($this->filters($request))->orderByDesc('h17.Id')->limit(10000)->get();
        $data = $rows->map(fn (stdClass $row) => [
            'sequence' => $row->h1708, 'name' => $row->h1701, 'employee_number' => $row->h1702,
            'department' => $row->h1703, 'bb_grade' => $row->h1704, 'bb_start' => $row->h1705,
            'bb_end' => $row->h1710, 'df_grade' => $row->h1706, 'df_start' => $row->h1707,
            'df_end' => $row->h1711, 'note' => $row->h1709,
        ])->values()->all();

        return $this->excel->download($data, [], false);
    }

    public function preview(Request $request): Response
    {
        $this->access->requireAdmin($request->user());
        $request->validate(['file' => ['required', 'file', 'mimes:xlsx', 'max:10240']]);
        try {
            $raw = $this->excel->parse($request->file('file'));
        } catch (\Throwable $error) {
            throw ValidationException::withMessages(['file' => $error->getMessage()]);
        }
        $preview = [];
        $seen = [];
        foreach ($raw as $item) {
            try {
                $row = $this->validatedRow($item);
                $key = implode('|', array_map(fn ($field) => (string) ($row[$field] ?? ''),
                    ['employee_number', 'bb_grade', 'bb_start', 'bb_end', 'df_grade', 'df_start', 'df_end']));
                $duplicate = isset($seen[$key]) || $this->writer->duplicateExists($row);
                $seen[$key] = true;
                $preview[] = ['line' => $item['line'], 'row' => $row,
                    'status' => $duplicate ? 'duplicate' : 'ready', 'error' => null];
            } catch (ValidationException $error) {
                $preview[] = ['line' => $item['line'], 'row' => $item, 'status' => 'error',
                    'error' => implode('；', array_merge(...array_values($error->errors())))];
            }
        }
        $oldToken = $request->session()->get('grade_import_token');
        if (is_string($oldToken)) {
            Storage::disk('local')->delete("grade-imports/{$oldToken}.json");
        }
        $token = bin2hex(random_bytes(16));
        Storage::disk('local')->put("grade-imports/{$token}.json", json_encode($preview, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $request->session()->put('grade_import_token', $token);

        return Inertia::render('Personnel/Admin/GradeImportPreview', [
            'modules' => $this->legacy->availableModules($request->user()), 'rows' => $preview, 'token' => $token,
        ]);
    }

    public function commit(Request $request): RedirectResponse
    {
        $this->access->requireAdmin($request->user());
        $token = $request->validate(['token' => ['required', 'string', 'size:32']])['token'];
        abort_unless(hash_equals((string) $request->session()->get('grade_import_token'), $token), 403);
        $path = "grade-imports/{$token}.json";
        abort_unless(Storage::disk('local')->exists($path), 404);
        $preview = json_decode(Storage::disk('local')->get($path), true, 512, JSON_THROW_ON_ERROR);
        abort_if(count(array_filter($preview, fn (array $item) => $item['status'] === 'error')) > 0,
            422, '请修正错误行后重新上传。');
        $rows = [];
        foreach ($preview as $item) {
            if ($item['status'] === 'ready') {
                $rows[] = $this->validatedRow($item['row']);
            }
        }
        $result = $this->writer->createMany($rows, $request->user());
        Storage::disk('local')->delete($path);
        $request->session()->forget('grade_import_token');

        return redirect()->route('personnel.admin.grades')->with('success',
            "已导入 {$result['created']} 条，跳过 ".(count($preview) - $result['created']).' 条重复记录。');
    }

    /** @return array<string, string> */
    private function filters(Request $request): array
    {
        return $request->validate(['department' => ['nullable', 'string', 'max:100'],
            'search' => ['nullable', 'string', 'max:100']]);
    }

    /** @param array<string, string> $filters */
    private function filtered(array $filters): Builder
    {
        $query = DB::connection($this->writer->readConnectionName())->table('h17')->where('h17.yndelete', '0');
        if (filled($filters['department'] ?? null)) {
            $query->where('h17.h1703', $filters['department']);
        }
        if (filled($filters['search'] ?? null)) {
            $term = str_replace(['%', '_'], ['\\%', '\\_'], $filters['search']);
            $query->where(fn (Builder $inner) => $inner->where('h17.h1701', 'like', "%{$term}%")
                ->orWhere('h17.h1702', 'like', "%{$term}%"));
        }

        return $query;
    }

    /** @param array<string, string> $labels
     * @return array<string, int|string|null>
     */
    private function display(stdClass $row, array $labels): array
    {
        return ['id' => $row->Id, 'sequence' => $row->h1708, 'name' => $row->h1701,
            'employee_number' => $row->h1702, 'department' => $row->h1703,
            'bb_grade' => $row->h1704, 'bb_label' => $labels[$row->h1704] ?? $row->h1704,
            'bb_start' => $row->h1705, 'bb_end' => $row->h1710,
            'df_grade' => $row->h1706, 'df_label' => $labels[$row->h1706] ?? $row->h1706,
            'df_start' => $row->h1707, 'df_end' => $row->h1711,
            'note' => $row->h1709, 'version' => $this->writer->version($row)];
    }

    /** @return array<int, array{code:string,label:string}> */
    private function grades(): array
    {
        return DB::connection($this->writer->readConnectionName())->table('d307')->where('yndelete', '0')
            ->orderBy('objname')->get(['objname as code', 'objjc as label'])
            ->map(fn ($row) => ['code' => (string) $row->code, 'label' => (string) $row->label])->values()->all();
    }

    /** @param array<string, mixed> $input
     * @return array<string, string|null>
     */
    private function validatedRow(array $input): array
    {
        $input = array_map(fn ($value) => is_string($value) && trim($value) === '' ? null : $value, $input);
        $row = validator($input, [
            'employee_number' => ['required', 'string', 'max:64'],
            'bb_grade' => ['nullable', 'string', 'max:255'], 'bb_start' => ['nullable', 'date_format:Y-m-d'],
            'bb_end' => ['nullable', 'date_format:Y-m-d'],
            'df_grade' => ['nullable', 'string', 'max:255'], 'df_start' => ['nullable', 'date_format:Y-m-d'],
            'df_end' => ['nullable', 'date_format:Y-m-d'], 'note' => ['nullable', 'string', 'max:255'],
        ])->validate();
        foreach (['bb', 'df'] as $type) {
            $grade = $row["{$type}_grade"] ?? null;
            $start = $row["{$type}_start"] ?? null;
            $end = $row["{$type}_end"] ?? null;
            if (($grade === null) !== ($start === null) || ($end !== null && ($start === null || $end < $start))) {
                throw ValidationException::withMessages([$type => '等级与聘任时间须同时填写，结束时间不得早于聘任时间。']);
            }
        }
        if (empty($row['bb_grade']) && empty($row['df_grade'])) {
            throw ValidationException::withMessages(['grade' => '本部或东方至少填写一组等级与聘任时间。']);
        }
        $connection = DB::connection($this->writer->readConnectionName());
        $profile = $connection->table('h50')->where('h5002', $row['employee_number'])->where('yndelete', '0')
            ->first(['h5001', 'h5003']);
        if ($profile === null) {
            throw ValidationException::withMessages(['employee_number' => '职工编号没有有效档案。']);
        }
        foreach (['bb_grade', 'df_grade'] as $field) {
            if (! empty($row[$field]) && ! $connection->table('d307')->where('objname', $row[$field])
                ->where('yndelete', '0')->exists()) {
                throw ValidationException::withMessages([$field => '岗位等级代码无效。']);
            }
        }

        return [...$row, 'bb_grade' => $row['bb_grade'] ?? null, 'bb_start' => $row['bb_start'] ?? null,
            'bb_end' => $row['bb_end'] ?? null, 'df_grade' => $row['df_grade'] ?? null,
            'df_start' => $row['df_start'] ?? null, 'df_end' => $row['df_end'] ?? null,
            'note' => $row['note'] ?? null, 'name' => (string) $profile->h5001,
            'department' => (string) $profile->h5003];
    }

    private function writerAvailable(): bool
    {
        try {
            $this->writer->connectionName();

            return true;
        } catch (\RuntimeException) {
            return false;
        }
    }
}
