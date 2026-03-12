<?php

namespace HendaBastian\LaracrudApigen\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

class GenerateCrudApi extends Command
{
    protected $signature = 'generate:crud-api
        {model : The model class name (e.g. User, Company, JobAds)}
        {--force : Overwrite existing files}
        {--skip-repository : Skip repository generation}
        {--skip-dto : Skip DTO generation}
        {--skip-routes : Skip route registration}';

    protected $description = 'Generate a complete CRUD API (Controller, Requests, Resource, Repository, DTO, Routes) for a given model.';

    protected Filesystem $files;

    protected string $model;

    protected string $modelVariable;

    protected string $modelPlural;

    protected string $tableName;

    protected array $columns = [];

    protected array $foreignKeys = [];

    protected array $relationships = [];

    public function __construct(Filesystem $files)
    {
        parent::__construct();
        $this->files = $files;
    }

    public function handle(): int
    {
        $this->model = Str::studly($this->argument('model'));
        $this->modelVariable = Str::camel($this->model);
        $this->modelPlural = Str::plural($this->modelVariable);

        $modelNamespace = config('crud-generator.model_namespace', 'App\\Models');
        $modelClass = "{$modelNamespace}\\{$this->model}";

        if (!class_exists($modelClass)) {
            $this->error("Model {$modelClass} does not exist.");

            return self::FAILURE;
        }

        $this->tableName = $this->resolveTableName($modelClass);

        $this->info("Generating CRUD API for {$this->model}...");

        $this->analyzeModel($modelClass);

        if (!$this->option('skip-repository')) {
            $this->ensureBaseRepositoryExists();
        }

        $this->generateRepositoryInterface();
        $this->generateRepository();
        $this->generateCreateRequest();
        $this->generateUpdateRequest();
        $this->generateResource();
        $this->generateController();

        if (!$this->option('skip-dto')) {
            $this->generateDTO();
        }

        if (!$this->option('skip-routes')) {
            $this->registerRoutes();
        }

        $this->newLine();
        $this->info("CRUD API for {$this->model} generated successfully!");
        $this->table(
            ['Component', 'Path'],
            $this->getGeneratedFiles()
        );

        return self::SUCCESS;
    }

    protected function resolveTableName(string $modelClass): string
    {
        return app($modelClass)->getTable();
    }

    protected function analyzeModel(string $modelClass): void
    {
        $model = app($modelClass);
        $table = $model->getTable();
        $excluded = config('crud-generator.excluded_columns', [
            'id',
            'deleted_at',
            'remember_token',
            'email_verified_at',
        ]);

        $schema = $model->getConnection()->getSchemaBuilder();
        $allColumns = $schema->getColumns($table);

        foreach ($allColumns as $column) {
            $columnName = $column['name'];

            if (in_array($columnName, $excluded)) {
                continue;
            }

            $typeName = $column['type_name'] ?? $column['type'] ?? 'varchar';
            $isAutoIncrement = $column['auto_increment'] ?? false;

            if ($isAutoIncrement) {
                continue;
            }

            $default = $column['default'] ?? null;
            if ($default !== null && Str::contains((string) $default, 'nextval(')) {
                $default = null;
            }

            $this->columns[$columnName] = [
                'type' => $typeName,
                'nullable' => $column['nullable'] ?? false,
                'default' => $default,
            ];
        }

        foreach ($this->columns as $name => $col) {
            if (Str::endsWith($name, '_id')) {
                $relatedTable = Str::plural(Str::beforeLast($name, '_id'));
                $this->foreignKeys[$name] = $relatedTable;
                $this->relationships[Str::camel(Str::beforeLast($name, '_id'))] = Str::studly(Str::singular($relatedTable));
            }
        }

        if (empty($this->columns)) {
            $this->warn("No columns detected for table '{$table}'. This may happen if the table name does not match the database (e.g. irregular pluralization). Consider adding \$table property to your model.");
        }
    }

