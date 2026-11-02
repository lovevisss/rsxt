<?php

namespace App\Http\Controllers;

use App\Services\AdminOperationLog;
use App\Services\AdminOperationRollback;
use App\Services\LegacyAccess;
use App\Services\PositionAccess;
use App\Services\PositionWriter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AdminOperationsController extends Controller
{
    public function __construct(private readonly PositionAccess $access, private readonly LegacyAccess $legacy,
        private readonly AdminOperationLog $operations, private readonly AdminOperationRollback $rollback,
        private readonly PositionWriter $writer) {}

    public function index(Request $request): Response
    {
        $this->access->requireAdmin($request->user());
        $filters = $request->validate([
            'module' => ['nullable', 'in:position,grade,department,administrators'],
            'action' => ['nullable', 'string', 'max:32'], 'actor' => ['nullable', 'string', 'max:64'],
            'employee_number' => ['nullable', 'string', 'max:64'],
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $query = DB::table('admin_operations');
        foreach (['module', 'action'] as $field) {
            if (filled($filters[$field] ?? null)) {
                $query->where($field, $filters[$field]);
            }
        }
        if (filled($filters['employee_number'] ?? null)) {
            $term = addcslashes($filters['employee_number'], '%_');
            $query->where(fn ($inner) => $inner->where('employee_number', 'like', '%'.$term.'%')
                ->orWhere('items', 'like', '%"h0602":"%'.$term.'%"%')
                ->orWhere('items', 'like', '%"h1702":"%'.$term.'%"%')
                ->orWhere('items', 'like', '%"h5402":"%'.$term.'%"%'));
        }
        if (filled($filters['actor'] ?? null)) {
            $term = '%'.addcslashes($filters['actor'], '%_').'%';
            $ids = DB::connection('legacy')->table('user')->where('username', 'like', $term)
                ->orWhere('truename', 'like', $term)->pluck('Id');
            $query->whereIn('actor_user_id', $ids);
        }
        if (filled($filters['from'] ?? null)) {
            $query->whereDate('created_at', '>=', $filters['from']);
        }
        if (filled($filters['to'] ?? null)) {
            $query->whereDate('created_at', '<=', $filters['to']);
        }
        $records = $query->orderByDesc('id')->paginate(20)->withQueryString();
        $names = DB::connection('legacy')->table('user')->whereIn('Id', $records->pluck('actor_user_id')->filter())
            ->pluck('truename', 'Id');
        $records->through(fn ($row) => [
            'id' => $row->id, 'module' => $row->module, 'action' => $row->action,
            'actor' => $names[$row->actor_user_id] ?? '系统', 'employee_number' => $row->employee_number,
            'item_count' => $row->item_count, 'status' => $row->status,
            'created_at' => $row->created_at,
        ]);

        return Inertia::render('Personnel/Admin/Operations', [
            'modules' => $this->legacy->availableModules($request->user()),
            'records' => $records, 'filters' => $filters,
        ]);
    }

    public function show(Request $request, int $id): Response
    {
        $this->access->requireAdmin($request->user());
        $row = $this->operations->row($id);
        abort_if($row === null, 404);
        $items = json_decode($row->items, true, 512, JSON_THROW_ON_ERROR);
        $reason = match ($row->status) {
            'legacy' => '历史日志缺少完整旧值，不能安全回滚。',
            'rolled_back' => '此操作已经回滚。',
            'rollback' => '回滚记录仅供审计。',
            default => null,
        };
        if ($reason === null && $row->module !== 'administrators') {
            try {
                $connection = DB::connection($this->writer->readConnectionName());
                foreach ($items as $item) {
                    if ($this->operations->snapshot($connection, $row->module, (int) $item['record_id']) != $item['after']) {
                        $reason = '目标记录已有后续修改，请先处理较新的操作。';
                        break;
                    }
                }
            } catch (\Throwable) {
                $reason = '无法核对当前记录，请稍后重试。';
            }
        }
        if ($reason === null && $row->module === 'administrators') {
            foreach ($items as $item) {
                $current = DB::table('personnel_admins')->where('employee_number', $item['record_id'])->first();
                if (($current === null ? null : (array) $current) != $item['after']) {
                    $reason = '管理员名单已有后续变更。';
                    break;
                }
            }
            if ($reason === null && $row->action === 'admin_added'
                && DB::table('personnel_admins')->count() <= 1) {
                $reason = '不能回滚最后一名管理员。';
            }
        }

        return Inertia::render('Personnel/Admin/OperationDetail', [
            'modules' => $this->legacy->availableModules($request->user()),
            'operation' => ['id' => $row->id, 'module' => $row->module, 'action' => $row->action,
                'actor_user_id' => $row->actor_user_id,
                'actor_name' => $row->actor_user_id === null ? '系统' : DB::connection('legacy')->table('user')
                    ->where('Id', $row->actor_user_id)->value('truename'),
                'employee_number' => $row->employee_number,
                'item_count' => $row->item_count, 'status' => $row->status, 'created_at' => $row->created_at,
                'rolled_back_at' => $row->rolled_back_at, 'related_operation_id' => $row->related_operation_id,
                'items' => $items, 'rollback_reason' => $reason],
        ]);
    }

    public function rollback(Request $request, int $id): RedirectResponse
    {
        $this->access->requireAdmin($request->user());
        try {
            $this->rollback->rollback($id, $request->user());
        } catch (HttpException $error) {
            if ($error->getStatusCode() === 409 && $request->hasHeader('X-Inertia')) {
                return back()->withErrors(['rollback' => $error->getMessage()]);
            }
            throw $error;
        }

        return redirect()->route('personnel.admin.operations.show', $id)->with('success', '操作已回滚。');
    }
}
