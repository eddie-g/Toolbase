<?php

namespace Tests\Feature;

use App\Models\CreditTransaction;
use App\Models\User;
use App\Models\UserActivity;
use App\UserPortal\Support\RecentActivity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * NK_58: a Word / Excel conversion is paid, and the portal shows what was
 * debited for it; free editor actions, and quotes that were never charged,
 * show no amount.
 */
class PortalActivityChargesTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_charged_conversion_shows_its_debit_and_free_actions_show_nothing(): void
    {
        $user = User::factory()->create(['credit_balance' => 5]);
        $debit = CreditTransaction::debitIfSufficient(
            userId: $user->id,
            amount: 0.10,
            service: 'document_conversion',
            modelName: 'adobe-pdf-services-export',
            description: 'Word PDF conversion (1 page)',
            metadata: [],
        );

        $charged = UserActivity::create([
            'user_id' => $user->id, 'action' => 'Convert to Word', 'category' => 'word_export', 'status' => 'success',
            'details' => ['charge_usd' => 0.10, 'billing_transaction_id' => $debit->id],
        ]);
        $quotedOnly = UserActivity::create([
            'user_id' => $user->id, 'action' => 'Convert to Excel', 'category' => 'excel_export', 'status' => 'success',
            'details' => ['conversion_charge_usd' => 0.10],
        ]);
        $free = UserActivity::create([
            'user_id' => $user->id, 'action' => 'Split PDF', 'category' => 'split_export', 'status' => 'success', 'details' => [],
        ]);

        $this->assertSame([$charged->id => 0.1], UserActivity::chargesFor([$charged, $quotedOnly, $free]));

        $costs = (new RecentActivity($user))->items('pdf', 10)->pluck('cost', 'title');
        $this->assertSame(0.1, $costs['Convert to Word']);
        $this->assertNull($costs['Convert to Excel']);
        $this->assertNull($costs['Split PDF']);
    }

    public function test_another_accounts_debit_is_never_shown(): void
    {
        $user = User::factory()->create(['credit_balance' => 5]);
        $other = User::factory()->create(['credit_balance' => 5]);
        $debit = CreditTransaction::debitIfSufficient(
            userId: $other->id, amount: 0.10, service: 'document_conversion',
            modelName: 'x', description: 'x', metadata: [],
        );
        $activity = UserActivity::create([
            'user_id' => $user->id, 'action' => 'Convert to Word', 'category' => 'word_export', 'status' => 'success',
            'details' => ['billing_transaction_id' => $debit->id],
        ]);

        $this->assertSame([], UserActivity::chargesFor([$activity]));
    }
}