    protected function getGeneratedFiles(): array
    {
        $controllerNs = config('crud-generator.controller_namespace', 'App\\Http\\Controllers\\Api');
        $requestNs = config('crud-generator.request_namespace', 'App\\Http\\Requests\\Api');
        $resourceNs = config('crud-generator.resource_namespace', 'App\\Http\\Resources\\Api');
        $repoNs = config('crud-generator.repository_namespace', 'App\\Repositories');
        $dtoNs = config('crud-generator.dto_namespace', 'App\\DTO');

        $files = [
            ['Controller', $this->namespacePath($controllerNs) . "/{$this->model}Controller.php"],
            ['Create Request', $this->namespacePath($requestNs) . "/{$this->model}/{$this->model}CreateRequest.php"],
            ['Update Request', $this->namespacePath($requestNs) . "/{$this->model}/{$this->model}UpdateRequest.php"],
            ['Resource', $this->namespacePath($resourceNs) . "/{$this->model}Resource.php"],
        ];

        if (!$this->option('skip-repository')) {
            $files[] = ['Repository Interface', $this->namespacePath($repoNs) . "/Contracts/{$this->model}RepositoryInterface.php"];
            $files[] = ['Repository', $this->namespacePath($repoNs) . "/{$this->model}Repository.php"];
        }

        if (!$this->option('skip-dto')) {
            $files[] = ['DTO', $this->namespacePath($dtoNs) . "/{$this->model}DTO.php"];
        }

        if (!$this->option('skip-routes')) {
            $files[] = ['Routes', config('crud-generator.route_file', 'routes/api.php')];
        }

        return $files;
    }

    protected function namespacePath(string $namespace): string
    {
        return str_replace('\\', '/', lcfirst($namespace));
    }

    protected function getStub(string $name): string
    {
        return $this->files->get(dirname(__DIR__, 2) . "/stubs/{$name}.stub");
    }

    protected function replacePlaceholders(string $stub, array $replacements): string
    {
        foreach ($replacements as $key => $value) {
            $stub = str_replace("{{ {$key} }}", $value, $stub);
        }

        return $stub;
    }

    protected function ensureBaseRepositoryExists(): void
    {
        $repoNs = config('crud-generator.repository_namespace', 'App\\Repositories');
        $basePath = base_path($this->namespacePath($repoNs));

        $baseRepoPath = "{$basePath}/BaseRepository.php";
        $baseInterfacePath = "{$basePath}/Contracts/BaseRepositoryInterface.php";

        $missing = !$this->files->exists($baseRepoPath) || !$this->files->exists($baseInterfacePath);

        if (!$missing) {
            return;
        }

        $this->components->warn('Base repository files not found. Publishing them now...');

        $stubPath = dirname(__DIR__, 2) . '/stubs';

        if (!$this->files->exists($baseInterfacePath)) {
            $this->writeFile($baseInterfacePath, $this->files->get("{$stubPath}/BaseRepositoryInterface.stub"));
            $this->components->info("Created: {$baseInterfacePath}");
        }

        if (!$this->files->exists($baseRepoPath)) {
            $this->writeFile($baseRepoPath, $this->files->get("{$stubPath}/BaseRepository.stub"));
            $this->components->info("Created: {$baseRepoPath}");
        }
    }

    protected function generateRepositoryInterface(): void
    {
        if ($this->option('skip-repository')) {
            return;
        }

        $repoNs = config('crud-generator.repository_namespace', 'App\\Repositories');
        $path = base_path($this->namespacePath($repoNs) . "/Contracts/{$this->model}RepositoryInterface.php");

        if ($this->files->exists($path) && !$this->option('force')) {
            $this->warn("Repository interface already exists: {$path}");

            return;
        }

        $content = $this->replacePlaceholders($this->getStub('RepositoryInterface'), [
            'repositoryNamespace' => $repoNs,
            'model' => $this->model,
        ]);

        $this->writeFile($path, $content);
        $this->components->info("Repository interface created: {$path}");
    }

