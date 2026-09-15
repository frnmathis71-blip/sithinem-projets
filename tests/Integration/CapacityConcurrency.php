<?php

// Standalone real-PostgreSQL integration test. Each run owns a temporary schema;
// application tables and the developer's configured database are never touched.
use App\Models\Order;
use App\Models\Product;
use App\Models\TimeSlot;
use App\Models\User;
use App\Services\OrderService;
use App\Services\RestaurantLock;
use App\Services\SlotService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$schema = $argv[2] ?? 'restaurant_test_'.bin2hex(random_bytes(8));
if (! preg_match('/^restaurant_test_[a-f0-9]{16}$/', $schema)) {
    throw new RuntimeException('Invalid test schema.');
}
config(['app.env' => 'testing', 'database.default' => 'pgsql', 'database.connections.pgsql.url' => null, 'database.connections.pgsql.host' => getenv('PG_TEST_HOST') ?: '127.0.0.1', 'database.connections.pgsql.port' => getenv('PG_TEST_PORT') ?: '55439', 'database.connections.pgsql.database' => getenv('PG_TEST_DATABASE') ?: 'restaurant_test', 'database.connections.pgsql.username' => getenv('PG_TEST_USER') ?: 'postgres', 'database.connections.pgsql.password' => getenv('PG_TEST_PASSWORD') ?: '', 'database.connections.pgsql.search_path' => $schema, 'cache.default' => 'array', 'queue.default' => 'sync']);
DB::purge('pgsql');
CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-18 15:00:00', 'UTC'));

if (($argv[1] ?? '') === 'child') {
    $mode = $argv[3];
    $user = User::query()->where('email', 'concurrency@example.test')->firstOrFail();
    $slot = TimeSlot::query()->orderBy('id')->firstOrFail();
    $product = Product::query()->firstOrFail();
    // This read happens on the child's independent database connection.
    $pid = DB::selectOne('select pg_backend_pid() as pid')->pid;
    echo 'READY:'.$pid.PHP_EOL;
    flush();
    try {
        if ($mode === 'close') {
            app(RestaurantLock::class)->run(fn () => $slot->update(['manually_closed' => true]));
            echo 'CLOSED'.PHP_EOL;
        } else {
            app(OrderService::class)->place($user, $slot->id, '0612345678', (string) Str::uuid(), ['product:'.$product->id => 1]);
            echo 'BOOKED'.PHP_EOL;
        }
    } catch (ValidationException) {
        echo 'REJECTED'.PHP_EOL;
    }
    exit(0);
}

DB::statement('CREATE SCHEMA '.$schema);
$children = [];
try {
    Artisan::call('migrate', ['--force' => true]);
    $user = User::factory()->create(['email' => 'concurrency@example.test']);
    $product = Product::create(['name' => 'Concurrency item', 'price' => 100, 'available' => true]);
    $slots = app(SlotService::class)->forDate('2026-09-18');
    $slot = TimeSlot::findOrFail($slots[0]['id']);
    $slot->update(['capacity_override' => 1]);

    $start = function (string $mode) use ($schema, &$children): Process {
        $process = new Process([PHP_BINARY, __FILE__, 'child', $schema, $mode]);
        $process->setTimeout(25);
        $process->start();
        $children[] = $process;

        return $process;
    };
    $waitForLock = function (Process $process): void {
        $deadline = microtime(true) + 15;
        do {
            $output = $process->getOutput();
            if (preg_match('/READY:(\d+)/', $output, $matches)) {
                $activity = DB::selectOne('select wait_event_type from pg_stat_activity where pid = ?', [(int) $matches[1]]);
                if ($activity?->wait_event_type === 'Lock') {
                    return;
                }
            }
            if (! $process->isRunning()) {
                throw new RuntimeException('Child exited before taking the lock: '.$output.$process->getErrorOutput());
            }
            usleep(20000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('The independent connection did not wait for the restaurant lock.');
    };
    // The parent holds the real database lock while two independent clients try
    // to book. pg_stat_activity proves both clients are waiting concurrently.
    DB::beginTransaction();
    DB::table('restaurant_settings')->where('id', 1)->lockForUpdate()->first();
    $first = $start('book');
    $second = $start('book');
    $waitForLock($first);
    $waitForLock($second);
    DB::commit();
    $first->wait();
    $second->wait();
    $output = $first->getOutput().$second->getOutput();
    if (! $first->isSuccessful() || ! $second->isSuccessful() || substr_count($output, 'BOOKED') !== 1 || substr_count($output, 'REJECTED') !== 1 || Order::count() !== 1) {
        throw new RuntimeException('Last-place concurrency test failed: '.$output.$first->getErrorOutput().$second->getErrorOutput());
    }
    echo "PASS: two concurrent PostgreSQL clients, one last place, exactly one order.\n";

    $slot->update(['capacity_override' => 2]);
    DB::beginTransaction();
    DB::table('restaurant_settings')->where('id', 1)->lockForUpdate()->first();
    $third = $start('book');
    $waitForLock($third);
    // Lowering below existing bookings must win over the waiting client.
    $slot->update(['capacity_override' => 0]);
    DB::commit();
    $third->wait();
    if (! $third->isSuccessful() || ! str_contains($third->getOutput(), 'REJECTED') || Order::count() !== 1) {
        throw new RuntimeException('Concurrent capacity reduction failed.');
    }
    echo "PASS: concurrent capacity reduction retains the existing order and rejects the waiting booking.\n";

    $slot->update(['capacity_override' => 2]);
    DB::beginTransaction();
    DB::table('restaurant_settings')->where('id', 1)->lockForUpdate()->first();
    $fourth = $start('book');
    $waitForLock($fourth);
    $slot->update(['manually_closed' => true]);
    DB::commit();
    $fourth->wait();
    if (! $fourth->isSuccessful() || ! str_contains($fourth->getOutput(), 'REJECTED') || Order::count() !== 1) {
        throw new RuntimeException('Concurrent manual closure failed.');
    }
    echo "PASS: concurrent manual closure rejects the waiting booking.\n";
} finally {
    foreach ($children as $child) {
        if ($child->isRunning()) {
            $child->stop();
        }
    }
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    DB::statement('DROP SCHEMA '.$schema.' CASCADE');
}
