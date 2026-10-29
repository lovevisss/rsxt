<?php

namespace App\Http\Controllers;

use App\Services\LegacyAccess;
use App\Services\LegacyCertificate;
use App\Services\LegacyHistory;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class PersonnelHistoryController extends Controller
{
    public function __construct(private readonly LegacyAccess $access, private readonly LegacyHistory $history, private readonly LegacyCertificate $certificate) {}

    public function index(Request $request): InertiaResponse
    {
        $tabs = [];
        foreach (config('personnel_history') as $key => $definition) {
            if ($this->access->canOpen($request->user(), $definition['menu_id'])) {
                $tabs[] = ['key' => $key, 'title' => $definition['title']];
            }
        }
        abort_if($tabs === [], 403);
        $tab = (string) $request->query('tab', $tabs[0]['key']);
        $definition = config("personnel_history.$tab");
        abort_if($definition === null, 404);
        abort_unless($this->access->canOpen($request->user(), $definition['menu_id']), 403);
        $page = filter_var($request->query('page', 1), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        abort_if($page === false, 404);

        return Inertia::render('Personnel/History', [
            'modules' => $this->access->availableModules($request->user()),
            'tabs' => $tabs,
            'activeTab' => $tab,
            'history' => $this->history->page($request->user(), $tab, $page),
        ]);
    }

    public function certificate(Request $request): Response
    {
        $tab = (string) $request->query('tab');
        $definition = config("personnel_history.$tab");
        abort_if($definition === null, 404);
        abort_unless($this->access->canOpen($request->user(), $definition['menu_id']), 403);
        $field = (string) $request->query('field');
        abort_unless(in_array($field, $definition['certificates'], true), 404);
        $id = filter_var($request->query('id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        abort_if($id === false, 404);
        $reference = DB::connection('legacy')->table($definition['table'])
            ->where('Id', $id)->where($definition['employee'], $request->user()->username)
            ->where('yndelete', '0')->value($field);
        abort_if(trim((string) $reference) === '', 404);
        $photo = $this->certificate->fetch($reference);
        abort_if($photo === null, 404);

        return response($photo['content'])->header('Content-Type', $photo['mime'])
            ->header('Cache-Control', 'private, no-store')
            ->header('X-Content-Type-Options', 'nosniff');
    }
}
