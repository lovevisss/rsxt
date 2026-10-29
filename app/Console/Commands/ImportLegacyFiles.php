<?php

namespace App\Console\Commands;

use App\Services\InstanceDataCopy;
use App\Services\MigratedLegacyFiles;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ResponseException;
use GuzzleHttp\Pool;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PDO;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

class ImportLegacyFiles extends Command
{
    protected $signature = 'personnel:import-files {--execute} {--verify} {--retry-missing}';

    protected $description = 'Inventory and copy upload references from the new snapshot into private storage';

    public function handle(InstanceDataCopy $copy, MigratedLegacyFiles $files): int
    {
        $settings = config('data_copy.target');
        $settings['database'] = 'uchr';
        $source = $copy->connect($settings);
        $source->exec("SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO'");
        $mapping = DB::connection('migration_auxiliary');
        if ($this->option('verify')) {
            if ($mapping->table('legacy_file_mappings')->where('status', 'pending')->exists()) {
                throw new RuntimeException('File downloads are still pending.');
            }
            $count = 0;
            foreach ($mapping->table('legacy_file_mappings')->where('status', 'copied')->cursor() as $file) {
                $path = Storage::disk($file->disk)->path($file->path);
                if (! is_file($path) || filesize($path) !== (int) $file->size || hash_file('sha256', $path) !== $file->sha256) {
                    throw new RuntimeException('Private file failed verification, mapping #'.$file->id);
                }
                $count++;
            }
            file_put_contents(Storage::disk('local')->path('migration/file-verification.json'), json_encode([
                'passed' => true, 'copied_files' => $count,
                'missing_source_files' => $mapping->table('legacy_file_mappings')->where('status', 'missing')->count(),
                'completed_at' => now()->toIso8601String(),
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            $this->missingReport($mapping);
            $this->info("Verified {$count} private files.");

            return self::SUCCESS;
        }
        $columns = $copy->query($source, "SELECT c.TABLE_NAME,c.COLUMN_NAME FROM information_schema.COLUMNS c JOIN information_schema.TABLES t ON t.TABLE_SCHEMA=c.TABLE_SCHEMA AND t.TABLE_NAME=c.TABLE_NAME WHERE c.TABLE_SCHEMA=DATABASE() AND t.TABLE_TYPE='BASE TABLE' AND c.DATA_TYPE IN ('char','varchar','text','mediumtext','longtext','tinytext') ORDER BY c.TABLE_NAME,c.ORDINAL_POSITION")->fetchAll(PDO::FETCH_ASSOC);
        $tables = [];
        foreach ($columns as $column) {
            $tables[$column['TABLE_NAME']][] = $column['COLUMN_NAME'];
        }
        $urls = [];
        $invalid = [];
        $invalidBusiness = [];
        $businessFields = ['h55' => ['h5504'], 'h70' => ['h7014'], 'h60' => ['h6022', 'h6023'], 'a35' => ['a3509']];
        foreach ($tables as $table => $fields) {
            $select = implode(',', array_map($copy->quote(...), $fields));
            $where = implode(' OR ', array_map(fn (string $field): string => $copy->quote($field)." LIKE '%useruploadfiles%'", $fields));
            foreach ($copy->query($source, 'SELECT '.$select.' FROM '.$copy->quote($table).' WHERE '.$where) as $row) {
                foreach ($row as $field => $raw) {
                    if (in_array($field, $businessFields[$table] ?? [], true)) {
                        continue;
                    }
                    if (! is_string($raw) || ! str_contains($raw, 'useruploadfiles')) {
                        continue;
                    }
                    $references = $files->references($raw);
                    if ($references === []) {
                        $invalid[$table.'.'.$field] = ($invalid[$table.'.'.$field] ?? 0) + 1;

                        continue;
                    }
                    foreach ($references as $url) {
                        $urls[$url] = ($urls[$url] ?? 0) + 1;
                    }
                }
            }
        }
        foreach ($businessFields as $table => $fields) {
            foreach ($fields as $field) {
                $statement = $copy->query($source, 'SELECT `Id`,'.$copy->quote($field).' FROM '.$copy->quote($table)
                    .' WHERE '.$copy->quote($field).' IS NOT NULL AND '.$copy->quote($field)." <> ''");
                foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $record) {
                    $reference = $record[$field];
                    $url = $files->canonicalUrl($reference) ?? ($files->references($reference)[0] ?? null);
                    if ($url === null) {
                        $invalid[$table.'.'.$field] = ($invalid[$table.'.'.$field] ?? 0) + 1;
                        $invalidBusiness[] = ['table' => $table, 'id' => $record['Id'], 'field' => $field];
                    } else {
                        $urls[$url] = ($urls[$url] ?? 0) + 1;
                    }
                }
            }
        }
        $this->line('Distinct file URLs: '.count($urls).'; invalid references: '.array_sum($invalid));
        arsort($invalid);
        foreach (array_slice($invalid, 0, 12, true) as $field => $count) {
            $this->line('Unresolved '.$field.': '.$count.' references');
        }
        if (! $this->option('execute')) {
            $this->warn('Inventory only; add --execute to download into private storage.');

            return self::SUCCESS;
        }
        foreach ($urls as $url => $references) {
            $mapping->table('legacy_file_mappings')->upsert([
                ['source_key' => hash('sha256', $url), 'source_url' => $url, 'reference_count' => $references,
                    'status' => 'pending', 'created_at' => now(), 'updated_at' => now()],
            ], ['source_key'], ['reference_count', 'updated_at']);
        }
        $activeKeys = array_map(fn (string $url): string => hash('sha256', $url), array_keys($urls));
        $mapping->table('legacy_file_mappings')->whereNotIn('source_key', $activeKeys)->update(['reference_count' => 0]);
        $mapping->table('legacy_file_mappings')->whereNotIn('source_key', $activeKeys)
            ->whereIn('status', ['pending', 'missing'])->update(['status' => 'unreferenced', 'updated_at' => now()]);
        $mapping->table('legacy_file_mappings')->whereIn('source_key', $activeKeys)->where('status', 'unreferenced')
            ->update(['status' => 'pending', 'updated_at' => now()]);
        $query = $mapping->table('legacy_file_mappings')->whereIn('status', $this->option('retry-missing') ? ['pending', 'missing'] : ['pending'])->orderBy('id');
        $pending = $query->get();
        $client = new Client(['allow_redirects' => false, 'connect_timeout' => 5, 'timeout' => config('legacy_files.download_timeout'), 'proxy' => '']);
        $directory = Storage::disk('local')->path('migration-downloads');
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        $requests = function () use ($pending, $client, $directory) {
            foreach ($pending as $index => $file) {
                $downloadUrl = rtrim(config('legacy_files.download_base_url'), '/').parse_url($file->source_url, PHP_URL_PATH);
                yield $index => fn () => $client->getAsync($downloadUrl, [
                    'sink' => $directory.'/'.$file->source_key.'.part',
                    'progress' => function ($total, $downloaded): void {
                        if ($downloaded > 100 * 1024 * 1024 || $total > 100 * 1024 * 1024) {
                            throw new RuntimeException('Legacy file exceeds 100 MB.');
                        }
                    },
                ]);
            }
        };
        $finished = 0;
        $pool = new Pool($client, $requests(), [
            'concurrency' => 8,
            'fulfilled' => function (ResponseInterface $response, int|string $index) use ($pending, $directory, $mapping, &$finished): void {
                if (! is_int($index)) {
                    throw new RuntimeException('Unexpected file download key.');
                }
                $file = $pending->get($index);
                if ($file === null) {
                    throw new RuntimeException('Unknown file download result.');
                }
                $temp = $directory.'/'.$file->source_key.'.part';
                $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($temp);
                if ($response->getStatusCode() !== 200 || in_array($mime, ['text/html', 'application/xhtml+xml'], true) || filesize($temp) === 0) {
                    $reason = filesize($temp) === 0 ? 'Source returned an empty file.' : 'Source did not return a downloadable file.';
                    $mapping->table('legacy_file_mappings')->where('id', $file->id)->update(['status' => 'missing', 'last_error' => $reason, 'updated_at' => now()]);
                    @unlink($temp);

                    return;
                }
                $sha = hash_file('sha256', $temp);
                if ($sha === false) {
                    throw new RuntimeException('Downloaded file could not be hashed.');
                }
                $path = 'legacy-files/'.substr($sha, 0, 2).'/'.$sha;
                $size = filesize($temp);
                $stream = fopen($temp, 'rb');
                if ($stream === false) {
                    throw new RuntimeException('Downloaded file could not be opened.');
                }
                try {
                    if (! Storage::disk('local')->put($path, $stream)) {
                        throw new RuntimeException('Private file could not be stored.');
                    }
                } finally {
                    fclose($stream);
                }
                if (hash_file('sha256', Storage::disk('local')->path($path)) !== $sha) {
                    throw new RuntimeException('Stored file hash does not match.');
                }
                $mapping->table('legacy_file_mappings')->where('id', $file->id)->update([
                    'status' => 'copied', 'disk' => 'local', 'path' => $path, 'sha256' => $sha,
                    'size' => $size, 'mime' => $mime, 'last_error' => null, 'updated_at' => now(),
                ]);
                unlink($temp);
                $finished++;
                if ($finished % 100 === 0) {
                    $this->line("Downloaded {$finished} files.");
                }
            },
            'rejected' => function (mixed $error, int|string $index) use ($pending, $directory, $mapping): void {
                if (! is_int($index)) {
                    throw new RuntimeException('Unexpected file download key.');
                }
                $file = $pending->get($index);
                if ($file === null) {
                    throw new RuntimeException('Unknown file download failure.');
                }
                $errno = $error instanceof Throwable && preg_match('/cURL error (\d+)/', $error->getMessage(), $matches) ? $matches[1] : 0;
                $status = $error instanceof ResponseException ? $error->getResponse()->getStatusCode() : 0;
                $mapping->table('legacy_file_mappings')->where('id', $file->id)->update([
                    'status' => 'missing', 'last_error' => "Download failed: HTTP {$status}, curl {$errno}.", 'updated_at' => now(),
                ]);
                @unlink($directory.'/'.$file->source_key.'.part');
            },
        ]);
        $pool->promise()->wait();
        $summary = $mapping->table('legacy_file_mappings')->selectRaw('status, COUNT(*) AS count, SUM(size) AS bytes')->groupBy('status')->get()->all();
        file_put_contents(Storage::disk('local')->path('migration/file-report.json'), json_encode(['completed_at' => now()->toIso8601String(), 'summary' => $summary, 'invalid_references_by_field' => $invalid, 'invalid_business_references' => $invalidBusiness], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        $this->missingReport($mapping);
        foreach ($summary as $row) {
            $this->line($row->status.': '.$row->count.' files, '.($row->bytes ?? 0).' bytes');
        }

        return self::SUCCESS;
    }

    private function missingReport(ConnectionInterface $mapping): void
    {
        $stream = fopen(Storage::disk('local')->path('migration/missing-files.csv'), 'wb');
        if ($stream === false) {
            throw new RuntimeException('Missing file report could not be created.');
        }
        try {
            fputcsv($stream, ['mapping_id', 'source_url', 'last_error'], escape: '');
            foreach ($mapping->table('legacy_file_mappings')->where('status', 'missing')->orderBy('id')->cursor() as $file) {
                fputcsv($stream, [$file->id, $file->source_url, $file->last_error], escape: '');
            }
        } finally {
            fclose($stream);
        }
    }
}
