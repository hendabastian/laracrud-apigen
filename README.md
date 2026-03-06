# LaraCRUD Generator

Generate complete CRUD APIs for Laravel with a single Artisan command.

## Features

- API Controller with full CRUD operations
- Form Request validation classes (Create & Update)
- API Resource for JSON transformation
- Repository pattern (Interface + Implementation)
- DTO generation using Spatie Laravel Data
- Spatie Query Builder integration (filters, sorts, includes)
- JSON:API pagination via Spatie JSON API Paginate
- Automatic route registration
- Automatic service provider binding
- Smart column type detection (MySQL, PostgreSQL, SQLite)

## Requirements

- PHP 8.2+
- Laravel 11 or 12

### Optional (recommended)

- `spatie/laravel-query-builder` — for query filtering/sorting
- `spatie/laravel-json-api-paginate` — for JSON:API pagination
- `spatie/laravel-data` — for DTO generation

## Installation

```bash
composer require karrierpage/laracrud-generator --dev
```

Run the install command to publish config and base repository files:

```bash
php artisan crud-generator:install
```

## Usage

```bash
php artisan generate:crud-api {ModelName}
```

### Examples

```bash
# Generate CRUD for User model
php artisan generate:crud-api User

# Generate CRUD for a multi-word model
php artisan generate:crud-api JobApplication

# Overwrite existing files
php artisan generate:crud-api User --force

# Skip repository pattern
php artisan generate:crud-api User --skip-repository

# Skip DTO generation
php artisan generate:crud-api User --skip-dto

# Skip route registration
php artisan generate:crud-api User --skip-routes
```

### Generated Files

For `php artisan generate:crud-api Company`:

| Component          | Path                                                        |
|--------------------|-------------------------------------------------------------|
| Controller         | `app/Http/Controllers/Api/CompanyController.php`            |
| Create Request     | `app/Http/Requests/Api/Company/CompanyCreateRequest.php`    |
| Update Request     | `app/Http/Requests/Api/Company/CompanyUpdateRequest.php`    |
| Resource           | `app/Http/Resources/Api/CompanyResource.php`                |
| Repository Interface | `app/Repositories/Contracts/CompanyRepositoryInterface.php` |
| Repository         | `app/Repositories/CompanyRepository.php`                    |
| DTO                | `app/DTO/CompanyDTO.php`                                    |
| Routes             | `routes/api.php` (appended)                                 |

### Generated API Endpoints

```
GET     /api/companies          — List (with filters, sorts, pagination)
POST    /api/companies          — Create
GET     /api/companies/{id}     — Show
PUT     /api/companies/{id}     — Update
DELETE  /api/companies/{id}     — Delete
```

## Configuration

Publish the config file:

```bash
php artisan vendor:publish --tag=crud-generator-config
```

Available options in `config/crud-generator.php`:

```php
return [
    'model_namespace'       => 'App\\Models',
    'controller_namespace'  => 'App\\Http\\Controllers\\Api',
    'request_namespace'     => 'App\\Http\\Requests\\Api',
    'resource_namespace'    => 'App\\Http\\Resources\\Api',
    'repository_namespace'  => 'App\\Repositories',
    'dto_namespace'         => 'App\\DTO',
    'route_file'            => 'routes/api.php',
    'service_provider'      => 'app/Providers/AppServiceProvider.php',
    'excluded_columns'      => ['id', 'created_at', 'updated_at', ...],
    'use_query_builder'     => true,
    'use_json_api_paginate' => true,
    'use_spatie_data'       => true,
];
```

## Customizing Business Logic

The generated code is yours to modify. The repository pattern makes it easy to add custom logic without touching the controller.

### Adding custom methods to a repository

First, define the method in the interface:

```php
// app/Repositories/Contracts/OrderRepositoryInterface.php
<?php

namespace App\Repositories\Contracts;

use Illuminate\Database\Eloquent\Collection;

interface OrderRepositoryInterface extends BaseRepositoryInterface
{
    public function findByStatus(string $status): Collection;

    public function cancelExpiredOrders(): int;
}
```

Then implement it in the repository:

```php
// app/Repositories/OrderRepository.php
<?php

namespace App\Repositories;

use App\Models\Order;
use App\Repositories\Contracts\OrderRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class OrderRepository extends BaseRepository implements OrderRepositoryInterface
{
    public function __construct(Order $model)
    {
        parent::__construct($model);
    }

    public function findByStatus(string $status): Collection
    {
        return $this->model->where('status', $status)->get();
    }

    public function cancelExpiredOrders(): int
    {
        return $this->model
            ->where('status', 'pending')
            ->where('expires_at', '<', now())
            ->update(['status' => 'cancelled']);
    }
}
```

### Overriding CRUD behavior in a repository

Override any base method to add custom logic:

```php
// app/Repositories/UserRepository.php
<?php

namespace App\Repositories;

use App\Models\User;
use App\Repositories\Contracts\UserRepositoryInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;

class UserRepository extends BaseRepository implements UserRepositoryInterface
{
    public function __construct(User $model)
    {
        parent::__construct($model);
    }

    public function create(array $data): Model
    {
        if (isset($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        }

        return parent::create($data);
    }

    public function update(int $id, array $data): Model
    {
        if (isset($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        }

        return parent::update($id, $data);
    }
}
```

### Adding custom controller actions

Add new methods to the generated controller and register the routes manually:

```php
// app/Http/Controllers/Api/OrderController.php

// Add this method to the generated controller:
public function cancel(Order $order): JsonResponse
{
    if ($order->status === 'shipped') {
        return response()->json(['message' => 'Cannot cancel a shipped order.'], 422);
    }

    $this->repository->update($order->id, ['status' => 'cancelled']);

    return response()->json(['message' => 'Order cancelled successfully.']);
}
```

```php
// routes/api.php
Route::patch('orders/{order}/cancel', [\App\Http\Controllers\Api\OrderController::class, 'cancel']);
```

### Customizing validation rules

Edit the generated request classes to add conditional or complex rules:

```php
// app/Http/Requests/Api/Order/OrderCreateRequest.php
public function rules(): array
{
    return [
        'customer_id' => ['required', 'integer', 'exists:customers,id'],
        'total'       => ['required', 'numeric', 'min:0.01'],
        'status'      => ['required', 'string', 'in:pending,confirmed,shipped'],
        'notes'       => ['nullable', 'string', 'max:1000'],
        'items'       => ['required', 'array', 'min:1'],
        'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
        'items.*.quantity'   => ['required', 'integer', 'min:1'],
    ];
}
```

### Customizing the API Resource

Add computed fields, conditional relationships, or hide fields:

```php
// app/Http/Resources/Api/OrderResource.php
public function toArray(Request $request): array
{
    return [
        'id'         => $this->id,
        'customer_id'=> $this->customer_id,
        'total'      => number_format($this->total, 2),
        'status'     => $this->status,
        'is_editable'=> in_array($this->status, ['pending', 'confirmed']),
        'customer'   => new CustomerResource($this->whenLoaded('customer')),
        'items'      => OrderItemResource::collection($this->whenLoaded('items')),
        'created_at' => $this->created_at,
        'updated_at' => $this->updated_at,
    ];
}
```

## Prerequisites

Make sure your `AppServiceProvider` has a `//` comment marker inside the `register()` method where bindings will be inserted:

```php
public function register(): void
{
    // existing bindings...
    //
}
```

Make sure your `bootstrap/app.php` includes the API routes:

```php
->withRouting(
    web: __DIR__.'/../routes/web.php',
    api: __DIR__.'/../routes/api.php',
    commands: __DIR__.'/../routes/console.php',
)
```

## License

MIT
