<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class DatabaseBackup extends Command
{
    protected $signature = 'db:backup {--max-files=7 : Maximum backup files to keep}';

    protected $description = 'Create a compressed MySQL database backup';

    public function handle(): int
    {
        $database = config('database.connections.mysql.database');
        $username = config('database.connections.mysql.username');
        $password = config('database.connections.mysql.password');
        $host = config('database.connections.mysql.host');
        $port = config('database.connections.mysql.port', 3306);

        $backupDir = storage_path('app/backups');
        if (! is_dir($backupDir)) {
            mkdir($backupDir, 0750, true);
        }

        $filename = "backup_{$database}_" . date('Y-m-d_His') . '.sql.gz';
        $filepath = "{$backupDir}/{$filename}";

        $passwordArg = $password !== '' && $password !== null
            ? '--password=' . escapeshellarg($password)
            : '';

        $command = sprintf(
            'mysqldump --host=%s --port=%s --user=%s %s --single-transaction --routines --triggers %s | gzip > %s',
            escapeshellarg($host),
            escapeshellarg((string) $port),
            escapeshellarg($username),
            $passwordArg,
            escapeshellarg($database),
            escapeshellarg($filepath)
        );

        $result = null;
        $output = [];
        exec($command . ' 2>&1', $output, $result);

        if ($result !== 0) {
            $errorMsg = implode("\n", $output);
            Log::channel('security')->error("Database backup failed: {$errorMsg}");
            $this->error("Backup failed: {$errorMsg}");

            return self::FAILURE;
        }

        $size = filesize($filepath);
        $sizeFormatted = number_format($size / 1024 / 1024, 2) . ' MB';

        Log::channel('security')->info("Database backup created: {$filename} ({$sizeFormatted})");
        $this->info("Backup created: {$filename} ({$sizeFormatted})");

        $this->pruneOldBackups($backupDir, (int) $this->option('max-files'));

        return self::SUCCESS;
    }

    private function pruneOldBackups(string $dir, int $maxFiles): void
    {
        $files = glob("{$dir}/backup_*.sql.gz");
        if ($files === false || count($files) <= $maxFiles) {
            return;
        }

        usort($files, fn (string $a, string $b) => filemtime($b) <=> filemtime($a));

        $toDelete = array_slice($files, $maxFiles);
        foreach ($toDelete as $file) {
            unlink($file);
            $this->line('Pruned old backup: ' . basename($file));
        }
    }
}