    protected function generateRepository(): void
    {
        if ($this->option('skip-repository')) {
            return;
        }

        $repoNs = config('crud-generator.repository_namespace', 'App\\Repositories');
        $modelNs = config('crud-generator.model_namespace', 'App\\Models');
        $useQueryBuilder = config('crud-generator.use_query_builder', true);
        $useJsonPaginate = config('crud-generator.use_json_api_paginate', true);

        $path = base_path($this->namespacePath($repoNs) . "/{$this->model}Repository.php");

        if ($this->files->exists($path) && !$this->option('force')) {
            $this->warn("Repository already exists: {$path}");

            return;
        }

        $paginateMethod = $useJsonPaginate ? 'jsonPaginate' : 'paginate';

        $filterFields = $this->getFilterableFields();
        $dateFields = $this->getDateFilterFields();
        $sortFields = $this->getSortableFields();
        $includeRelations = $this->getIncludableRelations();

        $filtersString = $this->buildFiltersArray($filterFields, $dateFields);
        $sortsString = implode(', ', array_map(fn($f) => "'{$f}'", $sortFields));
        $includesString = implode(', ', array_map(fn($f) => "'{$f}'", $includeRelations));

        $hasDateFilters = !empty($dateFields);

        if ($useQueryBuilder) {
            $queryBuilderImport = "use Spatie\\QueryBuilder\\QueryBuilder;";
            $allowedFilterImport = $hasDateFilters ? "\nuse Spatie\\QueryBuilder\\AllowedFilter;" : '';
            $listBody = "        return QueryBuilder::for(\$this->model->query())\n"
                . "            ->allowedFilters([\n"
                . "                {$filtersString}\n"
                . "            ])\n"
                . "            ->allowedSorts([{$sortsString}])\n"
                . "            ->allowedIncludes([{$includesString}])\n"
                . "            ->{$paginateMethod}();";
        } else {
            $queryBuilderImport = '';
            $allowedFilterImport = '';
            $listBody = "        return \$this->model->query()->{$paginateMethod}();";
        }

        $content = $this->replacePlaceholders($this->getStub('Repository'), [
            'repositoryNamespace' => $repoNs,
            'modelNamespace' => $modelNs,
            'model' => $this->model,
            'queryBuilderImport' => $queryBuilderImport,
            'allowedFilterImport' => $allowedFilterImport,
            'listBody' => $listBody,
        ]);

        $this->writeFile($path, $content);
        $this->components->info("Repository created: {$path}");
    }

    protected function generateCreateRequest(): void
    {
        $requestNs = config('crud-generator.request_namespace', 'App\\Http\\Requests\\Api');
        $path = base_path($this->namespacePath($requestNs) . "/{$this->model}/{$this->model}CreateRequest.php");

        if ($this->files->exists($path) && !$this->option('force')) {
            $this->warn("Create request already exists: {$path}");

            return;
        }

        $rules = $this->buildCreateValidationRules();
        $rulesString = $this->formatRulesArray($rules);

        $content = $this->replacePlaceholders($this->getStub('CreateRequest'), [
            'requestNamespace' => $requestNs,
            'model' => $this->model,
            'rules' => $rulesString,
        ]);

        $this->writeFile($path, $content);
        $this->components->info("Create request created: {$path}");
    }

    protected function generateUpdateRequest(): void
    {
        $requestNs = config('crud-generator.request_namespace', 'App\\Http\\Requests\\Api');
        $path = base_path($this->namespacePath($requestNs) . "/{$this->model}/{$this->model}UpdateRequest.php");

        if ($this->files->exists($path) && !$this->option('force')) {
            $this->warn("Update request already exists: {$path}");

            return;
        }

        $rules = $this->buildUpdateValidationRules();
        $rulesString = $this->formatRulesArray($rules);

        $content = $this->replacePlaceholders($this->getStub('UpdateRequest'), [
            'requestNamespace' => $requestNs,
            'model' => $this->model,
            'rules' => $rulesString,
        ]);

        $this->writeFile($path, $content);
        $this->components->info("Update request created: {$path}");
    }

