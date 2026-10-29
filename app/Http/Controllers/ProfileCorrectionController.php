<?php

namespace App\Http\Controllers;

use App\Services\LegacyAccess;
use App\Services\LegacyProfileValues;
use App\Services\ProfileCorrectionAccess;
use App\Services\ProfileCorrectionCatalog;
use App\Services\ProfileCorrectionService;
use App\Services\ProfileCorrectionWriter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use stdClass;

class ProfileCorrectionController extends Controller
{
    public function __construct(
        private readonly LegacyAccess $legacyAccess,
        private readonly ProfileCorrectionAccess $access,
        private readonly ProfileCorrectionCatalog $catalog,
        private readonly ProfileCorrectionService $service,
        private readonly ProfileCorrectionWriter $writer,
        private readonly LegacyProfileValues $profileValues,
    ) {}

    public function index(Request $request): InertiaResponse
    {
        abort_unless($this->access->canApply($request->user()), 403);

        return Inertia::render('Personnel/Corrections/Index', [
            'modules' => $this->legacyAccess->availableModules($request->user()),
            'reviewMode' => false,
            'requests' => DB::table('profile_correction_requests')
                ->where('applicant_user_id', $request->user()->Id)
                ->where('employee_number', $request->user()->username)
                ->orderByDesc('id')->paginate(20, ['id', 'status', 'reason', 'created_at', 'reviewed_at', 'applied_at']),
        ]);
    }

    public function create(Request $request): InertiaResponse
    {
        abort_unless($this->access->canApply($request->user()), 403);
        $fields = $this->catalog->fields();
        $profile = DB::connection('legacy')->table('h50')->where('h5002', $request->user()->username)
            ->where('yndelete', '0')->first(['Id', ...array_keys($fields)]);
        abort_if($profile === null, 404);
        $values = array_intersect_key((array) $profile, $fields);
        $display = $this->profileValues->resolve($profile, array_keys($fields));
        $sections = [];
        foreach (config('profile_corrections.fields') as $key => $keys) {
            $sections[] = ['key' => $key, 'title' => config("personnel_profile.sections.$key.title"), 'fields' => $keys];
        }

        return Inertia::render('Personnel/Corrections/Form', [
            'modules' => $this->legacyAccess->availableModules($request->user()),
            'fields' => $fields, 'sections' => $sections, 'values' => $values,
            'displayValues' => $display, 'options' => $this->catalog->options(),
            'hasActiveRequest' => DB::table('profile_correction_requests')
                ->where('active_employee_number', $request->user()->username)->exists(),
            'readOnlyPreview' => $this->isReadOnlyPreview($request),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->requireWritableSession($request);
        abort_unless($this->access->canApply($request->user()), 403);
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
            'changes' => ['required', 'array', 'min:1'],
            'evidence' => ['sometimes', 'array', 'max:5'],
            'evidence.*' => ['file', 'mimetypes:application/pdf,image/jpeg,image/png', 'max:10240'],
        ]);
        $id = $this->service->submit($request->user(), $validated['changes'], $validated['reason'], $request->file('evidence', []));

        return redirect()->route('personnel.corrections.show', $id)->with('success', '更正申请已提交。');
    }

    public function show(Request $request, int $id): InertiaResponse
    {
        $correction = $this->findVisible($request, $id);

        return Inertia::render('Personnel/Corrections/Show', [
            'modules' => $this->legacyAccess->availableModules($request->user()),
            'correction' => $correction,
            'changes' => $this->displayChanges($id),
            'files' => DB::table('profile_correction_files')->where('request_id', $id)
                ->get(['id', 'original_name', 'mime', 'size']),
            'events' => DB::table('profile_correction_events')->where('request_id', $id)
                ->orderBy('id')->get(['action', 'note', 'created_at']),
            'canReview' => $this->access->isReviewer($request->user()) && $correction->applicant_user_id !== $request->user()->Id,
            'writerAvailable' => $this->writer->available(),
            'readOnlyPreview' => $this->isReadOnlyPreview($request),
        ]);
    }

    public function withdraw(Request $request, int $id): RedirectResponse
    {
        $this->requireWritableSession($request);
        abort_unless($this->access->canApply($request->user()), 403);
        $correction = $this->findVisible($request, $id);
        abort_unless($correction->applicant_user_id === $request->user()->Id, 403);
        $this->service->withdraw($correction, $request->user());

        return back()->with('success', '申请已撤回。');
    }

