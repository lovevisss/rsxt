<?php

namespace App\Http\Controllers;

use App\Services\LegacyAccess;
use App\Services\PositionAccess;
use App\Services\PositionSpreadsheet;
use App\Services\PositionWriter;
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

class PositionAdminController extends Controller
{
    public function __construct(private PositionAccess $access, private PositionWriter $writer,
        private PositionSpreadsheet $excel, private LegacyAccess $legacy) {}

    public function index(Request $request): Response
    {
        $this->access->requireAdmin($request->user());
        $filters = $request->validate(['type' => ['nullable', 'in:DF,BB'], 'department' => ['nullable', 'string', 'max:100'],
            'search' => ['nullable', 'string', 'max:100']]);
        $filters['type'] = ($filters['type'] ?? null) ?: 'DF';
        $query = $this->filtered($filters);
        $records = $query->orderByDesc('h06.h0609')->orderByDesc('h06.Id')->paginate(20)->withQueryString();
        $positions = $this->positions();
        $labels = collect($positions)->pluck('label', 'code')->all();
        $records->through(fn ($row) => $this->display($row, $labels));

        return Inertia::render('Personnel/Admin/Positions', ['modules' => $this->legacy->availableModules($request->user()),
            'records' => $records, 'filters' => $filters, 'positions' => $positions,
            'departments' => DB::connection($this->writer->readConnectionName())->table('department')
                ->where('isuse', 1)->orderBy('name')->get(['objname as code', 'name as label']),
            'writerAvailable' => $this->writerAvailable()]);
    }

    public function admins(Request $request): Response
    {
        $this->access->requireAdmin($request->user());
        $admins = DB::table('personnel_admins')->orderBy('employee_number')->get();
        $names = DB::connection('legacy')->table('user')->whereIn('username', $admins->pluck('employee_number'))
            ->pluck('truename', 'username');

        return Inertia::render('Personnel/Admin/Administrators', ['modules' => $this->legacy->availableModules($request->user()),
            'admins' => $admins->map(fn ($item) => ['employee_number' => $item->employee_number,
                'name' => $names[$item->employee_number] ?? '', 'created_at' => $item->created_at])]);
    }

    public function addAdmin(Request $request): RedirectResponse
    {
        $this->access->requireAdmin($request->user());
        $data = $request->validate(['employee_number' => ['required', 'string', 'max:64']]);
        abort_unless(DB::connection('legacy')->table('user')->where('username', $data['employee_number'])->where('isUse', 1)->exists(), 422, '工号不存在或账号未启用。');
        DB::table('personnel_admins')->insertOrIgnore(['employee_number' => $data['employee_number'], 'added_by' => $request->user()->Id,
            'created_at' => now(), 'updated_at' => now()]);
        $this->audit($request, 'admin_added', $data['employee_number']);

        return back()->with('success', '管理员已添加。');
    }

    public function removeAdmin(Request $request, string $number): RedirectResponse
    {
        $this->access->requireAdmin($request->user());
        DB::transaction(function () use ($number): void {
            $admins = DB::table('personnel_admins')->lockForUpdate()->get();
            abort_if($admins->count() <= 1, 422, '必须保留至少一名管理员。');
            abort_unless($admins->contains('employee_number', $number), 404);
            DB::table('personnel_admins')->where('employee_number', $number)->delete();
        });
        $this->audit($request, 'admin_removed', $number);

        return back()->with('success', '管理员已移除。');
    }

    public function store(Request $request): RedirectResponse
    {
        $this->access->requireAdmin($request->user());
        $result = $this->writer->createMany([$this->validatedRow($request->all())], $request->user());

        return back()->with('success', $result['created'] ? '岗位变动已新增。' : '同日期记录已存在，未重复新增。');
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $this->access->requireAdmin($request->user());
        $version = $request->validate(['version' => ['required', 'string', 'size:64']])['version'];
        $this->writer->change($id, $this->validatedRow($request->all()), $version, $request->user());

        return back()->with('success', '岗位变动已更新。');
    }

    public function destroy(Request $request, int $id): RedirectResponse
    {
        $this->access->requireAdmin($request->user());
        $version = $request->validate(['version' => ['required', 'string', 'size:64']])['version'];
        $this->writer->delete($id, $version, $request->user());

        return back()->with('success', '岗位变动已删除。');
    }

    public function template(Request $request): StreamedResponse
    {
        $this->access->requireAdmin($request->user());

        return $this->excel->download([], $this->positions(), true);
    }

    public function export(Request $request): StreamedResponse
    {
        $this->access->requireAdmin($request->user());
        $filters = $request->validate(['type' => ['nullable', 'in:DF,BB'], 'department' => ['nullable', 'string', 'max:100'],
            'search' => ['nullable', 'string', 'max:100']]);
        $rows = $this->filtered($filters)->orderByDesc('h06.h0609')->orderByDesc('h06.Id')->limit(10000)->get();
        $data = $rows->map(fn ($row) => ['type' => $row->h0615, 'employee_number' => $row->h0602,
            'old_position' => $row->h0603, 'old_date' => $row->h0608, 'new_position' => $row->h0604,
            'new_date' => $row->h0609, 'reason' => $row->h0606, 'note' => $row->h0605])->values()->all();

        return $this->excel->download($data, [], false);
    }

