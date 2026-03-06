<?php

namespace HendaBastian\LaracrudApigen;

use Illuminate\Support\ServiceProvider;
use HendaBastian\LaracrudApigen\Commands\GenerateCrudApi;
use HendaBastian\LaracrudApigen\Commands\InstallCrudGenerator;

class CrudGeneratorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/crud-generator.php', 'crud-generator');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                GenerateCrudApi::class,
                InstallCrudGenerator::class,
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
}
