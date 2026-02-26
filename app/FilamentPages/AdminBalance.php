<?php

namespace App\FilamentPages;

use App\Models\Admin;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Stripe\Checkout\Session as StripeSession;
use Stripe\Stripe;

class AdminBalance extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-currency-dollar';
    protected static ?string $navigationGroup = 'Admin';
    protected static ?string $navigationLabel = 'Admin Balances';
    protected static ?string $title = 'Admin Credit Balances';
    protected static ?int $navigationSort = 50;

    protected static string $view = 'filament.pages.admin-balance';

    /** Allowed Stripe deposit amounts (USD) */
    public const STRIPE_AMOUNTS = [3, 5, 10, 20, 50, 100];

    public ?int $selectedAdminId = null;
    public ?float $topupAmount = null;

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Select::make('selectedAdminId')
                    ->label('Admin Account')
                    ->options(Admin::orderBy('email')->pluck('email', 'id'))
                    ->searchable()
                    ->required(),

                TextInput::make('topupAmount')
                    ->label('Amount ($)')
                    ->numeric()
                    ->minValue(0.01)
                    ->step(0.01)
                    ->prefix('$')
                    ->required(),
            ])
            ->statePath('');
    }

    public function topup(): void
    {
        $this->validate([
            'selectedAdminId' => 'required|exists:admins,id',
            'topupAmount'     => 'required|numeric|min:0.01',
        ]);

        $admin = Admin::findOrFail($this->selectedAdminId);
        $admin->topupBalance((float) $this->topupAmount);

        Notification::make()
            ->title('Balance updated')
            ->body(sprintf('Added $%.2f to %s. New balance: $%.4f', $this->topupAmount, $admin->email, $admin->fresh()->credit_balance))
            ->success()
            ->send();

        $this->topupAmount = null;
    }

    /**
     * Initiate a Stripe Checkout session to add credits to the logged-in admin's balance.
     */
    public function stripeCheckout(int $amount): mixed
    {
        if (!in_array($amount, self::STRIPE_AMOUNTS, true)) {
            Notification::make()->title('Invalid amount')->danger()->send();
            return null;
        }

        /** @var Admin $admin */
        $admin = Auth::guard('admin')->user();

        Stripe::setApiKey(config('services.stripe.secret'));

        $session = StripeSession::create([
            'payment_method_types' => ['card'],
            'line_items' => [[
                'price_data' => [
                    'currency' => 'usd',
                    'product_data' => [
                        'name' => "Admin Credits — \${$amount}",
                        'description' => "Add \${$amount}.00 to admin credit balance",
                    ],
                    'unit_amount' => $amount * 100,
                ],
                'quantity' => 1,
            ]],
            'mode' => 'payment',
            'success_url' => url('/admin/admin-balances') . '?stripe_status=success&amount=' . $amount,
            'cancel_url'  => url('/admin/admin-balances') . '?stripe_status=cancelled',
            'client_reference_id' => 'admin:' . $admin->id,
            'metadata' => [
                'admin_id'      => $admin->id,
                'credit_amount' => $amount,
                'type'          => 'admin_topup',
            ],
        ]);

        return redirect()->away($session->url);
    }

    public function getViewData(): array
    {
        return [
            'stripeAmounts' => self::STRIPE_AMOUNTS,
        ];
    }

    public function getAdmins()
    {
        return Admin::orderBy('email')->get(['id', 'name', 'email', 'credit_balance']);
    }
}
