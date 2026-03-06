<?php

namespace HendaBastian\LaracrudApigen\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

class InstallCrudGenerator extends Command
{
    protected $signature = 'crud-generator:install';

    protected $description = 'Install the CRUD Generator package (publish config and base repository files).';

    public function handle(Filesystem $files): int
    {
        $this->info('Installing CRUD Generator...');

        // Publish config
        $this->call('vendor:publish', [
            '--tag' => 'crud-generator-config',
        ]);

        // Publish base repository files
        $baseRepoPath = app_path('Repositories/BaseRepository.php');
        $baseInterfacePath = app_path('Repositories/Contracts/BaseRepositoryInterface.php');

        if ($files->exists($baseRepoPath) || $files->exists($baseInterfacePath)) {
            if (!$this->confirm('Base repository files already exist. Overwrite?', false)) {
                $this->warn('Skipped base repository files.');
            } else {
                $this->call('vendor:publish', [
                    '--tag' => 'crud-generator-repositories',
                    '--force' => true,
                ]);
            }
        } else {
            $this->call('vendor:publish', [
                '--tag' => 'crud-generator-repositories',
            ]);
        }

        // Ensure routes/api.php exists
        $routeFile = base_path(config('crud-generator.route_file', 'routes/api.php'));
        if (!$files->exists($routeFile)) {
            $files->ensureDirectoryExists(dirname($routeFile));
            $files->put($routeFile, <<<'PHP'
            <?php

            use Illuminate\Support\Facades\Route;

            PHP);
            $this->components->info("Created {$routeFile}");
        }

        $this->newLine();
        $this->info('CRUD Generator installed successfully!');
        $this->line('Run <comment>php artisan generate:crud-api {Model}</comment> to generate a CRUD API.');

        return self::SUCCESS;
    }
}
