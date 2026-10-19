<?php

namespace Grafite\Blacksmith\Commands;

use Illuminate\Support\Facades\Storage;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ZipArchive;

class Backup extends BlacksmithCommand
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
     * @return int
     */
    public function handle()
    {
        if (is_null(config('blacksmith.project'))) {
            $this->error('Please set the project name in the blacksmith config file.');

            return self::FAILURE;
        }

        $root = $this->blueprints()->root();

        if (! is_dir($root)) {
            $this->error("Nothing to back up, {$root} does not exist.");

            return self::FAILURE;
        }

        $path = 'blacksmith_backups/'.date('Y-m-d-H-i').'-'.config('blacksmith.project').'-backup.zip';
        $archive = tempnam(sys_get_temp_dir(), 'blacksmith');

        $zip = new ZipArchive;
        $zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($files as $file) {
            if (! $file->isDir()) {
                $zip->addFile($file->getRealPath(), substr($file->getPathname(), strlen($root) + 1));
            }
        }

        $zip->close();

        Storage::build(config('blacksmith.backup_storage'))->put($path, file_get_contents($archive));

        unlink($archive);

        $this->info('Backup saved to '.$path);

        return self::SUCCESS;
    }
}