    protected function generateResource(): void
    {
        $resourceNs = config('crud-generator.resource_namespace', 'App\\Http\\Resources\\Api');
        $modelNs = config('crud-generator.model_namespace', 'App\\Models');
        $path = base_path($this->namespacePath($resourceNs) . "/{$this->model}Resource.php");

        if ($this->files->exists($path) && !$this->option('force')) {
            $this->warn("Resource already exists: {$path}");

            return;
        }

        $fields = $this->buildResourceFields();
        $fieldsString = implode("\n", $fields);

        $relationshipLines = [];
        $relationshipImports = [];

        foreach ($this->relationships as $relationName => $relatedModel) {
            $resourceClass = "{$relatedModel}Resource";
            $relationshipImports[] = "use {$resourceNs}\\{$resourceClass};";
            $relationshipLines[] = "            '{$relationName}' => {$resourceClass}::collection(\$this->whenLoaded('{$relationName}')),";
        }

        $relationshipImportsString = !empty($relationshipImports) ? implode("\n", $relationshipImports) : '';
        $relationshipsString = !empty($relationshipLines) ? implode("\n", $relationshipLines) : '';

        $content = $this->replacePlaceholders($this->getStub('Resource'), [
            'resourceNamespace' => $resourceNs,
            'modelNamespace' => $modelNs,
            'model' => $this->model,
            'fields' => $fieldsString,
            'relationshipImports' => $relationshipImportsString,
            'relationships' => $relationshipsString,
        ]);

        $this->writeFile($path, $content);
        $this->components->info("Resource created: {$path}");
    }

    protected function generateController(): void
    {
        $controllerNs = config('crud-generator.controller_namespace', 'App\\Http\\Controllers\\Api');
        $requestNs = config('crud-generator.request_namespace', 'App\\Http\\Requests\\Api');
        $resourceNs = config('crud-generator.resource_namespace', 'App\\Http\\Resources\\Api');
        $modelNs = config('crud-generator.model_namespace', 'App\\Models');
        $repoNs = config('crud-generator.repository_namespace', 'App\\Repositories');
        $useQueryBuilder = config('crud-generator.use_query_builder', true);
        $useJsonPaginate = config('crud-generator.use_json_api_paginate', true);

        $path = base_path($this->namespacePath($controllerNs) . "/{$this->model}Controller.php");

        if ($this->files->exists($path) && !$this->option('force')) {
            $this->warn("Controller already exists: {$path}");

            return;
        }

        $filterFields = $this->getFilterableFields();
        $dateFields = $this->getDateFilterFields();
        $sortFields = $this->getSortableFields();
        $includeRelations = $this->getIncludableRelations();

        $filtersString = $this->buildFiltersArray($filterFields, $dateFields);
        $sortsString = implode(', ', array_map(fn($f) => "'{$f}'", $sortFields));
        $includesString = implode(', ', array_map(fn($f) => "'{$f}'", $includeRelations));

        $hasDateFilters = !empty($dateFields);

        $queryParamAttributes = $this->buildQueryParameterAttributes($filterFields, $sortFields, $includeRelations);

        $skipRepo = $this->option('skip-repository');

        $repositoryImport = $skipRepo ? '' : "use {$repoNs}\\Contracts\\{$this->model}RepositoryInterface;";

        $constructor = $skipRepo ? '' : <<<PHP
    public function __construct(
        private {$this->model}RepositoryInterface \$repository
    ) {}
PHP;

        $paginateMethod = $useJsonPaginate ? 'jsonPaginate' : 'paginate';

        if ($skipRepo) {
            if ($useQueryBuilder) {
                $queryBuilderImport = 'use Spatie\\QueryBuilder\\QueryBuilder;';
                $allowedFilterImport = $hasDateFilters ? "\nuse Spatie\\QueryBuilder\\AllowedFilter;" : '';
                $indexBody = "        \${$this->modelPlural} = QueryBuilder::for({$this->model}::query())\n"
                    . "            ->allowedFilters([\n"
                    . "                {$filtersString}\n"
                    . "            ])\n"
                    . "            ->allowedSorts([{$sortsString}])\n"
                    . "            ->allowedIncludes([{$includesString}])\n"
                    . "            ->{$paginateMethod}();";
            } else {
                $queryBuilderImport = '';
                $allowedFilterImport = '';
                $indexBody = "        \${$this->modelPlural} = {$this->model}::query()->{$paginateMethod}();";
            }
        } else {
            $queryBuilderImport = '';
            $allowedFilterImport = '';
            $indexBody = "        \${$this->modelPlural} = \$this->repository->list();";
        }

        $storeBody = $skipRepo
            ? "{$this->model}::create(\$request->validated());"
            : '$this->repository->create($request->validated());';
        $storeBody = "\$record = {$storeBody}";

        $updateBody = $skipRepo
            ? "\${$this->modelVariable}->update(\$request->validated());\n        \$record = \${$this->modelVariable}->fresh();"
            : "\$record = \$this->repository->update(\${$this->modelVariable}->id, \$request->validated());";

        $deleteBody = $skipRepo
            ? "\${$this->modelVariable}->delete();"
            : "\$this->repository->delete(\${$this->modelVariable}->id);";

        $content = $this->replacePlaceholders($this->getStub('Controller'), [
            'controllerNamespace' => $controllerNs,
            'requestNamespace' => $requestNs,
            'resourceNamespace' => $resourceNs,
            'modelNamespace' => $modelNs,
            'repositoryImport' => $repositoryImport,
            'queryBuilderImport' => $queryBuilderImport,
            'allowedFilterImport' => $allowedFilterImport,
            'model' => $this->model,
            'modelVariable' => $this->modelVariable,
            'modelPlural' => $this->modelPlural,
            'constructor' => $constructor,
            'queryParamAttributes' => $queryParamAttributes,
            'indexBody' => $indexBody,
            'storeBody' => $storeBody,
            'updateBody' => $updateBody,
            'deleteBody' => $deleteBody,
        ]);

        $this->writeFile($path, $content);
        $this->components->info("Controller created: {$path}");
    }

