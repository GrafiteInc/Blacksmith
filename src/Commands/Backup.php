<?php

namespace Grafite\Blacksmith\Commands;

use ZipArchive;
use Illuminate\Support\Str;
use RecursiveIteratorIterator;
use Illuminate\Console\Command;
use RecursiveDirectoryIterator;
use Illuminate\Support\Facades\Storage;

class Backup extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'blacksmith:backup';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Backup all the blacksmith files.';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $config = json_decode(file_get_contents(base_path('.blacksmith/config.json')), true);
        $path = 'blacksmith_backups/'.date('Y-m-d-H-i').'-'.$config['project'].'-backup.zip';
        $rootPath = base_path('.blacksmith');

        $zip = new ZipArchive();
        $zip->open(base_path('.blacksmith/backup.zip'), ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($rootPath),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($files as $file) {
            // Skip directories (they would be added automatically)
            if (! $file->isDir()) {
                // Get real and relative path for current file
                $filePath = $file->getRealPath();
                if (! Str::of($filePath)->contains('backup')) {
                    $relativePath = substr($filePath, strlen($rootPath) + 1);

                    // Add current file to archive
                    $zip->addFile($filePath, $relativePath);
                }
            }
        }

        // Zip archive will be created only after closing object
        $zip->close();

        Storage::build(config('blacksmith.backup_storage'))->put($path, file_get_contents(base_path('.blacksmith/backup.zip')));

        unlink(base_path('.blacksmith/backup.zip'));

        $this->info('Backup saved.');

        return 0;
    }
}
