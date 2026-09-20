<?php

namespace App\Auth;

use App\Models\Admin;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * The second factor of admin accounts: a time-based code from an authenticator
 * app, with single-use recovery codes. The secret and the recovery codes are
 * stored encrypted. A panel session counts as verified once the code has been
 * given in that session; signing out ends it with the session.
 */
class AdminTwoFactor
{
    public const SESSION_KEY = 'admin_two_factor_verified';

    public function __construct(private Google2FA $engine)
    {
    }

    public function isRequired(): bool
    {
        return (bool) config('security.admin_two_factor.required', true);
    }

    public function isEnrolled(Admin $admin): bool
    {
        return $admin->two_factor_confirmed_at !== null && filled($admin->two_factor_secret);
    }

    public function isVerified(Request $request, Admin $admin): bool
    {
        $session = $request->hasSession() ? $request->session() : session();

        return (int) $session->get(self::SESSION_KEY) === (int) $admin->getKey();
    }

    public function markVerified(Request $request, Admin $admin): void
    {
        // Livewire's component requests carry the same session through the helper.
        ($request->hasSession() ? $request->session() : session())->put(self::SESSION_KEY, (int) $admin->getKey());
    }

    /** Starts (or restarts) enrolment: a fresh secret, not yet confirmed. */
    public function beginEnrolment(Admin $admin): void
    {
        $admin->forceFill([
            'two_factor_secret' => encrypt($this->engine->generateSecretKey(32)),
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();
    }

    public function secret(Admin $admin): ?string
    {
        return filled($admin->two_factor_secret) ? decrypt($admin->two_factor_secret) : null;
    }

    public function qrCodeSvg(Admin $admin): string
    {
        $url = $this->engine->getQRCodeUrl(config('app.name').' admin', $admin->email, (string) $this->secret($admin));
        $svg = (new Writer(new ImageRenderer(new RendererStyle(200, 1), new SvgImageBackEnd())))->writeString($url);

        return trim(substr($svg, strpos($svg, "\n") + 1));
    }

    /**
     * Confirms enrolment with the first code and returns the recovery codes,
     * shown once. Null when the code is wrong.
     *
     * @return string[]|null
     */
    public function confirmEnrolment(Admin $admin, string $code): ?array
    {
        if (! $this->codeIsValid($admin, $code)) {
            return null;
        }
        $codes = array_map(static fn () => Str::lower(Str::random(5).'-'.Str::random(5)), range(1, 8));
        $admin->forceFill([
            'two_factor_recovery_codes' => encrypt(json_encode($codes)),
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $codes;
    }

    /** An authenticator code, or a recovery code (which is then used up). */
    public function verify(Admin $admin, string $code): bool
    {
        $code = trim($code);
        if ($this->codeIsValid($admin, $code)) {
            return true;
        }

        $recovery = filled($admin->two_factor_recovery_codes) ? (array) json_decode(decrypt($admin->two_factor_recovery_codes), true) : [];
        $match = array_search(Str::lower($code), $recovery, true);
        if ($match === false) {
            return false;
        }
        unset($recovery[$match]);
        $admin->forceFill(['two_factor_recovery_codes' => encrypt(json_encode(array_values($recovery)))])->save();

        return true;
    }

    /** One window either side for clock drift, and a code works once. */
    private function codeIsValid(Admin $admin, string $code): bool
    {
        $secret = $this->secret($admin);
        $code = preg_replace('/\s+/', '', $code);
        if ($secret === null || ! preg_match('/^\d{6}$/', (string) $code)) {
            return false;
        }

        // The library only reports which time step a code belongs to when it is
        // given a previous one to compare with; without it the first use would
        // go unrecorded and the same code could be replayed. Hence the 0.
        $cacheKey = 'admin-2fa-last:'.$admin->getKey();
        $timestamp = $this->engine->verifyKeyNewer($secret, $code, (int) Cache::get($cacheKey, 0), 1);
        if (! is_int($timestamp)) {
            return false;
        }
        Cache::put($cacheKey, $timestamp, 300);

        return true;
    }
}