    protected function generateDTO(): void
    {
        $dtoNs = config('crud-generator.dto_namespace', 'App\\DTO');
        $useSpatieData = config('crud-generator.use_spatie_data', true);
        $path = base_path($this->namespacePath($dtoNs) . "/{$this->model}DTO.php");

        if ($this->files->exists($path) && !$this->option('force')) {
            $this->warn("DTO already exists: {$path}");

            return;
        }

        $properties = $this->buildDTOProperties();
        $propertiesString = implode("\n", $properties);

        $stubName = $useSpatieData ? 'DTOSpatie' : 'DTO';

        $content = $this->replacePlaceholders($this->getStub($stubName), [
            'dtoNamespace' => $dtoNs,
            'model' => $this->model,
            'properties' => $propertiesString,
        ]);

        $this->writeFile($path, $content);
        $this->components->info("DTO created: {$path}");
    }

    protected function registerRoutes(): void
    {
        $controllerNs = config('crud-generator.controller_namespace', 'App\\Http\\Controllers\\Api');
        $routeFile = base_path(config('crud-generator.route_file', 'routes/api.php'));
        $routeName = Str::kebab(Str::plural($this->model));
        $controllerClass = "\\{$controllerNs}\\{$this->model}Controller::class";
        $routeLine = "Route::apiResource('{$routeName}', {$controllerClass});";

        if (!$this->files->exists($routeFile)) {
            $content = $this->replacePlaceholders($this->getStub('Routes'), [
                'routeLine' => $routeLine,
            ]);

            $this->writeFile($routeFile, $content);
            $this->components->info("Routes file created with {$this->model} routes.");

            return;
        }

        $existingContent = $this->files->get($routeFile);

        if (Str::contains($existingContent, $routeName)) {
            $this->warn("Route for {$routeName} already exists in {$routeFile}");

            return;
        }

        $this->files->append($routeFile, "\n{$routeLine}\n");
        $this->components->info("Route registered for {$this->model} in {$routeFile}");
    }

    protected function buildCreateValidationRules(): array
    {
        $rules = [];

        foreach ($this->columns as $name => $column) {
            $columnRules = [];

            if ($column['nullable'] || $column['default'] !== null) {
                $columnRules[] = 'nullable';
            } else {
                $columnRules[] = 'required';
            }

            $columnRules = [...$columnRules, ...$this->getTypeValidationRules($name, $column)];

            if (isset($this->foreignKeys[$name])) {
                $columnRules[] = "exists:{$this->foreignKeys[$name]},id";
            }

            $rules[$name] = $columnRules;
        }

        return $rules;
    }

    protected function buildUpdateValidationRules(): array
    {
        $rules = [];

        foreach ($this->columns as $name => $column) {
            $columnRules = ['sometimes'];

            if ($column['nullable'] || $column['default'] !== null) {
                $columnRules[] = 'nullable';
            }

            $columnRules = [...$columnRules, ...$this->getTypeValidationRules($name, $column)];

            if (isset($this->foreignKeys[$name])) {
                $columnRules[] = "exists:{$this->foreignKeys[$name]},id";
            }

            $rules[$name] = $columnRules;
        }

        return $rules;
    }

