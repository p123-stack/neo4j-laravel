<?php

namespace Neo4j\Neo4jLaravel\Tests\Integration;

 use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Neo4j\Neo4jLaravel\Neo4jModel;
use Neo4j\Neo4jLaravel\Tests\TestCase;

final class Neo4jEloquentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make('db')->connection('neo4j')
            ->statement('MATCH (n:User) DETACH DELETE n');
        $this->app->make('db')->connection('neo4j')
            ->statement('MATCH (n:SoftUser) DETACH DELETE n');
    }

    public function testEloquentModelSupportsBasicCrud(): void
    {
        $created = User::create(['name' => 'Pratiksha']);

        self::assertNotNull($created->id);
        self::assertSame('Pratiksha', $created->name);

        $found = User::where('name', 'Pratiksha')->first();

        self::assertInstanceOf(User::class, $found);
        self::assertSame($created->id, $found->id);
        self::assertSame($created->id, User::find($created->id)?->id);

        self::assertNotNull($created->created_at);
        self::assertNotNull($created->updated_at);

        $found->update(['name' => 'Pratiksha Zalte']);

        $updated = User::where('id', $created->id)->firstOrFail();

        self::assertSame('Pratiksha Zalte', $updated->name);
        self::assertNotNull($updated->updated_at);

        self::assertTrue($found->delete());
        self::assertNull(User::where('id', $created->id)->first());
    }

    public function testEloquentSupportsAggregatesExistsIncrementAndDateFilters(): void
    {
        User::create(['name' => 'Ada', 'score' => 10, 'status' => 'active']);
        User::create(['name' => 'Alan', 'score' => 20, 'status' => 'active']);
        User::create(['name' => 'Grace', 'score' => 5, 'status' => 'inactive']);

        self::assertSame(3, User::count());
        self::assertTrue(User::where('name', 'Ada')->exists());
        self::assertFalse(User::where('name', 'Missing')->exists());
        self::assertSame(35, (int) User::sum('score'));
        self::assertSame(20, (int) User::max('score'));
        self::assertSame(5, (int) User::min('score'));

        $ada = User::where('name', 'Ada')->firstOrFail();
        User::where('id', $ada->id)->increment('score', 3);
        self::assertSame(13, (int) User::where('id', $ada->id)->value('score'));

        self::assertSame(
            2,
            User::whereColumn('status', 'status')->where('status', 'active')->count()
        );

        self::assertGreaterThanOrEqual(
            1,
            User::whereYear('created_at', now()->year)->count()
        );

        $grouped = User::query()
            ->select('status')
            ->groupBy('status')
            ->orderBy('status')
            ->get();

        self::assertCount(2, $grouped);
        self::assertSame(['active', 'inactive'], $grouped->pluck('status')->all());
    }

    public function testEloquentHydratesNeo4jElementId(): void
    {
        $created = User::create(['name' => 'Element']);

        $found = User::where('id', $created->id)->firstOrFail();

        self::assertNotNull($found->elementId());
        self::assertIsString($found->elementId());
        self::assertNotSame($found->id, $found->elementId());

        // elementId is metadata and must not be written back as a node property.
        $found->name = 'Element Updated';
        $found->save();

        $reloaded = User::where('id', $created->id)->firstOrFail();
        self::assertSame('Element Updated', $reloaded->name);
        self::assertNotNull($reloaded->elementId());
    }

    public function testEloquentSoftDeletesRestoreAndForceDelete(): void
    {
        $user = SoftUser::create(['name' => 'Soft']);

        self::assertTrue($user->delete());
        self::assertNull(SoftUser::where('id', $user->id)->first());
        self::assertTrue($user->trashed());

        $trashed = SoftUser::withTrashed()->where('id', $user->id)->firstOrFail();
        self::assertNotNull($trashed->deleted_at);
        self::assertTrue($trashed->trashed());

        self::assertTrue($trashed->restore());
        self::assertFalse($trashed->fresh()->trashed());
        self::assertNotNull(SoftUser::where('id', $user->id)->first());

        $trashed->delete();
        self::assertTrue($trashed->forceDelete());
        self::assertNull(SoftUser::withTrashed()->where('id', $user->id)->first());
    }

    public function testEloquentPaginateSimplePaginateAndCursorPaginate(): void
    {
        foreach (['Ada', 'Alan', 'Grace', 'Grace2', 'Linus'] as $name) {
            User::create(['name' => $name]);
        }

        /** @var LengthAwarePaginator $page */
        $page = User::orderBy('name')->paginate(2, ['*'], 'page', 1);

        self::assertInstanceOf(LengthAwarePaginator::class, $page);
        self::assertSame(5, $page->total());
        self::assertCount(2, $page->items());
        self::assertSame(['Ada', 'Alan'], collect($page->items())->pluck('name')->all());

        /** @var Paginator $simple */
        $simple = User::orderBy('name')->simplePaginate(2, ['*'], 'page', 1);

        self::assertInstanceOf(Paginator::class, $simple);
        self::assertCount(2, $simple->items());
        self::assertTrue($simple->hasMorePages());

        /** @var CursorPaginator $cursor */
        $cursor = User::orderBy('name')->orderBy('id')->cursorPaginate(2);

        self::assertInstanceOf(CursorPaginator::class, $cursor);
        self::assertCount(2, $cursor->items());
        self::assertTrue($cursor->hasMorePages());

        $next = User::orderBy('name')->orderBy('id')->cursorPaginate(2, ['*'], 'cursor', $cursor->nextCursor());
        self::assertCount(2, $next->items());
        self::assertNotSame(
            collect($cursor->items())->pluck('id')->all(),
            collect($next->items())->pluck('id')->all()
        );
    }



}

final class User extends Neo4jModel
{
    protected $guarded = [];
}


final class SoftUser extends Neo4jModel
{
    use SoftDeletes;

    protected $table = 'SoftUser';

    protected $guarded = [];
}
