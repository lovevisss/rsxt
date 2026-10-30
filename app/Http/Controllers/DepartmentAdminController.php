<?php

namespace App\Http\Controllers;

use App\Services\DepartmentSpreadsheet;
use App\Services\DepartmentWriter;
use App\Services\LegacyAccess;
use App\Services\PositionAccess;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use stdClass;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DepartmentAdminController extends Controller
{
    public function __construct(private readonly PositionAccess $access, private readonly DepartmentWriter $writer,
        private readonly DepartmentSpreadsheet $excel, private readonly LegacyAccess $legacy) {}

    public function index(Request $request): Response
    {
        $this->access->requireAdmin($request->user());
        $filters = $this->filters($request);
        $records = $this->filtered($filters)->orderByDesc('h54.h5405')->orderByDesc('h54.Id')
            ->paginate(20)->withQueryString();
        $departments = $this->departments();
        $labels = collect($departments)->pluck('label', 'code')->all();
        $records->through(fn (stdClass $row) => $this->display($row, $labels));

        return Inertia::render('Personnel/Admin/Departments', [
            'modules' => $this->legacy->availableModules($request->user()),
            'records' => $records, 'filters' => $filters, 'departments' => $departments,
            'writerAvailable' => $this->writer->available(),
        ]);
    }

    public function lookup(Request $request): JsonResponse
    {
        $this->access->requireAdmin($request->user());
        $number = $request->validate(['employee_number' => ['required', 'string', 'max:64']])['employee_number'];
        $connection = DB::connection($this->writer->readConnectionName());
        $profile = $connection->table('h50')->where('h5002', $number)->where('yndelete', '0')
            ->first(['h5001', 'h5003']);
        abort_unless($profile && $connection->table('user')->where('username', $number)->where('isUse', 1)->exists(),
            404, '职工档案或启用账号不存在。');

        return response()->json(['name' => $profile->h5001, 'department' => $profile->h5003]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->access->requireAdmin($request->user());
        $result = $this->writer->createMany([$this->validatedRow($request->all())], $request->user());

        return back()->with('success', $result['created'] ? '部门变动已新增。' : '同日期和部门的记录已存在，未重复新增。');
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $this->access->requireAdmin($request->user());
        $version = $request->validate(['version' => ['required', 'string', 'size:64']])['version'];
        $this->writer->change($id, $this->validatedRow($request->all(), $id), $version, $request->user());

        return back()->with('success', '部门变动已更新。');
    }

    public function destroy(Request $request, int $id): RedirectResponse
    {
        $this->access->requireAdmin($request->user());
        $version = $request->validate(['version' => ['required', 'string', 'size:64']])['version'];
        $this->writer->delete($id, $version, $request->user());

        return back()->with('success', '部门变动已删除。');
    }

    public function template(Request $request): StreamedResponse
    {
        $this->access->requireAdmin($request->user());

        return $this->excel->download([], $this->departments(), true);
    }

    public function export(Request $request): StreamedResponse
    {
        $this->access->requireAdmin($request->user());
        $rows = $this->filtered($this->filters($request))->orderByDesc('h54.h5405')
            ->orderByDesc('h54.Id')->get();
        $data = $rows->map(fn (stdClass $row) => [
            'name' => $row->h5401, 'employee_number' => $row->h5402,
            'source_department' => $row->h5403, 'target_department' => $row->h5404,
            'changed_at' => $row->h5405, 'reason' => $row->h5407, 'note' => $row->h5406,
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
                $key = implode('|', [$row['employee_number'], $row['target_department'], $row['changed_at']]);
                $duplicate = isset($seen[$key]) || $this->writer->duplicateExists($row);
                $seen[$key] = true;
                $preview[] = ['line' => $item['line'], 'row' => $row,
                    'status' => $duplicate ? 'duplicate' : 'ready', 'error' => null];
            } catch (ValidationException $error) {
                $preview[] = ['line' => $item['line'], 'row' => $item,
                    'status' => 'error', 'error' => implode('；', array_merge(...array_values($error->errors())))];
            }
        }
        $oldToken = $request->session()->get('department_import_token');
        if (is_string($oldToken)) {
            Storage::disk('local')->delete("department-imports/{$oldToken}.json");
        }
        $token = bin2hex(random_bytes(16));
        Storage::disk('local')->put("department-imports/{$token}.json",
            json_encode($preview, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $request->session()->put('department_import_token', $token);

        return Inertia::render('Personnel/Admin/DepartmentImportPreview', [
            'modules' => $this->legacy->availableModules($request->user()), 'rows' => $preview, 'token' => $token,
        ]);
    }

    public function commit(Request $request): RedirectResponse
    {
        $this->access->requireAdmin($request->user());
        $token = $request->validate(['token' => ['required', 'string', 'size:32']])['token'];
        abort_unless(hash_equals((string) $request->session()->get('department_import_token'), $token), 403);
        $path = "department-imports/{$token}.json";
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
        $request->session()->forget('department_import_token');

        return redirect()->route('personnel.admin.departments')->with('success',
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
        $query = DB::connection($this->writer->readConnectionName())->table('h54')->where('h54.yndelete', '0');
        if (filled($filters['department'] ?? null)) {
            $query->where('h54.h5403', $filters['department']);
        }
        if (filled($filters['search'] ?? null)) {
            $term = str_replace(['%', '_'], ['\\%', '\\_'], $filters['search']);
            $query->where(fn (Builder $inner) => $inner->where('h54.h5401', 'like', "%{$term}%")
                ->orWhere('h54.h5402', 'like', "%{$term}%"));
        }

        return $query;
    }

    /** @param array<string, string> $labels
     * @return array<string, int|string|null>
     */
    private function display(stdClass $row, array $labels): array
    {
        return ['id' => $row->Id, 'name' => $row->h5401, 'employee_number' => $row->h5402,
            'source_department' => $row->h5403, 'source_label' => $labels[$row->h5403] ?? $row->h5403,
            'target_department' => $row->h5404, 'target_label' => $labels[$row->h5404] ?? $row->h5404,
            'changed_at' => $row->h5405, 'reason' => $row->h5407,
            'note' => $row->h5406, 'version' => $this->writer->version($row)];
    }

    /** @return array<int, array{code:string,label:string}> */
    private function departments(): array
    {
        return DB::connection($this->writer->readConnectionName())->table('department')
            ->where('yndelete', '0')->orderBy('name')->get(['objname as code', 'name as label'])
            ->map(fn ($row) => ['code' => (string) $row->code, 'label' => (string) $row->label])->values()->all();
    }

    /** @param array<string, mixed> $input
     * @return array<string, string|null>
     */
    private function validatedRow(array $input, ?int $existingId = null): array
    {
        $input = array_map(fn ($value) => is_string($value) && trim($value) === '' ? null : $value, $input);
        $row = validator($input, [
            'employee_number' => ['required', 'string', 'max:64'],
            'source_department' => ['nullable', 'string', 'max:255'],
            'target_department' => ['required', 'string', 'max:255'],
            'changed_at' => ['required', 'date_format:Y-m-d'],
            'reason' => ['nullable', 'string', 'max:255'], 'note' => ['nullable', 'string', 'max:255'],
        ])->validate();
        $connection = DB::connection($this->writer->readConnectionName());
        $existing = $existingId === null ? null : $connection->table('h54')->where('Id', $existingId)
            ->where('yndelete', '0')->first(['h5402', 'h5403', 'h5404']);
        $profile = $connection->table('h50')->where('h5002', $row['employee_number'])
            ->where('yndelete', '0')->first(['h5001', 'h5003']);
        if ($profile === null || ! $connection->table('user')->where('username', $row['employee_number'])
            ->where('isUse', 1)->exists()) {
            throw ValidationException::withMessages(['employee_number' => '职工档案或启用账号不存在。']);
        }
        $row['source_department'] ??= $profile->h5003;
        if (! $connection->table('department')->where('objname', $row['source_department'])->exists()
            && ($row['employee_number'] !== $existing?->h5402
                || $row['source_department'] !== $existing?->h5403)) {
            throw ValidationException::withMessages(['source_department' => '原部门代码无效。']);
        }
        if (! $connection->table('department')->where('objname', $row['target_department'])
            ->where('isuse', 1)->where('yndelete', '0')->exists()
            && ($row['employee_number'] !== $existing?->h5402
                || $row['target_department'] !== $existing?->h5404)) {
            throw ValidationException::withMessages(['target_department' => '目标部门代码无效或已停用。']);
        }

        return [...$row, 'reason' => $row['reason'] ?? null, 'note' => $row['note'] ?? null,
            'name' => (string) $profile->h5001];
    }
}
