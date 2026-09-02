# Neo4j Laravel

A Laravel package that provides integration with Neo4j graph database.

> [!WARNING]
> Database Configuration Recommendations:
>
> Laravel uses the default database connection for authentication, sessions, and other core features. While you can use Neo4j as your default database, we recommend:
>
> 1. Use a traditional database (SQLite, MySQL, PostgreSQL, etc.) as your default database connection
> 2. Use Neo4j as a secondary connection for your graph data
>
> If you choose to use Neo4j as your default database:
>
> - Set `SESSION_DRIVER=file` in your .env file
> - Store authentication data in Neo4j only if your application model uses the Neo4j Eloquent concern described below
> - Other features that depend on the default database connection may be affected
>
> These limitations will be addressed in future releases.

## Installation

```bash
composer require neo4j-php/neo4j-laravel
```

## Configuration

### Environment Variables

Add the following to your `.env` file:

```env
DB_CONNECTION=neo4j
NEO4J_URL=bolt://localhost:7687
NEO4J_USERNAME=neo4j
NEO4J_PASSWORD=your_password
NEO4J_DATABASE=neo4j
```

#### Query log flushing (`NEO4J_QUERY_CHANNEL`)

Each Neo4j connection keeps an in-memory query log (the same entries `getQueryLog()` returns; queries are still recorded by the existing `logQuery()` path with timing—nothing is double-logged at execution time). After each HTTP request finishes (or when a console command ends), that buffer is flushed once to Laravel’s logger as `debug` lines, then cleared.

| Variable | Behavior |
|----------|----------|
| `NEO4J_QUERY_CHANNEL` **unset or empty** | Each log line is written with `Log::debug(...)` (no `::channel()`), so messages use your app’s default log stack from `config('logging.default')` (usually `stack` → `single` / `laravel.log`). |
| `NEO4J_QUERY_CHANNEL=neo4j_queries` (non-empty) | If `config('logging.channels.neo4j_queries')` exists, lines are written with `Log::channel('neo4j_queries')->debug(...)`. Define that channel in `config/logging.php` (e.g. a `daily` file dedicated to Neo4j). If the name is set but **not** defined under `logging.channels`, the package falls back to the same behavior as an empty value (default logger). |

To tune or disable logging per environment, configure the channel itself in `config/logging.php` (e.g. `level`, a `null` driver, or omitting the channel from your production `LOG_STACK`).

Example: dedicated daily channel in `config/logging.php`:

```php
'neo4j_queries' => [
    'driver' => 'daily',
    'path' => storage_path('logs/neo4j-queries.log'),
    'level' => env('LOG_LEVEL', 'debug'),
    'days' => 14,
],
```

Then in `.env`:

```env
NEO4J_QUERY_CHANNEL=neo4j_queries
```

### Laravel Debugbar (optional)