    protected function getTypeValidationRules(string $name, array $column): array
    {
        $type = strtolower($column['type']);
        $rules = [];

        if ($name === 'email' || Str::contains($name, 'email')) {
            return ['string', 'email', 'max:255'];
        }

        if (Str::contains($name, 'url')) {
            return ['string', 'url', 'max:255'];
        }

        if (in_array($type, ['varchar', 'nvarchar', 'char', 'nchar', 'text', 'ntext', 'string', 'enum'])) {
            $rules[] = 'string';
            if (!in_array($type, ['text', 'ntext'])) {
                $rules[] = 'max:255';
            }
        } elseif (in_array($type, ['int', 'integer', 'bigint', 'smallint', 'tinyint', 'int2', 'int4', 'int8'])) {
            $rules[] = 'integer';
        } elseif (in_array($type, ['decimal', 'float', 'double', 'numeric', 'float4', 'float8', 'money', 'smallmoney', 'real'])) {
            $rules[] = 'numeric';
        } elseif (in_array($type, ['boolean', 'bool', 'bit'])) {
            $rules[] = 'boolean';
        } elseif (in_array($type, ['date'])) {
            $rules[] = 'date';
        } elseif (in_array($type, ['datetime', 'datetime2', 'datetimeoffset', 'smalldatetime', 'timestamp', 'timestamptz'])) {
            $rules[] = 'date';
        } elseif (in_array($type, ['uniqueidentifier'])) {
            $rules[] = 'string';
            $rules[] = 'uuid';
        } else {
            $rules[] = 'string';
            $rules[] = 'max:255';
        }

        return $rules;
    }

    protected function formatRulesArray(array $rules): string
    {
        $lines = [];

        foreach ($rules as $field => $fieldRules) {
            $rulesStr = implode("', '", $fieldRules);
            $lines[] = "            '{$field}' => ['{$rulesStr}'],";
        }

        return implode("\n", $lines);
    }

    protected function buildResourceFields(): array
    {
        $fields = [];

        foreach ($this->columns as $name => $column) {
            $fields[] = "            '{$name}' => \$this->{$name},";
        }

        return $fields;
    }

    protected function buildDTOProperties(): array
    {
        $properties = [];
        $excludeFields = ['id', 'created_at', 'updated_at'];

        foreach ($this->columns as $name => $column) {
            if (in_array($name, $excludeFields)) {
                continue;
            }

            $phpType = $this->mapToPhpType($column['type']);
            $nullable = $column['nullable'] || $column['default'] !== null;

            $properties[] = $nullable
                ? "        public ?{$phpType} \${$name} = null,"
                : "        public {$phpType} \${$name},";
        }

        return $properties;
    }

    protected function mapToPhpType(string $dbType): string
    {
        $type = strtolower($dbType);

        return match (true) {
            in_array($type, ['int', 'integer', 'bigint', 'smallint', 'tinyint', 'int2', 'int4', 'int8']) => 'int',
            in_array($type, ['decimal', 'float', 'double', 'numeric', 'float4', 'float8', 'money', 'smallmoney', 'real']) => 'float',
            in_array($type, ['boolean', 'bool', 'bit']) => 'bool',
            in_array($type, ['date', 'datetime', 'datetime2', 'datetimeoffset', 'smalldatetime', 'timestamp', 'timestamptz']) => 'Carbon',
            default => 'string',
        };
    }