    public function preview(Request $request): Response
    {
        $this->access->requireAdmin($request->user());
        $request->validate(['file' => ['required', 'file', 'mimes:xlsx', 'max:10240']]);
        try {
            $raw = $this->excel->parse($request->file('file'));
        } catch (\Throwable $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }
        $preview = [];
        $seen = [];
        foreach ($raw as $item) {
            try {
                $row = $this->validatedRow($item);
                $key = $row['employee_number'].'|'.$row['type'].'|'.$row['new_date'];
                $duplicate = isset($seen[$key]) || DB::connection($this->writer->readConnectionName())->table('h06')
                    ->where('h0602', $row['employee_number'])->where('h0615', $row['type'])
                    ->where('h0609', $row['new_date'])->where('yndelete', '0')->exists();
                $seen[$key] = true;
                $preview[] = ['line' => $item['line'], 'row' => $row, 'status' => $duplicate ? 'duplicate' : 'ready', 'error' => null];
            } catch (ValidationException $e) {
                $preview[] = ['line' => $item['line'], 'row' => $item, 'status' => 'error',
                    'error' => implode('；', array_merge(...array_values($e->errors())))];
            }
        }
        $token = bin2hex(random_bytes(16));
        Storage::disk('local')->put("position-imports/{$token}.json", json_encode($preview, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $request->session()->put('position_import_token', $token);

        return Inertia::render('Personnel/Admin/ImportPreview', ['modules' => $this->legacy->availableModules($request->user()),
            'rows' => $preview, 'token' => $token]);
    }

    public function commit(Request $request): RedirectResponse
    {
        $this->access->requireAdmin($request->user());
        $token = $request->validate(['token' => ['required', 'string', 'size:32']])['token'];
        abort_unless(hash_equals((string) $request->session()->get('position_import_token'), $token), 403);
        $path = "position-imports/{$token}.json";
        abort_unless(Storage::disk('local')->exists($path), 404);
        $preview = json_decode(Storage::disk('local')->get($path), true, 512, JSON_THROW_ON_ERROR);
        abort_if(count(array_filter($preview, fn (array $item) => $item['status'] === 'error')) > 0, 422, '请修正错误行后重新上传。');
        $rows = [];
        foreach ($preview as $item) {
            if ($item['status'] === 'ready') {
                $rows[] = $this->validatedRow($item['row']);
            }
        }
        $result = $this->writer->createMany($rows, $request->user());
        Storage::disk('local')->delete($path);
        $request->session()->forget('position_import_token');

        return redirect()->route('personnel.admin.positions')->with('success', "已导入 {$result['created']} 条，跳过 ".(count($preview) - $result['created']).' 条重复记录。');
    }

    /** @param array<string, string> $filters */
    private function filtered(array $filters): Builder
    {
        $query = DB::connection($this->writer->readConnectionName())->table('h06')->where('h06.yndelete', '0');
        if (filled($filters['type'] ?? null)) {
            $query->where('h06.h0615', $filters['type']);
        }
        if (filled($filters['department'] ?? null)) {
            $query->where('h06.h0607', $filters['department']);
        }
        if (filled($filters['search'] ?? null)) {
            $term = str_replace(['%', '_'], ['\\%', '\\_'], $filters['search']);
            $query->where(fn ($q) => $q->where('h06.h0601', 'like', "%{$term}%")
                ->orWhere('h06.h0602', 'like', "%{$term}%"));
        }

        return $query;
    }

    /** @param array<string, string> $labels
     * @return array<string, int|string|null>
     */
    private function display(stdClass $row, array $labels): array
    {
        return ['id' => $row->Id, 'type' => $row->h0615, 'employee_number' => $row->h0602,
            'name' => $row->h0601, 'department' => $row->h0607, 'old_position' => $row->h0603,
            'old_position_label' => $labels[$row->h0603] ?? $row->h0603, 'old_date' => $row->h0608,
            'new_position' => $row->h0604, 'new_position_label' => $labels[$row->h0604] ?? $row->h0604,
            'new_date' => $row->h0609, 'reason' => $row->h0606, 'note' => $row->h0605,
            'version' => $this->writer->version($row)];
    }

    /** @return array<int, array{code:string,label:string}> */
    private function positions(): array
    {
        return DB::connection($this->writer->readConnectionName())->table('d306')->where('yndelete', '0')
            ->orderBy('objname')->get(['objname as code', 'objjc as label'])
            ->map(fn ($row) => ['code' => (string) $row->code, 'label' => (string) $row->label])->values()->all();
    }

    /** @param array<string, mixed> $input
     * @return array<string, string|null>
     */
    private function validatedRow(array $input): array
    {
        $data = validator($input, ['type' => ['required', 'in:DF,BB'], 'employee_number' => ['required', 'string', 'max:64'],
            'old_position' => ['required', 'string', 'max:255'], 'new_position' => ['required', 'string', 'max:255'],
            'old_date' => ['nullable', 'date_format:Y-m-d'], 'new_date' => ['required', 'date_format:Y-m-d'],
            'reason' => ['nullable', 'string', 'max:255'], 'note' => ['nullable', 'string', 'max:255']])->validate();
        $db = DB::connection($this->writer->readConnectionName());
        $profile = $db->table('h50')->where('h5002', $data['employee_number'])->where('yndelete', '0')
            ->first(['h5001', 'h5003']);
        if (! $profile) {
            throw ValidationException::withMessages(['employee_number' => '职工编号没有有效档案。']);
        }
        foreach (['old_position', 'new_position'] as $field) {
            if (! $db->table('d306')->where('objname', $data[$field])->where('yndelete', '0')->exists()) {
                throw ValidationException::withMessages([$field => '岗位代码无效。']);
            }
        }

        return [...$data, 'old_date' => $data['old_date'] ?? null, 'reason' => $data['reason'] ?? null,
            'note' => $data['note'] ?? null, 'name' => $profile->h5001, 'department' => $profile->h5003];
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

    private function audit(Request $request, string $action, string $number): void
    {
        DB::table('position_change_events')->insert(['employee_number' => $number, 'actor_user_id' => $request->user()->Id,
            'action' => $action, 'created_at' => now()]);
    }
}
