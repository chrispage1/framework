<?php

namespace Illuminate\Tests\Bus;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Batch;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\BatchFactory;
use Illuminate\Bus\DatabaseBatchRepository;
use Illuminate\Bus\PendingBatch;
use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\Factory;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\CallQueuedClosure;
use Mockery as m;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class BusBatchTest extends TestCase
{
    protected function setUp(): void
    {
        $db = new DB;

        $db->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);

        $db->bootEloquent();
        $db->setAsGlobal();

        $this->createSchema();

        $_SERVER['__finally.count'] = 0;
        $_SERVER['__then.count'] = 0;
        $_SERVER['__catch.count'] = 0;
    }

    /**
     * Setup the database schema.
     *
     * @return void
     */
    public function createSchema()
    {
        $this->schema()->create('job_batches', function ($table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->integer('total_jobs');
            $table->integer('pending_jobs');
            $table->integer('failed_jobs');
            $table->text('failed_job_ids');
            $table->text('options')->nullable();
            $table->integer('cancelled_at')->nullable();
            $table->integer('created_at');
            $table->integer('finished_at')->nullable();
        });
    }

    /**
     * Tear down the database schema.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        unset($_SERVER['__finally.batch']);
        unset($_SERVER['__then.batch']);
        unset($_SERVER['__catch.batch']);
        unset($_SERVER['__catch.exception']);

        $this->schema()->drop('job_batches');

        m::close();
    }

    public function test_jobs_can_be_added_to_the_batch()
    {
        $queue = m::mock(Factory::class);

        $batch = $this->createTestBatch($queue);

        $job = new class {
            use Batchable;
        };

        $secondJob = new class {
            use Batchable;
        };

        $thirdJob = function () {
        };

        $queue->shouldReceive('connection')->once()
                        ->with('test-connection')
                        ->andReturn($connection = m::mock(stdClass::class));

        $connection->shouldReceive('bulk')->once()->with(\Mockery::on(function ($args) use ($job, $secondJob) {
            return
                $args[0] == $job &&
                $args[1] == $secondJob &&
                $args[2] instanceof CallQueuedClosure
                && is_string($args[2]->batchId);
        }), '', 'test-queue');

        $batch = $batch->add([$job, $secondJob, $thirdJob]);

        $this->assertEquals(3, $batch->totalJobs);
        $this->assertEquals(3, $batch->pendingJobs);
        $this->assertTrue(is_string($job->batchId));
        $this->assertInstanceOf(CarbonImmutable::class, $batch->createdAt);
    }

    public function test_processed_jobs_can_be_calculated()
    {
        $queue = m::mock(Factory::class);

        $batch = $this->createTestBatch($queue);

        $batch->totalJobs = 10;
        $batch->pendingJobs = 4;

        $this->assertEquals(6, $batch->processedJobs());
        $this->assertEquals(60, $batch->progress());
    }

    public function test_successful_jobs_can_be_recorded()
    {
        $queue = m::mock(Factory::class);

        $batch = $this->createTestBatch($queue);

        $job = new class {
            use Batchable;
        };

        $secondJob = new class {
            use Batchable;
        };

        $queue->shouldReceive('connection')->once()
                        ->with('test-connection')
                        ->andReturn($connection = m::mock(stdClass::class));

        $connection->shouldReceive('bulk')->once();

        $batch = $batch->add([$job, $secondJob]);
        $this->assertEquals(2, $batch->pendingJobs);

        $batch->recordSuccessfulJob('test-id');
        $batch->recordSuccessfulJob('test-id');

        $this->assertInstanceOf(Batch::class, $_SERVER['__finally.batch']);
        $this->assertInstanceOf(Batch::class, $_SERVER['__then.batch']);

        $batch = $batch->fresh();
        $this->assertEquals(0, $batch->pendingJobs);
        $this->assertTrue($batch->finished());
        $this->assertEquals(1, $_SERVER['__finally.count']);
        $this->assertEquals(1, $_SERVER['__then.count']);
    }

    public function test_failed_jobs_can_be_recorded_while_not_allowing_failures()
    {
        $queue = m::mock(Factory::class);

        $batch = $this->createTestBatch($queue, $allowFailures = false);

        $job = new class {
            use Batchable;
        };

        $secondJob = new class {
            use Batchable;
        };

        $queue->shouldReceive('connection')->once()
                        ->with('test-connection')
                        ->andReturn($connection = m::mock(stdClass::class));

        $connection->shouldReceive('bulk')->once();

        $batch = $batch->add([$job, $secondJob]);
        $this->assertEquals(2, $batch->pendingJobs);

        $batch->recordFailedJob('test-id', new RuntimeException('Something went wrong.'));
        $batch->recordFailedJob('test-id', new RuntimeException('Something else went wrong.'));

        $this->assertInstanceOf(Batch::class, $_SERVER['__finally.batch']);
        $this->assertFalse(isset($_SERVER['__then.batch']));

        $batch = $batch->fresh();
        $this->assertEquals(2, $batch->pendingJobs);
        $this->assertEquals(2, $batch->failedJobs);
        $this->assertTrue($batch->finished());
        $this->assertTrue($batch->cancelled());
        $this->assertEquals(1, $_SERVER['__finally.count']);
        $this->assertEquals(1, $_SERVER['__catch.count']);
        $this->assertSame('Something went wrong.', $_SERVER['__catch.exception']->getMessage());
    }

    public function test_failed_jobs_can_be_recorded_while_allowing_failures()
    {
        $queue = m::mock(Factory::class);

        $batch = $this->createTestBatch($queue, $allowFailures = true);

        $job = new class {
            use Batchable;
        };

        $secondJob = new class {
            use Batchable;
        };

        $queue->shouldReceive('connection')->once()
                        ->with('test-connection')
                        ->andReturn($connection = m::mock(stdClass::class));

        $connection->shouldReceive('bulk')->once();

        $batch = $batch->add([$job, $secondJob]);
        $this->assertEquals(2, $batch->pendingJobs);

        $batch->recordFailedJob('test-id', new RuntimeException('Something went wrong.'));
        $batch->recordFailedJob('test-id', new RuntimeException('Something else went wrong.'));

        // While allowing failures this batch never actually completes...
        $this->assertFalse(isset($_SERVER['__then.batch']));

        $batch = $batch->fresh();
        $this->assertEquals(2, $batch->pendingJobs);
        $this->assertEquals(2, $batch->failedJobs);
        $this->assertFalse($batch->finished());
        $this->assertFalse($batch->cancelled());
        $this->assertEquals(1, $_SERVER['__catch.count']);
        $this->assertSame('Something went wrong.', $_SERVER['__catch.exception']->getMessage());
    }

    public function test_batch_can_be_cancelled()
    {
        $queue = m::mock(Factory::class);

        $batch = $this->createTestBatch($queue);

        $batch->cancel();

        $batch = $batch->fresh();

        $this->assertTrue($batch->cancelled());
    }

    public function test_batch_can_be_deleted()
    {
        $queue = m::mock(Factory::class);

        $batch = $this->createTestBatch($queue);

        $batch->delete();

        $batch = $batch->fresh();

        $this->assertNull($batch);
    }

    public function test_batch_state_can_be_inspected()
    {
        $queue = m::mock(Factory::class);

        $batch = $this->createTestBatch($queue);

        $this->assertFalse($batch->finished());
        $batch->finishedAt = now();
        $this->assertTrue($batch->finished());

        $batch->options['then'] = [];
        $this->assertFalse($batch->hasThenCallbacks());
        $batch->options['then'] = [1];
        $this->assertTrue($batch->hasThenCallbacks());

        $this->assertFalse($batch->allowsFailures());
        $batch->options['allowFailures'] = true;
        $this->assertTrue($batch->allowsFailures());

        $this->assertFalse($batch->hasFailures());
        $batch->failedJobs = 1;
        $this->assertTrue($batch->hasFailures());

        $batch->options['catch'] = [];
        $this->assertFalse($batch->hasCatchCallbacks());
        $batch->options['catch'] = [1];
        $this->assertTrue($batch->hasCatchCallbacks());

        $this->assertFalse($batch->cancelled());
        $batch->cancelledAt = now();
        $this->assertTrue($batch->cancelled());

        $this->assertTrue(is_string(json_encode($batch)));
    }

    protected function createTestBatch($queue, $allowFailures = false)
    {
        $repository = new DatabaseBatchRepository(new BatchFactory($queue), DB::connection(), 'job_batches');

        $pendingBatch = (new PendingBatch(new Container, collect()))
                            ->then(function (Batch $batch) {
                                $_SERVER['__then.batch'] = $batch;
                                $_SERVER['__then.count']++;
                            })
                            ->catch(function (Batch $batch, $e) {
                                $_SERVER['__catch.batch'] = $batch;
                                $_SERVER['__catch.exception'] = $e;
                                $_SERVER['__catch.count']++;
                            })
                            ->finally(function (Batch $batch) {
                                $_SERVER['__finally.batch'] = $batch;
                                $_SERVER['__finally.count']++;
                            })
                            ->allowFailures($allowFailures)
                            ->onConnection('test-connection')
                            ->onQueue('test-queue');

        return $repository->store($pendingBatch);
    }

    /**
     * Get a database connection instance.
     *
     * @return \Illuminate\Database\Connection
     */
    protected function connection()
    {
        return Model::getConnectionResolver()->connection();
    }

    /**
     * Get a schema builder instance.
     *
     * @return \Illuminate\Database\Schema\Builder
     */
    protected function schema()
    {
        return $this->connection()->getSchemaBuilder();
    }
}
