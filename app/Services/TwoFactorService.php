<?php

namespace App\Services;

use App\Models\User;
use App\Support\AuditEvent;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use LogicException;
use PragmaRX\Google2FA\Google2FA;

/**
 * TOTP two-factor authentication: enrolment, verification with replay protection, recovery codes.
 */
class TwoFactorService
{
    /**
     * Session key holding the id of a user who passed the password step but not yet 2FA.
     */
    public const PENDING_LOGIN_SESSION_KEY = 'login.id';

    private const WINDOW = 1;

    private const RECOVERY_CODE_COUNT = 8;

    public function __construct(
        private readonly Google2FA $google2fa,
        private readonly AuditLogger $audit,
    ) {}

    public function generateSecret(): string
    {
        return $this->google2fa->generateSecretKey(32);
    }

    public function otpauthUrl(User $user, string $secret): string
    {
        return $this->google2fa->getQRCodeUrl((string) config('app.name'), $user->email, $secret);
    }

    /**
     * An inline SVG (192 px) of the otpauth URL, without the XML declaration.
     */
    public function qrCodeSvg(User $user, string $secret): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(192), new SvgImageBackEnd));

        $svg = $writer->writeString($this->otpauthUrl($user, $secret));

        return trim((string) preg_replace('/^<\?xml[^>]*\?>/', '', $svg));
    }

    /**
     * Store a new pending (unconfirmed) secret and return it.
     */
    public function beginEnrolment(User $user): string
    {
        if ($user->hasConfirmedTwoFactor()) {
            throw new LogicException('Two-factor authentication is already enabled for this user.');
        }

        $secret = $this->generateSecret();

        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        return $secret;
    }

    /**
     * Confirm the pending secret. Returns the plain recovery codes once, or null for a wrong code.
     *
     * @return list<string>|null
     */
    public function confirmEnrolment(User $user, string $code): ?array
    {
        if ($user->two_factor_secret === null || $user->two_factor_confirmed_at !== null) {
            return null;
        }

        if (! $this->verifyAgainstSecret($user, $user->two_factor_secret, $code)) {
            return null;
        }

        $codes = $this->makeRecoveryCodes();

        $user->forceFill([
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => $this->hashCodes($codes),
        ])->save();

        return $codes;
    }

    /**
     * Verify a TOTP code for a user with confirmed 2FA. A code is accepted once only.
     */
    public function verifyCode(User $user, string $code): bool
    {
        if (! $user->hasConfirmedTwoFactor()) {
            return false;
        }

        return $this->verifyAgainstSecret($user, $user->two_factor_secret, $code);
    }

    public function useRecoveryCode(User $user, string $code): bool
    {
        $code = Str::upper(trim($code));
        $hashes = $user->two_factor_recovery_codes ?? [];

        if ($code === '' || ! $user->hasConfirmedTwoFactor()) {
            return false;
        }

        foreach ($hashes as $index => $hash) {
            if (Hash::check($code, $hash)) {
                unset($hashes[$index]);

                $user->forceFill(['two_factor_recovery_codes' => array_values($hashes)])->save();

                return true;
            }
        }

        return false;
    }

    /**
     * Turn 2FA off. Refused for roles that must use it (Admin, Accountant).
     */
    public function disable(User $user, User $actor): void
    {
        if ($user->requiresTwoFactor()) {
            throw new LogicException('Two-factor authentication is required for this role and cannot be disabled.');
        }

        DB::transaction(function () use ($user, $actor): void {
            $wasConfirmed = $user->hasConfirmedTwoFactor();

            $user->forceFill([
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
            ])->save();

            $this->audit->record(
                AuditEvent::TwoFactorDisabled,
                $user,
                ['two_factor_enabled' => $wasConfirmed],
                ['two_factor_enabled' => false],
                $actor,
            );
        });
    }

    /**
     * @return list<string>
     */
    public function regenerateRecoveryCodes(User $user): array
    {
        if (! $user->hasConfirmedTwoFactor()) {
            throw new LogicException('Two-factor authentication is not enabled for this user.');
        }

        $codes = $this->makeRecoveryCodes();

        $user->forceFill(['two_factor_recovery_codes' => $this->hashCodes($codes)])->save();

        return $codes;
    }

    /**
     * Window ±1 step; a timestep already accepted for this user is rejected (replay).
     */
    private function verifyAgainstSecret(User $user, string $secret, string $code): bool
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';

        if (preg_match('/^\d{6}$/', $code) !== 1) {
            return false;
        }

        $cacheKey = "2fa:last:{$user->id}";
        $lastTimestep = (int) Cache::get($cacheKey, 0);

        $timestep = $this->google2fa->verifyKeyNewer($secret, $code, $lastTimestep, self::WINDOW);

        if (! is_int($timestep)) {
            return false;
        }

        Cache::put($cacheKey, $timestep, now()->addMinutes(2));

        return true;
    }

    /**
     * @return list<string>
     */
    private function makeRecoveryCodes(): array
    {
        return array_map(
            fn (): string => Str::upper(Str::random(5)).'-'.Str::upper(Str::random(5)),
            range(1, self::RECOVERY_CODE_COUNT),
        );
    }

    /**
     * @param  list<string>  $codes
     * @return list<string>
     */
    private function hashCodes(array $codes): array
    {
        return array_map(fn (string $code): string => Hash::make($code), $codes);
    }
}
