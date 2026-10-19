<?php

namespace Tests\Feature;

use Tests\TestCase;
use ZipArchive;

class BackupTest extends TestCase
{
    public function test_backup_zips_the_blacksmith_directory()
    {
        $this->seedBlueprint();

        config([
            'blacksmith.project' => 'acme',
            'blacksmith.backup_storage' => ['driver' => 'local', 'root' => $this->root.'-backups'],
        ]);

        $this->artisan('blacksmith:backup')->assertSuccessful();

        $backups = glob($this->root.'-backups/blacksmith_backups/*-acme-backup.zip');
        $this->assertCount(1, $backups);

        $zip = new ZipArchive;
        $zip->open($backups[0]);

        $this->assertNotFalse($zip->locateName('state.json'));
        $this->assertNotFalse($zip->locateName('sites/example.com/.env'));
        $this->assertNotFalse($zip->locateName('servers/app.json'));

        $zip->close();
        array_map('unlink', $backups);
        rmdir($this->root.'-backups/blacksmith_backups');
        rmdir($this->root.'-backups');
    }

    public function test_backup_requires_a_project_name()
    {
        $this->artisan('blacksmith:backup')
            ->expectsOutput('Please set the project name in the blacksmith config file.')
            ->assertFailed();
    }
}