    protected function buildQueryParameterAttributes(array $filterFields, array $sortFields, array $includeRelations): string
    {
        $attributes = [];
        $dateFields = $this->getDateFilterFields();

        foreach ($filterFields as $field) {
            if (in_array($field, $dateFields)) {
                $attributes[] = "    #[QueryParameter('filter[{$field}]', description: 'Filter by {$field}. Supports exact date or range with comma separator (e.g. 2025-01-01,2025-01-31).', type: 'string', example: '2025-01-01,2025-01-31')]";
            } else {
                $attributes[] = "    #[QueryParameter('filter[{$field}]', description: 'Filter by {$field}.', type: 'string')]";
            }
        }

        if (!empty($sortFields)) {
            $allowedValues = implode(', ', array_map(
                fn($f) => "{$f}, -{$f}",
                $sortFields
            ));
            $attributes[] = "    #[QueryParameter('sort', description: 'Sort by field. Allowed: {$allowedValues}. Prefix with - for descending.', type: 'string', example: '{$sortFields[0]}')]";
        }

        if (!empty($includeRelations)) {
            $allowedIncludes = implode(', ', $includeRelations);
            $attributes[] = "    #[QueryParameter('include', description: 'Include related resources. Allowed: {$allowedIncludes}. Comma-separated for multiple.', type: 'string', example: '{$includeRelations[0]}')]";
        }

        $useJsonPaginate = config('crud-generator.use_json_api_paginate', true);

        if ($useJsonPaginate) {
            $paginationParam = config('json-api-paginate.pagination_parameter', 'page');
            $numberParam = config('json-api-paginate.number_parameter', 'number');
            $sizeParam = config('json-api-paginate.size_parameter', 'size');
            $defaultSize = config('json-api-paginate.default_size', 30);
            $useCursorPagination = config('json-api-paginate.use_cursor_pagination', false);

            if ($useCursorPagination) {
                $cursorParam = config('json-api-paginate.cursor_parameter', 'cursor');
                $attributes[] = "    #[QueryParameter('{$paginationParam}[{$cursorParam}]', description: 'Cursor for cursor-based pagination.', type: 'string')]";
            } else {
                $attributes[] = "    #[QueryParameter('{$paginationParam}[{$numberParam}]', description: 'Page number for pagination.', type: 'int', example: 1)]";
            }

            $attributes[] = "    #[QueryParameter('{$paginationParam}[{$sizeParam}]', description: 'Number of items per page.', type: 'int', example: {$defaultSize})]";
        } else {
            $attributes[] = "    #[QueryParameter('page', description: 'Page number for pagination.', type: 'int', example: 1)]";
            $attributes[] = "    #[QueryParameter('per_page', description: 'Number of items per page.', type: 'int', example: 15)]";
        }

        return implode("\n", $attributes);
    }

    protected function getFilterableFields(): array
    {
        return array_keys($this->columns);
    }

    protected function getDateFilterFields(): array
    {
        $dateTypes = ['date', 'datetime', 'datetime2', 'datetimeoffset', 'smalldatetime', 'timestamp', 'timestamptz'];

        return array_keys(array_filter($this->columns, fn($col) => in_array($col['type'], $dateTypes)));
    }

    protected function buildFiltersArray(array $filterFields, array $dateFields): string
    {
        $filters = [];

        foreach ($filterFields as $field) {
            if (in_array($field, $dateFields)) {
                $filters[] = "AllowedFilter::callback('{$field}', function (\$query, \$value) {\n"
                    . "                    if (is_string(\$value) && str_contains(\$value, ',')) {\n"
                    . "                        [\$from, \$to] = explode(',', \$value, 2);\n"
                    . "                        \$query->whereBetween('{$field}', [trim(\$from), trim(\$to)]);\n"
                    . "                    } else {\n"
                    . "                        \$query->whereDate('{$field}', \$value);\n"
                    . "                    }\n"
                    . "                })";
            } else {
                $filters[] = "'{$field}'";
            }
        }

        return implode(",\n                ", $filters);
    }

    protected function getSortableFields(): array
    {
        return array_keys($this->columns);
    }

    protected function getIncludableRelations(): array
    {
        return array_keys($this->relationships);
    }

    protected function writeFile(string $path, string $content): void
    {
        $directory = dirname($path);

        if (!$this->files->isDirectory($directory)) {
            $this->files->makeDirectory($directory, 0755, true);
        }

        $lines = explode("\n", $content);
        $minIndent = PHP_INT_MAX;

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $indent = strlen($line) - strlen(ltrim($line));
            $minIndent = min($minIndent, $indent);
        }

        if ($minIndent > 0 && $minIndent < PHP_INT_MAX) {
            $lines = array_map(function ($line) use ($minIndent) {
                if (trim($line) === '') {
                    return '';
                }

                return substr($line, min($minIndent, strlen($line) - strlen(ltrim($line))));
            }, $lines);
        }

        $this->files->put($path, implode("\n", $lines));
    }
}
