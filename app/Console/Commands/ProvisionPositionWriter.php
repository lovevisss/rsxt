<?php

namespace App\Console\Commands;

use App\Services\InstanceDataCopy;
use Illuminate\Console\Command;
use RuntimeException;

class ProvisionPositionWriter extends Command
{
    protected $signature = 'personnel:provision-position-writer {--execute}';

    protected $description = 'Create a restricted h06/h50 writer on the copied 183 database and activate it';

    public function handle(InstanceDataCopy $copy): int
    {
        if (! $this->option('execute')) {
            $this->line('Dry run: provision a restricted position writer on the configured copy target and update local .env.');

            return self::SUCCESS;
        }
        $target = config('data_copy.target');
        if ($target['host'] !== config('database.connections.mysql.host')
            || $target['host'] !== config('database.connections.legacy.host')
            || config('database.connections.mysql.database') !== 'rsxt'
            || config('database.connections.legacy.database') !== 'uchr') {
            throw new RuntimeException('The current app is not using the expected copied rsxt/uchr instance.');
        }
        $path = storage_path('app/private/migration/runtime-credentials.json');
        $contents = is_file($path) ? file_get_contents($path) : false;
        $credentials = $contents !== false ? json_decode($contents, true, flags: JSON_THROW_ON_ERROR) : null;
        if (! is_array($credentials) || ! isset($credentials['app'], $credentials['reader'])) {
            throw new RuntimeException('Existing target runtime credentials are required.');
        }
        $admin = $copy->connect($target, false);
        if (! isset($credentials['position'])) {
            $username = 'rsxt_position183';
            $check = $copy->prepare($admin, "SELECT COUNT(*) FROM mysql.user WHERE User=? AND Host='%'");
            $check->execute([$username]);
            if ((int) $check->fetchColumn() !== 0) {
                throw new RuntimeException('An unmanaged position writer account already exists.');
            }
            $credentials['position'] = ['username' => $username, 'password' => 'Rsxt!183'.bin2hex(random_bytes(24)).'aA9'];
            file_put_contents($path, json_encode($credentials, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        }
        $account = $credentials['position'];
        $identity = $admin->quote($account['username'])."@'%'";
        $admin->exec('CREATE USER IF NOT EXISTS '.$identity.' IDENTIFIED BY '.$admin->quote($account['password']));
        $admin->exec('GRANT SELECT,INSERT,UPDATE ON `uchr`.`h06` TO '.$identity);
        $admin->exec('GRANT SELECT,INSERT,UPDATE ON `uchr`.`h17` TO '.$identity);
        $admin->exec('GRANT SELECT,INSERT,UPDATE ON `uchr`.`h54` TO '.$identity);
        $admin->exec('GRANT SELECT ON `uchr`.`h50` TO '.$identity);
        $admin->exec('GRANT UPDATE (`h5019`,`h5021`,`h5094`,`h5073`,`updateTime`) ON `uchr`.`h50` TO '.$identity);
        $admin->exec('GRANT UPDATE (`h5071`,`h5072`,`updateTime`) ON `uchr`.`h50` TO '.$identity);
        $admin->exec('GRANT UPDATE (`h5003`,`updateTime`) ON `uchr`.`h50` TO '.$identity);
        $admin->exec('GRANT SELECT ON `uchr`.`department` TO '.$identity);
        $admin->exec('GRANT SELECT ON `uchr`.`user` TO '.$identity);
        $admin->exec('GRANT UPDATE (`departmentId`,`updatetime`) ON `uchr`.`user` TO '.$identity);
        $admin->exec('GRANT SELECT,INSERT ON `rsxt`.`department_profile_baselines` TO '.$identity);
        $admin->exec('GRANT SELECT,INSERT ON `rsxt`.`department_change_events` TO '.$identity);
        $copy->connect([...$target, ...$account, 'database' => 'uchr']);
        $settings = [
            'LEGACY_POSITION_DB_USERNAME' => $account['username'],
            'LEGACY_POSITION_DB_PASSWORD' => $account['password'],
            'POSITION_WRITE_TARGET' => 'production',
            'POSITION_PRODUCTION_WRITES_ENABLED' => 'true',
        ];
        $environment = file_get_contents(base_path('.env'));
        if ($environment === false) {
            throw new RuntimeException('Application environment could not be read.');
        }
        file_put_contents(base_path('.env.position-next'), $copy->rewriteEnvironment($environment, $settings).PHP_EOL);
        rename(base_path('.env.position-next'), base_path('.env'));
        $this->call('config:clear');
        $this->info('Restricted position writer provisioned and activated for the copied instance.');

        return self::SUCCESS;
    }
}
