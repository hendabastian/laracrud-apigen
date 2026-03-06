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

        $this->registerRepositoryBinding();

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
            'created_at',
            'updated_at',
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

        $content = <<<PHP
        <?php

        namespace {$repoNs}\Contracts;

        interface {$this->model}RepositoryInterface extends BaseRepositoryInterface
        {
            //
        }
        PHP;

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
        $path = base_path($this->namespacePath($repoNs) . "/{$this->model}Repository.php");

        if ($this->files->exists($path) && !$this->option('force')) {
            $this->warn("Repository already exists: {$path}");

            return;
        }

        $content = <<<PHP
        <?php

        namespace {$repoNs};

        use {$modelNs}\\{$this->model};
        use {$repoNs}\Contracts\\{$this->model}RepositoryInterface;

        class {$this->model}Repository extends BaseRepository implements {$this->model}RepositoryInterface
        {
            public function __construct({$this->model} \$model)
            {
                parent::__construct(\$model);
            }
        }
        PHP;

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

        $content = <<<PHP
        <?php

        namespace {$requestNs}\\{$this->model};

        use Illuminate\Foundation\Http\FormRequest;

        class {$this->model}CreateRequest extends FormRequest
        {
            /**
             * Determine if the user is authorized to make this request.
             */
            public function authorize(): bool
            {
                return true;
            }

            /**
             * Get the validation rules that apply to the request.
             *
             * @return array<string, array<int, string>>
             */
            public function rules(): array
            {
                return [
        {$rulesString}
                ];
            }
        }
        PHP;

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

        $content = <<<PHP
        <?php

        namespace {$requestNs}\\{$this->model};

        use Illuminate\Foundation\Http\FormRequest;

        class {$this->model}UpdateRequest extends FormRequest
        {
            /**
             * Determine if the user is authorized to make this request.
             */
            public function authorize(): bool
            {
                return true;
            }

            /**
             * Get the validation rules that apply to the request.
             *
             * @return array<string, array<int, string>>
             */
            public function rules(): array
            {
                return [
        {$rulesString}
                ];
            }
        }
        PHP;

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

        $content = <<<PHP
        <?php

        namespace {$resourceNs};

        use Illuminate\Http\Request;
        use Illuminate\Http\Resources\Json\JsonResource;

        /**
         * @mixin \\{$modelNs}\\{$this->model}
         */
        class {$this->model}Resource extends JsonResource
        {
            /**
             * Transform the resource into an array.
             *
             * @return array<string, mixed>
             */
            public function toArray(Request \$request): array
            {
                return [
                    'id' => \$this->id,
        {$fieldsString}
                    'created_at' => \$this->created_at,
                    'updated_at' => \$this->updated_at,
                ];
            }
        }
        PHP;

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
        $sortFields = $this->getSortableFields();
        $includeRelations = $this->getIncludableRelations();

        $filtersString = implode(', ', array_map(fn($f) => "'{$f}'", $filterFields));
        $sortsString = implode(', ', array_map(fn($f) => "'{$f}'", $sortFields));
        $includesString = implode(', ', array_map(fn($f) => "'{$f}'", $includeRelations));

        $skipRepo = $this->option('skip-repository');

        $repositoryImport = $skipRepo ? '' : "use {$repoNs}\\Contracts\\{$this->model}RepositoryInterface;";

        $constructorParam = $skipRepo ? '' : <<<PHP

                public function __construct(
                    private {$this->model}RepositoryInterface \$repository
                ) {}
            PHP;

        $paginateMethod = $useJsonPaginate ? 'jsonPaginate' : 'paginate';

        if ($useQueryBuilder) {
            $queryBuilderImport = 'use Spatie\\QueryBuilder\\QueryBuilder;';
            $indexQuery = $skipRepo
                ? "QueryBuilder::for({$this->model}::query())"
                : 'QueryBuilder::for($this->repository->getModel()->query())';
            $indexBody = "        \${$this->modelPlural} = {$indexQuery}\n"
                . "            ->allowedFilters([{$filtersString}])\n"
                . "            ->allowedSorts([{$sortsString}])\n"
                . "            ->allowedIncludes([{$includesString}])\n"
                . "            ->{$paginateMethod}();";
        } else {
            $queryBuilderImport = '';
            $indexBody = $skipRepo
                ? "        \${$this->modelPlural} = {$this->model}::query()->{$paginateMethod}();"
                : "        \${$this->modelPlural} = \$this->repository->getModel()->query()->{$paginateMethod}();";
        }

        $storeBody = $skipRepo
            ? "\$record = {$this->model}::create(\$request->validated());"
            : '$record = $this->repository->create($request->validated());';

        $updateBody = $skipRepo
            ? "\${$this->modelVariable}->update(\$request->validated());\n        \$record = \${$this->modelVariable}->fresh();"
            : "\$record = \$this->repository->update(\${$this->modelVariable}->id, \$request->validated());";

        $deleteBody = $skipRepo
            ? "\${$this->modelVariable}->delete();"
            : "\$this->repository->delete(\${$this->modelVariable}->id);";

        $content = <<<PHP
        <?php

        namespace {$controllerNs};

        use App\Http\Controllers\Controller;
        use {$requestNs}\\{$this->model}\\{$this->model}CreateRequest;
        use {$requestNs}\\{$this->model}\\{$this->model}UpdateRequest;
        use {$resourceNs}\\{$this->model}Resource;
        use {$modelNs}\\{$this->model};
        {$repositoryImport}
        use Illuminate\Http\JsonResponse;
        use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
        {$queryBuilderImport}

        class {$this->model}Controller extends Controller
        {
        {$constructorParam}

            /**
             * Display a listing of the resource.
             */
            public function index(): AnonymousResourceCollection
            {
        {$indexBody}

                return {$this->model}Resource::collection(\${$this->modelPlural});
            }

            /**
             * Store a newly created resource in storage.
             */
            public function store({$this->model}CreateRequest \$request): JsonResponse
            {
                {$storeBody}

                return (new {$this->model}Resource(\$record))
                    ->response()
                    ->setStatusCode(201);
            }

            /**
             * Display the specified resource.
             */
            public function show({$this->model} \${$this->modelVariable}): {$this->model}Resource
            {
                return new {$this->model}Resource(\${$this->modelVariable});
            }

            /**
             * Update the specified resource in storage.
             */
            public function update({$this->model}UpdateRequest \$request, {$this->model} \${$this->modelVariable}): {$this->model}Resource
            {
                {$updateBody}

                return new {$this->model}Resource(\$record);
            }

            /**
             * Remove the specified resource from storage.
             */
            public function destroy({$this->model} \${$this->modelVariable}): JsonResponse
            {
                {$deleteBody}

                return response()->json(['message' => '{$this->model} deleted successfully.'], 200);
            }
        }
        PHP;

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

        if ($useSpatieData) {
            $content = <<<PHP
            <?php

            namespace {$dtoNs};

            use Carbon\Carbon;
            use Spatie\LaravelData\Data;

            class {$this->model}DTO extends Data
            {
                public function __construct(
                    public int \$id,
            {$propertiesString}
                    public ?Carbon \$created_at = null,
                    public ?Carbon \$updated_at = null,
                ) {}
            }
            PHP;
        } else {
            $content = <<<PHP
            <?php

            namespace {$dtoNs};

            class {$this->model}DTO
            {
                public function __construct(
                    public int \$id,
            {$propertiesString}
                    public ?string \$created_at = null,
                    public ?string \$updated_at = null,
                ) {}
            }
            PHP;
        }

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
            $content = <<<PHP
            <?php

            use Illuminate\Support\Facades\Route;

            {$routeLine}
            PHP;

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

    protected function registerRepositoryBinding(): void
    {
        if ($this->option('skip-repository')) {
            return;
        }

        $repoNs = config('crud-generator.repository_namespace', 'App\\Repositories');
        $providerPath = base_path(config('crud-generator.service_provider', 'app/Providers/AppServiceProvider.php'));
        $content = $this->files->get($providerPath);

        $interfaceClass = "\\{$repoNs}\\Contracts\\{$this->model}RepositoryInterface::class";
        $repositoryClass = "\\{$repoNs}\\{$this->model}Repository::class";

        if (Str::contains($content, $interfaceClass)) {
            $this->warn("Repository binding for {$this->model} already exists in AppServiceProvider.");

            return;
        }

        $bindingLine = "\$this->app->bind({$interfaceClass}, {$repositoryClass});";

        $pattern = '/(public function register\(\): void\s*\{.*?)(        \/\/)/s';

        if (preg_match($pattern, $content)) {
            $content = preg_replace(
                $pattern,
                '$1        ' . $bindingLine . "\n        //",
                $content,
                1
            );
        }

        $this->files->put($providerPath, $content);
        $this->components->info('Repository binding registered in AppServiceProvider.');
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

        if (in_array($type, ['varchar', 'char', 'text', 'string', 'enum'])) {
            $rules[] = 'string';
            if ($type !== 'text') {
                $rules[] = 'max:255';
            }
        } elseif (in_array($type, ['int', 'integer', 'bigint', 'smallint', 'tinyint', 'int2', 'int4', 'int8'])) {
            $rules[] = 'integer';
        } elseif (in_array($type, ['decimal', 'float', 'double', 'numeric', 'float4', 'float8'])) {
            $rules[] = 'numeric';
        } elseif (in_array($type, ['boolean', 'bool'])) {
            $rules[] = 'boolean';
        } elseif (in_array($type, ['date'])) {
            $rules[] = 'date';
        } elseif (in_array($type, ['datetime', 'timestamp', 'timestamptz'])) {
            $rules[] = 'date';
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

        foreach ($this->columns as $name => $column) {
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
            in_array($type, ['decimal', 'float', 'double', 'numeric', 'float4', 'float8']) => 'float',
            in_array($type, ['boolean', 'bool']) => 'bool',
            in_array($type, ['date', 'datetime', 'timestamp', 'timestamptz']) => 'string',
            default => 'string',
        };
    }

    protected function getFilterableFields(): array
    {
        return array_keys($this->columns);
    }

    protected function getSortableFields(): array
    {
        return [...array_keys($this->columns), 'created_at', 'updated_at'];
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
