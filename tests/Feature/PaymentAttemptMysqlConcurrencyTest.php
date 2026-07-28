<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\Payments\PaymentAttemptService;
use App\Services\Payments\PaymentRetryAuthorizationService;
use App\Services\Payments\Providers\CardPaymentProvider;
use Database\Seeders\PaymentSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Concerns\BuildsPaymentAttemptFixtures;
use Tests\Fakes\FakeCardPaymentProvider;
use Tests\TestCase;
use Throwable;

class PaymentAttemptMysqlConcurrencyTest extends TestCase
{
    use BuildsPaymentAttemptFixtures;

    public function test_mysql_same_key_replays_one_attempt_and_provider_reference(): void
    {
        $key = $this->paymentAttemptKey('mysql-same');

        $this->assertConcurrentAttemptCase(
            [$key, $key],
            transactionStatus: 'failed',
            expectedReplayed: [false, true],
            expectedTransactionDelta: 1,
        );
    }

    public function test_mysql_different_keys_create_at_most_one_new_attempt_and_transaction(): void
    {
        $this->assertConcurrentAttemptCase(
            [
                $this->paymentAttemptKey('mysql-a'),
                $this->paymentAttemptKey('mysql-b'),
            ],
            transactionStatus: 'failed',
            expectedReplayed: [false, true],
            expectedTransactionDelta: 1,
        );
    }

    public function test_mysql_pending_transaction_is_recovered_without_provider_or_duplicate(): void
    {
        $this->assertConcurrentAttemptCase(
            [
                $this->paymentAttemptKey('mysql-pending-a'),
                $this->paymentAttemptKey('mysql-pending-b'),
            ],
            transactionStatus: 'pending',
            expectedReplayed: [true, true],
            expectedTransactionDelta: 0,
        );
    }

    /**
     * @param  array<int, string>  $keys
     * @param  array<int, bool>  $expectedReplayed
     */
    private function assertConcurrentAttemptCase(
        array $keys,
        string $transactionStatus,
        array $expectedReplayed,
        int $expectedTransactionDelta,
    ): void {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires MySQL.');
        }

        if (! function_exists('pcntl_fork')) {
            $this->fail('pcntl_fork is required for MySQL concurrency validation.');
        }

        foreach ([
            'orders',
            'payment_methods',
            'payment_transactions',
            'payment_attempts',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }

        config()->set('payments.methods.card.enabled', true);
        config()->set(
            'payments.methods.card.approved_redirect_hosts',
            ['payments.example.test'],
        );
        $this->seed(PaymentSeeder::class);
        $card = PaymentMethod::query()->where('code', 'card')->firstOrFail();
        $originalStatus = $card->status;
        $card->update(['status' => 'active']);
        $this->app->instance(CardPaymentProvider::class, new FakeCardPaymentProvider);
        $owner = User::factory()->create();
        $order = $this->paymentOrder(
            $owner,
            paymentStatus: $transactionStatus === 'pending' ? 'pending' : 'failed',
        );
        $this->paymentTransaction($order, $transactionStatus);
        $startingTransactions = $order->paymentTransactions()->count();

        try {
            $results = $this->forkAttempts(
                $order->getKey(),
                $owner->getKey(),
                $keys,
            );

            $this->assertSame($expectedReplayed, collect($results)
                ->pluck('replayed')
                ->sort()
                ->values()
                ->all());
            $this->assertSame(1, PaymentAttempt::query()
                ->where('order_id', $order->getKey())
                ->count());
            $this->assertSame(
                $startingTransactions + $expectedTransactionDelta,
                $order->paymentTransactions()->count(),
            );
            $attempt = PaymentAttempt::query()
                ->where('order_id', $order->getKey())
                ->sole();
            $this->assertSame(1, $order->paymentTransactions()
                ->where('transaction_id', $attempt->provider_reference)
                ->count());
            $this->assertSame('pending', $order->fresh()->payment_status);
        } finally {
            Order::query()->whereKey($order->getKey())->delete();
            User::query()->whereKey($owner->getKey())->forceDelete();
            $card->update(['status' => $originalStatus]);
        }
    }

    /**
     * @param  array<int, string>  $keys
     * @return array<int, array{replayed: bool, reference: string}>
     */
    private function forkAttempts(int $orderId, int $userId, array $keys): array
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'payment-attempts-'.uniqid();
        $startFile = $directory.DIRECTORY_SEPARATOR.'start';
        $children = [];

        $this->purgeConnections();

        if (! mkdir($directory)) {
            throw new RuntimeException('Unable to create payment attempt synchronization directory.');
        }

        try {
            foreach ($keys as $index => $key) {
                $pid = pcntl_fork();

                if ($pid === -1) {
                    throw new RuntimeException('Unable to fork payment attempt process.');
                }

                if ($pid === 0) {
                    while (! file_exists($startFile)) {
                        usleep(1_000);
                    }

                    $this->purgeConnections();

                    try {
                        $order = Order::query()->findOrFail($orderId);
                        $user = User::query()->findOrFail($userId);
                        $authorization = app(PaymentRetryAuthorizationService::class)
                            ->accountOwner($order, $user);
                        $result = app(PaymentAttemptService::class)
                            ->attempt($authorization, $key);
                        $payload = [
                            'replayed' => $result->replayed,
                            'reference' => $result->attempt->reference,
                        ];
                    } catch (Throwable $exception) {
                        $payload = [
                            'error' => get_class($exception),
                            'message' => $exception->getMessage(),
                        ];
                    }

                    $written = file_put_contents(
                        $directory.DIRECTORY_SEPARATOR."result-{$index}.json",
                        json_encode($payload, JSON_THROW_ON_ERROR),
                    );
                    $this->purgeConnections();
                    exit($written === false ? 1 : 0);
                }

                $children[] = $pid;
            }

            touch($startFile);

            foreach ($children as $pid) {
                $this->assertSame($pid, pcntl_waitpid($pid, $status));
                $this->assertTrue(pcntl_wifexited($status));
                $this->assertSame(0, pcntl_wexitstatus($status));
            }

            return collect(array_keys($keys))
                ->map(function (int $index) use ($directory): array {
                    $path = $directory.DIRECTORY_SEPARATOR."result-{$index}.json";
                    $this->assertFileExists($path);
                    $result = json_decode(
                        (string) file_get_contents($path),
                        true,
                        512,
                        JSON_THROW_ON_ERROR,
                    );
                    $this->assertArrayNotHasKey('error', $result);

                    return $result;
                })
                ->all();
        } finally {
            if (! file_exists($startFile)) {
                touch($startFile);
            }

            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status, WNOHANG);
            }

            foreach (glob($directory.DIRECTORY_SEPARATOR.'*') ?: [] as $path) {
                unlink($path);
            }

            if (is_dir($directory)) {
                rmdir($directory);
            }

            $this->purgeConnections();
        }
    }

    private function purgeConnections(): void
    {
        foreach (array_keys(DB::getConnections()) as $connection) {
            DB::purge($connection);
        }
    }
}
