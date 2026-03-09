<?php

namespace HendaBastian\LaracrudApigen;

use Illuminate\Support\ServiceProvider;
use HendaBastian\LaracrudApigen\Commands\GenerateCrudApi;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class CrudGeneratorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/crud-generator.php', 'crud-generator');

        $this->autoBindRepositories();
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                GenerateCrudApi::class,
            ]);

            $this->publishes([
                __DIR__ . '/../config/crud-generator.php' => config_path('crud-generator.php'),
            ], 'crud-generator-config');

            $this->publishes([
                __DIR__ . '/../stubs/BaseRepository.stub' => app_path('Repositories/BaseRepository.php'),
                __DIR__ . '/../stubs/BaseRepositoryInterface.stub' => app_path('Repositories/Contracts/BaseRepositoryInterface.php'),
            ], 'crud-generator-repositories');
        }
    }

    protected function autoBindRepositories(): void
    {
        $repoNs = config('crud-generator.repository_namespace', 'App\\Repositories');
        $contractsPath = base_path(str_replace('\\', '/', lcfirst($repoNs)) . '/Contracts');

        if (! File::isDirectory($contractsPath)) {
            return;
        }

        $files = File::files($contractsPath);

        foreach ($files as $file) {
            $filename = $file->getFilenameWithoutExtension();

            // Skip the base interface
            if ($filename === 'BaseRepositoryInterface') {
                continue;
            }

            // e.g. CompanyRepositoryInterface -> CompanyRepository
            if (! Str::endsWith($filename, 'RepositoryInterface')) {
                continue;
            }

            $repoName = Str::replaceLast('Interface', '', $filename);
            $interfaceClass = "{$repoNs}\\Contracts\\{$filename}";
            $repositoryClass = "{$repoNs}\\{$repoName}";

            if (class_exists($repositoryClass) && interface_exists($interfaceClass)) {
                $this->app->bind($interfaceClass, $repositoryClass);
            }
        }
    }
}
