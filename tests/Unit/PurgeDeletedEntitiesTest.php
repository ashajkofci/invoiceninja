<?php

namespace Tests\Unit;

use App\Jobs\Util\PurgeDeletedEntities;
use App\Models\BaseModel;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PurgeDeletedEntitiesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('purge_deleted_entities', function (Blueprint $table) {
            $table->id();
            $table->boolean('is_deleted')->default(false);
            $table->timestamp('deleted_at')->nullable();
        });
    }

    public function testItOnlyPermanentlyDeletesEntitiesErasedAtLeastThreeYearsAgo(): void
    {
        Carbon::setTestNow('2026-09-21 12:00:00');

        DB::table('purge_deleted_entities')->insert([
            ['id' => 1, 'is_deleted' => true, 'deleted_at' => '2020-01-01 00:00:00'],
            ['id' => 2, 'is_deleted' => true, 'deleted_at' => '2023-09-21 12:00:00'],
            ['id' => 3, 'is_deleted' => true, 'deleted_at' => '2023-09-21 12:00:01'],
            ['id' => 4, 'is_deleted' => false, 'deleted_at' => '2020-01-01 00:00:00'],
            ['id' => 5, 'is_deleted' => true, 'deleted_at' => null],
        ]);

        (new PurgeDeletedEntities([PurgeDeletedEntity::class]))->handle();

        $this->assertSame(
            [3, 4, 5],
            DB::table('purge_deleted_entities')->orderBy('id')->pluck('id')->all()
        );
    }
}

class PurgeDeletedEntity extends BaseModel
{
    use SoftDeletes;

    protected $table = 'purge_deleted_entities';

    public $timestamps = false;
}