    public function reviewIndex(Request $request): InertiaResponse
    {
        abort_unless($this->access->isReviewer($request->user()), 403);

        return Inertia::render('Personnel/Corrections/Index', [
            'modules' => $this->legacyAccess->availableModules($request->user()),
            'reviewMode' => true,
            'requests' => DB::table('profile_correction_requests')->orderByRaw("CASE WHEN status = 'pending' THEN 0 WHEN status = 'apply_failed' THEN 1 ELSE 2 END")
                ->orderByDesc('id')->paginate(20, ['id', 'employee_number', 'status', 'reason', 'created_at', 'reviewed_at', 'applied_at']),
        ]);
    }

    public function approve(Request $request, int $id): RedirectResponse
    {
        $this->requireWritableSession($request);
        $correction = $this->findReviewable($request, $id);
        $this->service->approve($correction, $request->user());

        return back()->with('success', '审核已完成，请查看写入状态。');
    }

    public function reject(Request $request, int $id): RedirectResponse
    {
        $this->requireWritableSession($request);
        $correction = $this->findReviewable($request, $id);
        $data = $request->validate(['note' => ['required', 'string', 'max:2000']]);
        $this->service->reject($correction, $request->user(), $data['note']);

        return back()->with('success', '申请已驳回。');
    }

    public function retry(Request $request, int $id): RedirectResponse
    {
        $this->requireWritableSession($request);
        $correction = $this->findReviewable($request, $id);
        $this->service->retry($correction, $request->user());

        return back()->with('success', '已重新尝试写入。');
    }

    public function file(Request $request, int $id, int $fileId): Response
    {
        $this->findVisible($request, $id);
        $file = DB::table('profile_correction_files')->where('request_id', $id)->where('id', $fileId)->first();
        abort_if($file === null || ! Storage::disk('local')->exists($file->path), 404);

        return response(Storage::disk('local')->get($file->path))
            ->header('Content-Type', $file->mime)
            ->header('Content-Disposition', 'attachment; filename="evidence-'.(int) $file->id.'"')
            ->header('Cache-Control', 'private, no-store')
            ->header('X-Content-Type-Options', 'nosniff');
    }

    private function findVisible(Request $request, int $id): stdClass
    {
        $correction = DB::table('profile_correction_requests')->where('id', $id)->first();
        abort_if($correction === null, 404);
        $owner = $correction->applicant_user_id === $request->user()->Id
            && $correction->employee_number === $request->user()->username
            && $this->access->canApply($request->user());
        abort_unless($owner || $this->access->isReviewer($request->user()), 403);

        return $correction;
    }

    private function requireWritableSession(Request $request): void
    {
        abort_if($this->isReadOnlyPreview($request), 403, '本机预览仅供只读核对。');
    }

    private function isReadOnlyPreview(Request $request): bool
    {
        return $request->session()->get('personnel_preview') === true
            || (app()->environment('local') && config('personnel.local_preview')
                && ! config('personnel.cas.validate_url'));
    }

    private function findReviewable(Request $request, int $id): stdClass
    {
        abort_unless($this->access->isReviewer($request->user()), 403);
        $correction = DB::table('profile_correction_requests')->where('id', $id)->first();
        abort_if($correction === null, 404);
        abort_if($correction->applicant_user_id === $request->user()->Id, 403);

        return $correction;
    }

    /** @return array<int, array<string, mixed>> */
    private function displayChanges(int $id): array
    {
        $changes = DB::table('profile_correction_changes')->where('request_id', $id)->get();
        $keys = array_values($changes->pluck('field_key')->all());
        $old = $changes->pluck('old_raw', 'field_key')->all();
        $new = $changes->pluck('new_raw', 'field_key')->all();
        $oldDisplay = $this->profileValues->resolve((object) $old, $keys);
        $newDisplay = $this->profileValues->resolve((object) $new, $keys);

        return $changes->map(fn ($change) => [
            'field' => $change->field_key, 'label' => $change->field_label,
            'old' => $oldDisplay[$change->field_key], 'new' => $newDisplay[$change->field_key],
        ])->all();
    }
}
