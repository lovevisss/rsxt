<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\LegacyAccess;
use App\Services\LegacyFields;
use App\Services\LegacyPortrait;
use App\Services\LegacyProfileSections;
use App\Services\LegacyProfileValues;
use App\Services\ProfileCorrectionAccess;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PersonnelController extends Controller
{
    public function __construct(private readonly LegacyAccess $access, private readonly LegacyFields $fields, private readonly LegacyProfileValues $profileValues, private readonly LegacyProfileSections $profileSections, private readonly LegacyPortrait $portrait) {}

    public function dashboard(Request $request): Response
    {
        $user = $request->user();
        $available = $this->access->availableModules($user);

        return Inertia::render('Personnel/Dashboard', [
            'modules' => $available,
            'person' => $this->profileRow($user),
            'assessmentCount' => isset($available['assessments'])
                ? DB::connection('legacy')->table('h20')->where('h2002', $user->username)->where('yndelete', '0')->count()
                : null,
            'leaveCount' => isset($available['leave'])
                ? DB::connection('legacy')->table('a34')->where('CreatorId', $user->Id)
                    ->whereIn('a3410', ['1', '2'])
                    ->where(fn ($q) => $q->where('yndelete', '0')->orWhere('issubmit', 'y'))->count()
                : null,
        ]);
    }

    public function profile(Request $request): Response
    {
        $this->requireModule($request->user(), 'profile');
        $sections = $this->profileSections->all();
        $active = (string) $request->query('section', 'basic');
        abort_unless(isset($sections[$active]), 404);
        $fields = $sections[$active]['fields'];
        $columns = array_values(array_unique([...array_keys($fields), 'h5001', 'h5002', 'h5003']));
        $person = $this->profileRow($request->user(), $columns);
        $resolved = $person ? $this->profileValues->resolve($person, $columns) : null;

        $navigation = [];
        foreach ($sections as $key => $section) {
            $navigation[] = [
                'key' => $key,
                'title' => $section['title'],
                'description' => $section['description'],
                'count' => count($section['fields']),
            ];
        }
        $displayFields = [];
        foreach ($fields as $key => $label) {
            $displayFields[] = ['key' => $key, 'label' => $label];
        }

        return Inertia::render('Personnel/Profile', [
            'modules' => $this->access->availableModules($request->user()),
            'sections' => $navigation,
            'activeSection' => $active,
            'fields' => $displayFields,
            'record' => $resolved ? array_intersect_key($resolved, $fields) : null,
            'identity' => $resolved ? array_intersect_key($resolved, array_flip(['h5001', 'h5002', 'h5003'])) : null,
            'hasPortrait' => $person !== null && $this->portrait->hasReference($request->user()),
            'canRequestCorrection' => app(ProfileCorrectionAccess::class)->canApply($request->user()),
        ]);
    }

    public function portrait(Request $request): \Illuminate\Http\Response
    {
        $this->requireModule($request->user(), 'profile');
        abort_if($this->profileRow($request->user(), ['h5002']) === null, 404);
        $photo = $this->portrait->fetch($request->user());
        abort_if($photo === null, 404);

        return response($photo['content'])
            ->header('Content-Type', $photo['mime'])
            ->header('Cache-Control', 'private, no-store')
            ->header('X-Content-Type-Options', 'nosniff');
    }

    public function printProfile(Request $request): \Illuminate\Http\Response
    {
        return response()->view('personnel.profile-document', [...$this->profileDocument($request), 'forPdf' => false])
            ->header('Cache-Control', 'private, no-store');
    }

    public function downloadProfile(Request $request): \Illuminate\Http\Response
    {
        $data = $this->profileDocument($request);
        $memoryLimit = (string) ini_get('memory_limit');
        if (preg_match('/^(\d+)([KMG]?)$/i', $memoryLimit, $matches)) {
            $multiplier = match (strtoupper($matches[2])) {
                'G' => 1024 * 1024 * 1024,
                'M' => 1024 * 1024,
                'K' => 1024,
                default => 1,
            };
            if ((int) $matches[1] * $multiplier < 256 * 1024 * 1024) {
                ini_set('memory_limit', '256M');
            }
        }
        $directory = storage_path('app/dompdf-fonts');
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        $options = new Options;
        $options->setChroot(resource_path('fonts'));
        $options->setFontDir($directory);
        $options->setFontCache($directory);
        $options->setTempDir($directory);
        $options->setIsRemoteEnabled(false);
        $options->setDefaultFont('WenQuanYi');
        $pdf = new Dompdf($options);
        $fontPath = str_replace('\\', '/', resource_path('fonts/wqy-microhei.ttf'));
        $pdf->loadHtml(view('personnel.profile-document', [
            ...$data,
            'forPdf' => true,
            'fontUri' => 'file://'.$fontPath,
        ])->render(), 'UTF-8');
        $pdf->setPaper('A4');
        $pdf->render();
        $name = rawurlencode((string) ($data['identity']['h5002'] ?? 'profile'));

        return response($pdf->output())
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', "attachment; filename*=UTF-8''{$name}.pdf")
            ->header('Cache-Control', 'private, no-store')
            ->header('X-Content-Type-Options', 'nosniff');
    }

    /** @return array{sections:array<string, array{title:string, description:string, fields:array<string,string>}>, values:array<string, string|null>, identity:array<string, string|null>, photoDataUri:string|null} */
    private function profileDocument(Request $request): array
    {
        $this->requireModule($request->user(), 'profile');
        $sections = $this->profileSections->all();
        $columns = array_values(array_unique(array_merge(...array_map(fn ($section) => array_keys($section['fields']), array_values($sections)))));
        $person = $this->profileRow($request->user(), $columns);
        abort_if($person === null, 404);
        $values = $this->profileValues->resolve($person, $columns);
        $photo = $this->portrait->fetch($request->user());

        return [
            'sections' => $sections,
            'values' => $values,
            'identity' => array_intersect_key($values, array_flip(['h5001', 'h5002', 'h5003'])),
            'photoDataUri' => $photo ? 'data:'.$photo['mime'].';base64,'.base64_encode($photo['content']) : null,
        ];
    }

    private function requireModule(User $user, string $module): void
    {
        abort_unless($this->access->canOpenModule($user, $module), 403);
    }

    /** @param list<string>|null $columns */
    private function profileRow(User $user, ?array $columns = null): ?object
    {
        if (! $this->access->canOpen($user, config('personnel.modules.profile.menu_id'))) {
            return null;
        }

        return DB::connection('legacy')->table('h50')
            ->where('h5002', $user->username)
            ->where('yndelete', '0')
            ->first(['Id', ...($columns ?? ['h5001', 'h5002', 'h5003', 'h5006', 'h5009', 'h5012', 'h5015', 'h5019', 'h5036', 'h5040', 'h5065', 'h5071', 'h5072', 'h5087'])]);
    }
}
