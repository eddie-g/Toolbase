<?php

namespace Tests\Feature;

use App\Filament\Resources\PdfTestReportResource\Pages\ViewPdfTestReport;
use App\Models\Admin;
use App\Models\PdfTestReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PdfTestReportViewPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_view_page_renders_individual_checks(): void
    {
        $admin = Admin::create([
            'name' => 'Test Admin',
            'email' => 'infolist-admin@example.test',
            'password' => bcrypt('secret-password'),
        ]);
        $this->actingAs($admin, 'admin');

        $record = PdfTestReport::create([
            'run_id' => 'run-1',
            'test_type' => 'pdf',
            'test_key' => 'k',
            'filename' => 'k.pdf',
            'description' => 'd',
            'test_category' => 'PDF Upload Tests',
            'section_name' => 's',
            'status' => 'fail',
            'checks' => [
                ['item' => 'alpha_check', 'result' => 'PASS', 'description' => 'Alpha assertion.', 'detail' => 'alpha-detail'],
                ['name' => 'beta_check', 'status' => 'FAIL', 'message' => 'beta-detail'],
            ],
            'checks_passed' => 1,
            'checks_total' => 2,
        ]);

        Livewire::test(ViewPdfTestReport::class, ['record' => $record->getKey()])
            ->assertSee('alpha_check')
            ->assertSee('alpha-detail')
            ->assertSee('Alpha assertion.')
            ->assertSee('beta_check')
            ->assertSee('beta-detail');
    }
}
