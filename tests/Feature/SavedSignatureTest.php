<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\SavedSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Account-scoped saved signatures (NK_Dev_4).
 *
 * The browser flow is covered by the signature modal checks; these pin the
 * server contract, especially that one account can never read or delete
 * another's signatures.
 */
class SavedSignatureTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private function admin(string $email = 'sig@example.com'): Admin
    {
        return Admin::query()->create([
            'name' => 'Signature Admin',
            'email' => $email,
            'password' => Hash::make('password'),
        ]);
    }

    public function test_guests_cannot_list_or_save(): void
    {
        $this->getJson('/saved-signatures')
            ->assertStatus(401)
            ->assertJsonPath('signed_in', false);

        $this->postJson('/saved-signatures', ['data_url' => self::PNG])
            ->assertStatus(401);
    }

    public function test_signature_round_trips_for_an_account(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')
            ->postJson('/saved-signatures', [
                'name' => 'Work signature',
                'source_mode' => 'type',
                'data_url' => self::PNG,
                'composer' => ['mode' => 'type', 'text' => 'Grace Hopper'],
                'width' => 120,
                'height' => 40,
            ])
            ->assertCreated()
            ->assertJsonPath('signature.name', 'Work signature')
            ->assertJsonPath('signature.sourceMode', 'type')
            ->assertJsonPath('signature.composer.text', 'Grace Hopper');

        $this->actingAs($admin, 'admin')
            ->getJson('/saved-signatures')
            ->assertOk()
            ->assertJsonPath('signed_in', true)
            ->assertJsonCount(1, 'signatures')
            ->assertJsonPath('signatures.0.name', 'Work signature');
    }

    public function test_names_fall_back_when_omitted(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->postJson('/saved-signatures', ['data_url' => self::PNG])
            ->assertCreated()
            ->assertJsonPath('signature.name', 'Signature 1');
    }

    public function test_non_image_payloads_are_rejected(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->postJson('/saved-signatures', ['data_url' => 'javascript:alert(1)'])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_per_account_limit_is_enforced(): void
    {
        $admin = $this->admin();

        for ($i = 0; $i < SavedSignature::PER_ACCOUNT_LIMIT; $i++) {
            SavedSignature::query()->create([
                'admin_id' => $admin->id,
                'name' => "Signature {$i}",
                'source_mode' => 'draw',
                'data_url' => self::PNG,
            ]);
        }

        $this->actingAs($admin, 'admin')
            ->postJson('/saved-signatures', ['data_url' => self::PNG])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_accounts_cannot_see_or_delete_each_others_signatures(): void
    {
        $owner = $this->admin('owner@example.com');
        $other = $this->admin('other@example.com');

        $signature = SavedSignature::query()->create([
            'admin_id' => $owner->id,
            'name' => 'Private',
            'source_mode' => 'draw',
            'data_url' => self::PNG,
        ]);

        // The other account sees an empty list...
        $this->actingAs($other, 'admin')
            ->getJson('/saved-signatures')
            ->assertOk()
            ->assertJsonCount(0, 'signatures');

        // ...and cannot delete by guessing the id.
        $this->actingAs($other, 'admin')
            ->deleteJson('/saved-signatures/'.$signature->id)
            ->assertNotFound();

        $this->assertDatabaseHas('saved_signatures', ['id' => $signature->id]);

        // The owner can.
        $this->actingAs($owner, 'admin')
            ->deleteJson('/saved-signatures/'.$signature->id)
            ->assertOk();

        $this->assertDatabaseMissing('saved_signatures', ['id' => $signature->id]);
    }
}
