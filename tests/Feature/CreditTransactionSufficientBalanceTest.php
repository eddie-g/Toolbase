<?php

namespace Tests\Feature;

use App\Exceptions\InsufficientCreditBalanceException;
use App\Models\CreditTransaction;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CreditTransactionSufficientBalanceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->decimal('credit_balance', 10, 4)->default(0);
            $table->timestamps();
        });
        Schema::create('credit_transactions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('type');
            $table->decimal('amount', 10, 6);
            $table->decimal('balance_after', 10, 4);
            $table->string('service');
            $table->string('model_name')->nullable();
            $table->string('description')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function test_it_atomically_debits_a_conversion_with_sufficient_balance(): void
    {
        $user = $this->userWithBalance(1.00);

        $transaction = CreditTransaction::debitIfSufficient(
            userId: $user->id,
            amount: 0.10,
            service: 'document_conversion',
        );

        $this->assertSame('0.100000', $transaction->amount);
        $this->assertSame('0.9000', $user->fresh()->credit_balance);
    }

    public function test_it_does_not_debit_or_log_when_balance_is_insufficient(): void
    {
        $user = $this->userWithBalance(0.05);

        try {
            CreditTransaction::debitIfSufficient(
                userId: $user->id,
                amount: 0.10,
                service: 'document_conversion',
            );
            $this->fail('Expected an insufficient balance exception.');
        } catch (InsufficientCreditBalanceException $e) {
            $this->assertSame(0.10, $e->required);
            $this->assertSame(0.05, $e->available);
        }

        $this->assertSame('0.0500', $user->fresh()->credit_balance);
        $this->assertSame(0, CreditTransaction::query()->count());
    }

    private function userWithBalance(float $balance): User
    {
        return User::query()->create([
            'name' => 'Conversion User',
            'email' => uniqid('conversion-', true).'@example.com',
            'password' => 'password',
            'credit_balance' => $balance,
        ]);
    }
}
