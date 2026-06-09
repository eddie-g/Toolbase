<?php

namespace Tests\Feature;

use App\Http\Controllers\CreditController;
use App\Models\CreditTransaction;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\TestCase;

class CreditCheckoutTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
        ]);

        DB::purge('sqlite');
        DB::reconnect('sqlite');

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->decimal('credit_balance', 10, 4)->default(0);
            $table->timestamps();
        });

        Schema::create('credit_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id');
            $table->string('type')->default('debit');
            $table->decimal('amount', 10, 6);
            $table->decimal('balance_after', 10, 4);
            $table->string('service')->index();
            $table->string('model_name')->nullable();
            $table->string('description')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function test_paid_checkout_session_adds_credits_once(): void
    {
        $user = User::factory()->create(['credit_balance' => 0]);
        $session = $this->checkoutSession($user->id, 5);

        $firstResult = $this->processCreditCheckoutSession($session, $user->id);
        $secondResult = $this->processCreditCheckoutSession($session, $user->id);

        $this->assertSame('credited', $firstResult['status']);
        $this->assertSame('already_processed', $secondResult['status']);
        $this->assertSame(5.0, (float) $user->refresh()->credit_balance);
        $this->assertSame(1, CreditTransaction::where('metadata->stripe_session_id', $session->id)->count());
    }

    public function test_checkout_session_for_another_user_is_not_credited(): void
    {
        $user = User::factory()->create(['credit_balance' => 0]);
        $otherUser = User::factory()->create(['credit_balance' => 0]);
        $session = $this->checkoutSession($otherUser->id, 5);

        $result = $this->processCreditCheckoutSession($session, $user->id);

        $this->assertSame('wrong_user', $result['status']);
        $this->assertSame(0.0, (float) $user->refresh()->credit_balance);
        $this->assertSame(0, CreditTransaction::where('metadata->stripe_session_id', $session->id)->count());
    }

    private function processCreditCheckoutSession(object $session, int $expectedUserId): array
    {
        $method = new ReflectionMethod(CreditController::class, 'processCreditCheckoutSession');
        $method->setAccessible(true);

        return $method->invoke(new CreditController(), $session, $expectedUserId, 'test');
    }

    private function checkoutSession(int $userId, int $amount): object
    {
        return (object) [
            'id' => 'cs_test_' . uniqid(),
            'mode' => 'payment',
            'payment_status' => 'paid',
            'client_reference_id' => (string) $userId,
            'payment_intent' => 'pi_test',
            'metadata' => (object) [
                'user_id' => $userId,
                'credit_amount' => $amount,
            ],
        ];
    }
}
