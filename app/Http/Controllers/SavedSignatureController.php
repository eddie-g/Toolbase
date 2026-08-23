<?php

namespace App\Http\Controllers;

use App\Models\SavedSignature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Account-scoped saved signatures (NK_Dev_4).
 *
 * Backs the "Save signature" button beside Clear in the signature modal and
 * the account list in the modal's Saved tab.
 */
class SavedSignatureController extends Controller
{
    /** Largest accepted data URL, so a huge upload cannot bloat the table. */
    private const MAX_DATA_URL_BYTES = 3_000_000;

    public function index(): JsonResponse
    {
        $ownership = $this->currentOwnership();
        if (! $this->isSignedIn($ownership)) {
            return $this->guestResponse();
        }

        $signatures = SavedSignature::query()
            ->forOwner($ownership)
            ->orderByDesc('updated_at')
            ->limit(SavedSignature::PER_ACCOUNT_LIMIT)
            ->get()
            ->map(fn (SavedSignature $signature) => $signature->toModalPayload())
            ->all();

        return response()->json([
            'success' => true,
            'signed_in' => true,
            'limit' => SavedSignature::PER_ACCOUNT_LIMIT,
            'signatures' => $signatures,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $ownership = $this->currentOwnership();
        if (! $this->isSignedIn($ownership)) {
            return $this->guestResponse();
        }

        $validated = $request->validate([
            'name' => 'nullable|string|max:120',
            'source_mode' => 'nullable|string|in:draw,type,upload',
            'data_url' => 'required|string',
            'composer' => 'nullable|array',
            'width' => 'nullable|integer|min:1|max:20000',
            'height' => 'nullable|integer|min:1|max:20000',
        ]);

        $dataUrl = (string) $validated['data_url'];
        if (! str_starts_with($dataUrl, 'data:image/')) {
            return response()->json([
                'success' => false,
                'message' => 'Signature image must be an inline image data URL.',
            ], 422);
        }
        if (strlen($dataUrl) > self::MAX_DATA_URL_BYTES) {
            return response()->json([
                'success' => false,
                'message' => 'That signature image is too large to save.',
            ], 422);
        }

        $existing = SavedSignature::query()->forOwner($ownership)->count();
        if ($existing >= SavedSignature::PER_ACCOUNT_LIMIT) {
            return response()->json([
                'success' => false,
                'message' => 'You have reached the '.SavedSignature::PER_ACCOUNT_LIMIT.' saved signature limit. Delete one first.',
            ], 422);
        }

        $name = trim((string) ($validated['name'] ?? ''));
        if ($name === '') {
            $name = 'Signature '.($existing + 1);
        }

        $signature = SavedSignature::query()->create([
            ...$ownership,
            'name' => $name,
            'source_mode' => $validated['source_mode'] ?? 'draw',
            'data_url' => $dataUrl,
            'composer' => $validated['composer'] ?? null,
            'width' => $validated['width'] ?? 0,
            'height' => $validated['height'] ?? 0,
        ]);

        return response()->json([
            'success' => true,
            'signature' => $signature->toModalPayload(),
        ], 201);
    }

    public function destroy(string $savedSignature): JsonResponse
    {
        $ownership = $this->currentOwnership();
        if (! $this->isSignedIn($ownership)) {
            return $this->guestResponse();
        }

        // Scope the lookup by owner so one account can never delete another's.
        $signature = SavedSignature::query()
            ->forOwner($ownership)
            ->whereKey($savedSignature)
            ->first();

        if ($signature === null) {
            return response()->json(['success' => false, 'message' => 'Signature not found.'], 404);
        }

        $signature->delete();

        return response()->json(['success' => true]);
    }

    /** @return array{user_id: int|null, admin_id: int|null} */
    private function currentOwnership(): array
    {
        $userId = Auth::guard('web')->id();
        if ($userId !== null) {
            return ['user_id' => (int) $userId, 'admin_id' => null];
        }

        $adminId = Auth::guard('admin')->id();
        if ($adminId !== null) {
            return ['user_id' => null, 'admin_id' => (int) $adminId];
        }

        return ['user_id' => null, 'admin_id' => null];
    }

    /** @param array{user_id: int|null, admin_id: int|null} $ownership */
    private function isSignedIn(array $ownership): bool
    {
        return $ownership['user_id'] !== null || $ownership['admin_id'] !== null;
    }

    private function guestResponse(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'signed_in' => false,
            'message' => 'Sign in to save signatures to your account.',
        ], 401);
    }
}