Install [barryvdh/laravel-debugbar](https://github.com/barryvdh/laravel-debugbar) or [fruitcake/laravel-debugbar](https://github.com/fruitcake/laravel-debugbar) (v4+) as a **dev** dependency. No extra Neo4j config is required.

When Debugbar’s database (`queries`) collector is enabled, Cypher run through Laravel’s connection API appears in the shared **Queries** tab (via Laravel’s `QueryExecuted` event), alongside SQL from other connections. The connection name identifies Neo4j rows.

Failed Cypher is still listed there: the statement keeps the original query and appends a `/* Neo4j error: … */` comment with the exception message. The in-memory query log (`getQueryLog()` / `NEO4J_QUERY_CHANNEL` flush) keeps the clean Cypher plus separate `status` / `error_message` fields.

Use the connection API **or** the container-resolved Neo4j client / driver / session (they are wrapped so Cypher is captured the same way):

```php
DB::connection('neo4j')->select('MATCH (n) RETURN n LIMIT $limit', ['limit' => 10]);

// Also captured — SessionInterface / ClientInterface / DriverInterface from the container
$session->run('MATCH (n) RETURN n LIMIT $limit', ['limit' => 10]);
```

Calling methods on a raw client obtained outside this package (for example constructing `Client` yourself without going through the Laravel container) still bypasses capture.

In SPA / API apps, Debugbar often stores AJAX datasets under `storage/debugbar`. Ensure that directory is writable by PHP, then select the API request (for example `GET /api/...`) in Debugbar—not only the HTML shell that served the page.

### Database Configuration

Add the Neo4j connection configuration to your `config/database.php`:

```php
'neo4j' => [
    'driver' => 'neo4j',
    'url' => env('NEO4J_URL', 'bolt://localhost:7687'),
    'username' => env('NEO4J_USERNAME', 'neo4j'),
    'password' => env('NEO4J_PASSWORD', ''),
    'database' => env('NEO4J_DATABASE', 'neo4j'),
    'auth_scheme' => env('NEO4J_AUTH_SCHEME', 'basic'),
    'ssl' => [
        'mode' => env('NEO4J_SSL_MODE', 'from_url'),
        'verify_peer' => env('NEO4J_SSL_VERIFY_PEER', true),
    ],
    'connection' => [
        'timeout' => env('NEO4J_CONNECTION_TIMEOUT', 30),
        'max_pool_size' => env('NEO4J_MAX_POOL_SIZE', 100),
    ],
    'transaction' => [
        'timeout' => env('NEO4J_TRANSACTION_TIMEOUT', 30),
    ],
],
```

## Usage

### Using Laravel's DB Facade

```php
use Illuminate\Support\Facades\DB;

// Basic query
$result = DB::connection('neo4j')->select('MATCH (n) RETURN n');

// With parameters
$result = DB::connection('neo4j')->select(<<<'CYPHER'
    MATCH (m:Movie {title: $title})
    RETURN m
CYPHER, ['title' => 'The Matrix']);

// Transactions
DB::connection('neo4j')->beginTransaction();
try {
    // Your queries here
    DB::connection('neo4j')->commit();
} catch (\Exception $e) {
    DB::connection('neo4j')->rollBack();
    throw $e;
}
```

### Using Eloquent

Add `HasNeo4jConnection` to a standard Eloquent model. The model class name is
used as its default Neo4j label, so `User` maps to `:User`. New models receive a
UUID `id` automatically.

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Neo4j\Neo4jLaravel\Concerns\HasNeo4jConnection;

class User extends Model
{
    use HasNeo4jConnection;

    protected $fillable = ['name', 'email'];
}
```

The regular Eloquent read and CRUD APIs then use the Neo4j connection, Query
Builder, and Cypher grammar:

```php
$user = User::where('name', 'Pratiksha')->first();

$user = User::create([
    'name' => 'Pratiksha',
    'email' => 'pratiksha@example.com',
]);

$user->update(['name' => 'Pratiksha Zalte']);
$user->delete();
```

When a full node is returned, Neo4j's native element id is hydrated onto the
model (separate from the UUID primary key):

```php
$user = User::where('name', 'Pratiksha')->first();
$user->id;          // UUID property used as Eloquent key
$user->elementId(); // Neo4j element id, e.g. "4:xxx:123"
```

Laravel's `SoftDeletes` trait works on Neo4j models (`deleted_at` property,
`withTrashed` / `restore` / `forceDelete`). Hard deletes use `DETACH DELETE`.

Pagination APIs (`paginate`, `simplePaginate`, `cursorPaginate`) work via
Cypher `SKIP` / `LIMIT` and aggregates for totals.

For a dedicated graph model, the package also provides
`Neo4j\Neo4jLaravel\Neo4jModel`, which already includes the concern.
Graph relationship APIs are not part of the initial Eloquent integration.

### Using Neo4j Client Interface

`ClientInterface`, `DriverInterface`, and `SessionInterface` resolved from the container are capturing wrappers, so `$session->run(...)` appears in Debugbar like `DB::connection('neo4j')`:

```php
use Laudis\Neo4j\Contracts\SessionInterface;

class YourController extends Controller
{
    public function index(SessionInterface $session)
    {
        $result = $session->run(<<<'CYPHER'
            MATCH (n)
            RETURN n
        CYPHER);
        return response()->json($result->toArray());
    }
}
```

### Example: Movie Management

```php
// Create a movie
$result = DB::connection('neo4j')->statement(<<<'CYPHER'
    CREATE (m:Movie {
        title: $title,
        released: $released,
        tagline: $tagline,
        created_at: datetime()
    })
    RETURN m
CYPHER, [
    'title' => 'The Matrix',
    'released' => 1999,
    'tagline' => 'Welcome to the Real World'
]);

// Add an actor to a movie
$result = DB::connection('neo4j')->select(<<<'CYPHER'
    MATCH (m:Movie {title: $movieTitle})
    MERGE (a:Person {name: $actorName})
    MERGE (a)-[r:ACTED_IN]->(m)
    SET r.roles = $roles
    RETURN m, a, r
CYPHER, [
    'movieTitle' => 'The Matrix',
    'actorName' => 'Keanu Reeves',
    'roles' => ['Neo']
]);

// Find similar movies
$result = DB::connection('neo4j')->select(<<<'CYPHER'
    MATCH (m:Movie {title: $title})<-[:ACTED_IN]-(a:Person)-[:ACTED_IN]->(other:Movie)
    WHERE m <> other
    WITH other, count(distinct a) as commonActors
    RETURN other, commonActors
    ORDER BY commonActors DESC
    LIMIT 5
CYPHER, ['title' => 'The Matrix']);
```

### Example: User Management

```php
// Create a user
$result = $session->run(<<<'CYPHER'
    CREATE (u:User {
        name: $name,
        email: $email,
        created_at: datetime()
    })
    RETURN u
CYPHER, [
    'name' => 'John Doe',
    'email' => 'john@example.com'
]);

// Update a user
$result = $session->run(<<<'CYPHER'
    MATCH (u:User {email: $email})
    SET u.name = $name, u.updated_at = datetime()
    RETURN u
CYPHER, [
    'email' => 'john@example.com',
    'name' => 'John Smith'
]);
```

## Features

- Seamless integration with Laravel's database layer
- Support for both DB Facade and Neo4j Client Interface
- Transaction support
- Parameterized queries
- Optional Laravel Debugbar support (Cypher in the shared Queries tab)
- Query log flushing via `NEO4J_QUERY_CHANNEL`
- SSL configuration options
- Connection pooling
- Timeout settings for connections and transactions

## License

This package is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
