<?php

use App\Models\GuidedTemplate;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        GuidedTemplate::whereIn('slug', [
            'newsletter_classic',
            'newsletter_modern',
            'purchase_order',
        ])->update(['is_active' => false]);

        GuidedTemplate::where('slug', 'default')->update([
            'type' => 'invoice',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        GuidedTemplate::where('slug', 'bold_red')->update([
            'type' => 'invoice',
            'sort_order' => 2,
            'is_active' => true,
        ]);

        GuidedTemplate::where('slug', 'lease_extension')->update([
            'type' => 'realestate',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        GuidedTemplate::where('slug', 'security_deposit_return')->update([
            'type' => 'realestate',
            'sort_order' => 2,
            'is_active' => true,
        ]);
    }

    public function down(): void
    {
        GuidedTemplate::whereIn('slug', [
            'newsletter_classic',
            'newsletter_modern',
            'purchase_order',
        ])->update(['is_active' => true]);
    }
};
