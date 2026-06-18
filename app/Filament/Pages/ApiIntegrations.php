<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Illuminate\Support\Facades\Http;
use App\Services\FalBalanceService;

class ApiIntegrations extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-puzzle-piece';

    protected static ?string $navigationLabel = 'API Integrations';

    protected static ?string $title = 'API Integrations';

    protected static ?string $navigationGroup = 'Admin';

    protected static ?int $navigationSort = 10;

    protected static string $view = 'filament.pages.api-integrations';

    public array $integrations = [];

    public bool $fetching = false;

    public ?string $lastRefreshed = null;

    // fal.ai balance input
    public string $newFalBalance = '';
    public bool $showBalanceInput = false;

    public function mount(): void
    {
        $this->refresh();
    }

    public function refresh(): void
    {
        $this->fetching = true;

        $this->integrations = [
            'flux'    => $this->fetchFalBalance(),
            'openai'  => $this->fetchOpenAiBalance(),
            'gemini'  => $this->fetchGeminiStatus(),
            'recraft' => $this->fetchRecraftBalance(),
        ];

        $this->lastRefreshed = now()->format('H:i:s');
        $this->fetching = false;
    }

    public function saveManualBalance(): void
    {
        $value = trim($this->newFalBalance);

        if (!is_numeric($value) || (float)$value < 0) {
            $this->addError('newFalBalance', 'Please enter a valid positive number.');
            return;
        }

        FalBalanceService::setBalance((float)$value, 'Manually set via API Integrations page');

        $this->newFalBalance   = '';
        $this->showBalanceInput = false;

        // Refresh the flux card
        $this->integrations['flux'] = $this->fetchFalBalance();
        $this->lastRefreshed = now()->format('H:i:s');
    }

    // ─── fal.ai (Flux) ───────────────────────────────────────────────────────

    private function fetchFalBalance(): array
    {
        // ── Always hydrate from local ledger first ─────────────────────────
        $ledgerBalance = FalBalanceService::current();
        $totalSpent    = FalBalanceService::totalSpent();
        $totalCredited = FalBalanceService::totalCredited();
        $recentEntries = FalBalanceService::recent(5)->map(fn($e) => [
            'type'    => $e->type,
            'amount'  => $e->amount,
            'balance' => $e->balance_after,
            'model'   => $e->model,
            'date'    => $e->created_at?->format('M d H:i'),
        ])->toArray();

        $ledgerExtra = array_filter([
            'Total Spent'   => $totalSpent > 0  ? '$' . number_format($totalSpent, 4)    : null,
            'Total Credited'=> $totalCredited > 0 ? '$' . number_format($totalCredited, 4) : null,
        ]);

        $key = config('services.fal.key');

        if (!$key) {
            // No API key but may still have ledger data
            return [
                'status'         => $ledgerBalance > 0 ? 'ok' : 'no_key',
                'balance'        => $ledgerBalance > 0 ? $ledgerBalance : null,
                'label'          => 'Tracked Balance',
                'currency'       => 'USD',
                'note'           => 'No API key — balance tracked locally only',
                'extra'          => $ledgerExtra,
                'ledger_entries' => $recentEntries,
                'has_ledger'     => true,
            ];
        }

        $headers = ['Authorization' => 'Key ' . $key];

        try {
            // Attempt 1: REST alpha billing endpoint
            $response = Http::withHeaders($headers)
                ->timeout(8)
                ->get('https://rest.alpha.fal.ai/billing/credit-balance');

            if ($response->successful()) {
                $data    = $response->json();
                $balance = $data['credit_balance'] ?? $data['balance'] ?? $data['credits'] ?? null;
                return [
                    'status'         => 'ok',
                    'balance'        => $balance !== null ? (float)$balance : $ledgerBalance,
                    'label'          => $balance !== null ? 'Credits (fal.ai)' : 'Tracked Balance',
                    'currency'       => 'USD',
                    'extra'          => $ledgerExtra,
                    'ledger_entries' => $recentEntries,
                    'has_ledger'     => true,
                ];
            }

            // Attempt 2: primary v1 billing endpoint
            $response2 = Http::withHeaders($headers)
                ->timeout(8)
                ->get('https://api.fal.ai/v1/billing/balance');

            if ($response2->successful()) {
                $data    = $response2->json();
                $balance = isset($data['balance']) ? (float)$data['balance'] : null;
                return [
                    'status'         => 'ok',
                    'balance'        => $balance ?? $ledgerBalance,
                    'label'          => $balance !== null ? 'Balance (fal.ai)' : 'Tracked Balance',
                    'currency'       => 'USD',
                    'extra'          => $ledgerExtra,
                    'ledger_entries' => $recentEntries,
                    'has_ledger'     => true,
                ];
            }

            // Non-auth error — key is valid but no balance API exists
            // Fall back entirely to local ledger
            $status = in_array($response2->status(), [401, 403]) ? 'error' : 'connected';

            return [
                'status'         => $ledgerBalance > 0 ? 'ok' : $status,
                'balance'        => $ledgerBalance > 0 ? $ledgerBalance : null,
                'label'          => 'Tracked Balance',
                'currency'       => 'USD',
                'note'           => $status === 'error'
                    ? 'Invalid API key'
                    : 'No balance API — tracking locally. Set your starting balance below.',
                'extra'          => $ledgerExtra,
                'ledger_entries' => $recentEntries,
                'has_ledger'     => true,
            ];
        } catch (\Exception $e) {
            return array_merge($this->exception($e), [
                'balance'        => $ledgerBalance > 0 ? $ledgerBalance : null,
                'ledger_entries' => $recentEntries,
                'has_ledger'     => true,
                'extra'          => $ledgerExtra,
            ]);
        }
    }

    // ─── OpenAI (ChatGPT / GPT Image) ────────────────────────────────────────

    private function fetchOpenAiBalance(): array
    {
        $key = config('services.openai.api_key');

        if (!$key) {
            return $this->noKey();
        }

        return [
            'status'  => 'connected',
            'balance' => null,
            'label'   => null,
            'note'    => 'No balance API available — usage billed per request',
            'extra'   => [
                'Model' => config('services.openai.model', 'gpt-4o'),
            ],
        ];
    }

    // ─── Google Gemini ────────────────────────────────────────────────────────

    private function fetchGeminiStatus(): array
    {
        $key = config('services.gemini.api_key');

        if (!$key) {
            return $this->noKey();
        }

        try {
            $base = rtrim(config('services.gemini.base_url', 'https://generativelanguage.googleapis.com/v1beta'), '/');
            $response = Http::timeout(8)->get($base . '/models', ['key' => $key]);

            if ($response->successful()) {
                $models = $response->json()['models'] ?? [];
                $activeModel = config('services.gemini.model', 'gemini-2.5-flash-lite');

                return [
                    'status'  => 'connected',
                    'balance' => null,
                    'label'   => 'Connected',
                    'note'    => 'No balance API (managed via Google Cloud)',
                    'extra'   => [
                        'Active Model'     => $activeModel,
                        'Models Available' => count($models),
                    ],
                ];
            }

            return $this->httpError($response->status(), $response->body());
        } catch (\Exception $e) {
            return $this->exception($e);
        }
    }

    // ─── Recraft ──────────────────────────────────────────────────────────────

    private function fetchRecraftBalance(): array
    {
        $key = config('services.recraft.key');

        if (!$key) {
            return $this->noKey();
        }

        try {
            $base = rtrim(config('services.recraft.base_url', 'https://external.api.recraft.ai'), '/');
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $key,
            ])->timeout(8)->get($base . '/v1/users/me');

            if ($response->successful()) {
                $d = $response->json();
                $credits = $d['credits'] ?? ($d['credit_balance'] ?? ($d['balance'] ?? null));

                $extra = [];
                if (!empty($d['id']))    $extra['User ID'] = $d['id'];
                if (!empty($d['email'])) $extra['Email']   = $d['email'];

                return [
                    'status'   => 'ok',
                    'balance'  => $credits !== null ? (float) $credits : null,
                    'label'    => 'Credits',
                    'currency' => 'credits',
                    'extra'    => $extra,
                ];
            }

            return $this->httpError($response->status(), $response->body());
        } catch (\Exception $e) {
            return $this->exception($e);
        }
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function noKey(): array
    {
        return ['status' => 'no_key', 'balance' => null, 'label' => null, 'note' => 'No API key configured', 'extra' => []];
    }

    private function httpError(int $code, string $body): array
    {
        $decoded = json_decode($body, true);
        $msg = $decoded['error']['message'] ?? $decoded['message'] ?? ('HTTP ' . $code);
        return ['status' => 'error', 'balance' => null, 'label' => null, 'note' => $msg, 'extra' => []];
    }

    private function exception(\Exception $e): array
    {
        return ['status' => 'error', 'balance' => null, 'label' => null, 'note' => $e->getMessage(), 'extra' => []];
    }
}
