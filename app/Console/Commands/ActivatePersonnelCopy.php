<?php

namespace App\Console\Commands;

use App\Services\InstanceDataCopy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ActivatePersonnelCopy extends Command
{
    protected $signature = 'personnel:activate-copy {--execute} {--activate}';

    protected $description = 'Provision restricted target accounts and activate a verified instance copy';

    public function handle(InstanceDataCopy $copy): int
    {
        if (! $this->option('execute')) {
            $this->line('Dry run: create five restricted target accounts. Add --execute to provision; add --activate after write verification to switch .env.');

            return self::SUCCESS;
        }
        $manifest = $this->json(config('data_copy.manifest'));
        if (! isset($manifest['completed_at']) || $manifest['target_host'] !== config('data_copy.target.host')) {
            throw new RuntimeException('A completed target snapshot is required.');
        }
        $path = storage_path('app/private/migration/runtime-credentials.json');
        $credentials = is_file($path) ? $this->json($path) : [];
        $names = ['app' => 'rsxt_app183', 'reader' => 'rsxt_read183', 'profile' => 'rsxt_profile183', 'assessment' => 'rsxt_assess183', 'leave' => 'rsxt_leave183'];
        $admin = $copy->connect(config('data_copy.target'), false);
        if ($credentials === []) {
            foreach ($names as $kind => $username) {
                $check = $copy->prepare($admin, "SELECT COUNT(*) FROM mysql.user WHERE User=? AND Host='%'");
                $check->execute([$username]);
                if ((int) $check->fetchColumn() !== 0) {
                    throw new RuntimeException('An unmanaged runtime account already exists.');
                }
                $credentials[$kind] = ['username' => $username, 'password' => 'Rsxt!183'.bin2hex(random_bytes(24)).'aA9'];
            }
            file_put_contents($path, json_encode($credentials, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        }
        $identities = [];
        foreach ($credentials as $kind => $account) {
            $identity = $admin->quote($account['username'])."@'%'";
            $identities[$kind] = $identity;
            $admin->exec('CREATE USER IF NOT EXISTS '.$identity.' IDENTIFIED BY '.$admin->quote($account['password']));
        }
        $admin->exec('GRANT SELECT,INSERT,UPDATE,DELETE ON `rsxt`.* TO '.$identities['app']);
        $admin->exec('GRANT SELECT ON `uchr`.* TO '.$identities['reader']);
        foreach (['h50', 'user'] as $table) {
            $admin->exec('GRANT SELECT ON `uchr`.'.$copy->quote($table).' TO '.$identities['profile']);
        }
        $fields = array_merge(...array_values(config('profile_corrections.fields')));
        $fields[] = 'updateTime';
        $admin->exec('GRANT UPDATE ('.implode(',', array_map($copy->quote(...), $fields)).') ON `uchr`.`h50` TO '.$identities['profile']);
        $admin->exec('GRANT UPDATE (`truename`) ON `uchr`.`user` TO '.$identities['profile']);
        $admin->exec('GRANT SELECT,UPDATE (`Memo`,`h2025`,`h2030`,`h2013`,`updateTime`) ON `uchr`.`h20` TO '.$identities['assessment']);
        foreach (['a34', 'a35', 'a38'] as $table) {
            $admin->exec('GRANT SELECT,INSERT,UPDATE ON `uchr`.'.$copy->quote($table).' TO '.$identities['leave']);
        }
        $admin->exec('GRANT SELECT,INSERT,UPDATE,DELETE ON `uchr`.`gz_auditors` TO '.$identities['leave']);
        foreach (['user', 'h50', 'department', 'ud09', 'ud10', 'modules', 'workflowmodule_users', 'gz_dpleaders'] as $table) {
            $admin->exec('GRANT SELECT ON `uchr`.'.$copy->quote($table).' TO '.$identities['leave']);
        }
        foreach ($credentials as $kind => $account) {
            $copy->connect([...config('data_copy.target'), ...$account, 'database' => $kind === 'app' ? 'rsxt' : 'uchr']);
        }
        $this->info('Five restricted runtime accounts provisioned and authenticated; credentials stored privately.');
        if (! $this->option('activate')) {
            return self::SUCCESS;
        }
        $verification = $this->json(storage_path('app/private/migration/write-verification.json'));
        if (($verification['passed'] ?? false) !== true || $verification['target_host'] !== config('data_copy.target.host')) {
            throw new RuntimeException('Successful target write verification is required before activation.');
        }
        $fileVerification = $this->json(storage_path('app/private/migration/file-verification.json'));
        if (($fileVerification['passed'] ?? false) !== true || ($fileVerification['copied_files'] ?? 0) === 0) {
            throw new RuntimeException('Successful private file verification is required before activation.');
        }
        if (DB::connection('migration_auxiliary')->table('legacy_file_mappings')->where('status', 'pending')->exists()) {
            throw new RuntimeException('File copy is still pending.');
        }
        $target = config('data_copy.target');
        $stage = config('data_copy.sources.rsxt');
        $settings = [
            'DB_CONNECTION' => 'mysql', 'DB_URL' => '', 'DB_HOST' => $target['host'], 'DB_PORT' => $target['port'],
            'DB_DATABASE' => 'rsxt', 'DB_USERNAME' => $credentials['app']['username'], 'DB_PASSWORD' => $credentials['app']['password'],
            'LEGACY_DB_HOST' => $target['host'], 'LEGACY_DB_PORT' => $target['port'], 'LEGACY_DB_DATABASE' => 'uchr',
            'LEGACY_DB_USERNAME' => $credentials['reader']['username'], 'LEGACY_DB_PASSWORD' => $credentials['reader']['password'],
            'LEGACY_DB_CHARSET' => 'utf8mb4', 'LEGACY_DB_COLLATION' => 'utf8mb4_unicode_ci',
            'LEGACY_WRITE_ENABLED' => 'false', 'LEGACY_STAGE_DB_HOST' => $stage['host'], 'LEGACY_STAGE_DB_PORT' => $stage['port'],
            'LEGACY_STAGE_DB_USERNAME' => $stage['username'], 'LEGACY_STAGE_DB_PASSWORD' => $stage['password'],
            'LEGACY_PRIVATE_FILES_ENABLED' => 'true', 'LEGACY_FILES_REMOTE_FALLBACK' => 'false', 'LEAVE_PRIVATE_ATTACHMENTS' => 'true',
            'PROFILE_CORRECTION_WRITE_TARGET' => 'production', 'PROFILE_CORRECTION_PRODUCTION_WRITES_ENABLED' => 'true',
            'PROFILE_CORRECTION_TRANSACTIONAL_IDENTITY' => 'true',
            'ASSESSMENT_WRITE_TARGET' => 'production', 'ASSESSMENT_PRODUCTION_WRITES_ENABLED' => 'true',
            'LEAVE_WRITE_TARGET' => 'production', 'LEAVE_PRODUCTION_WRITES_ENABLED' => 'true',
        ];
        foreach (['PROFILE' => 'profile', 'ASSESSMENT' => 'assessment', 'LEAVE' => 'leave'] as $tag => $kind) {
            $settings['LEGACY_'.$tag.'_DB_USERNAME'] = $credentials[$kind]['username'];
            $settings['LEGACY_'.$tag.'_DB_PASSWORD'] = $credentials[$kind]['password'];
        }
        $environment = file_get_contents(base_path('.env'));
        if ($environment === false) {
            throw new RuntimeException('Application environment could not be read.');
        }
        $environment = $copy->rewriteEnvironment($environment, $settings);
        file_put_contents(base_path('.env.copy-next'), $environment.PHP_EOL);
        rename(base_path('.env.copy-next'), base_path('.env'));
        $this->call('config:clear');
        $this->info('Application configuration now targets the new instance; original source settings are retained for read-only inventory.');

        return self::SUCCESS;
    }

    /** @return array<string,mixed> */
    private function json(string $path): array
    {
        $contents = is_file($path) ? file_get_contents($path) : false;
        if ($contents === false) {
            throw new RuntimeException('Required migration record is unavailable: '.basename($path));
        }

        return json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
    }
}
