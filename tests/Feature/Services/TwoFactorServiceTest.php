<?php

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\User;
use App\Services\TwoFactorService;
use App\Support\RoleName;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;

beforeEach(function () {
    $this->service = app(TwoFactorService::class);
    $this->google2fa = new Google2FA;
});

function userWithRole(RoleName $role, ?string $secret = null): User
{
    $factory = User::factory();

    if ($secret !== null) {
        $factory = $factory->withTwoFactor($secret);
    }

    $user = $factory->create();
    Employee::factory()->forRole($role)->create(['user_id' => $user->id]);

    return $user->fresh();
}

it('begins enrolment with a pending secret', function () {
    $user = userWithRole(RoleName::ADMIN);

    $secret = $this->service->beginEnrolment($user);

    expect($secret)->toHaveLength(32)
        ->and($user->fresh()->two_factor_secret)->toBe($secret)
        ->and($user->fresh()->two_factor_confirmed_at)->toBeNull()
        ->and($user->fresh()->hasConfirmedTwoFactor())->toBeFalse();
})->group('phase0');

it('refuses to begin enrolment when 2FA is already confirmed', function () {
    $user = userWithRole(RoleName::ADMIN, $this->service->generateSecret());

    expect(fn () => $this->service->beginEnrolment($user))->toThrow(LogicException::class);
})->group('phase0');

it('confirms enrolment with a valid code and returns eight hashed-at-rest recovery codes', function () {
    $user = userWithRole(RoleName::ADMIN);
    $secret = $this->service->beginEnrolment($user);

    $codes = $this->service->confirmEnrolment($user, $this->google2fa->getCurrentOtp($secret));

    $user->refresh();
    $stored = $user->two_factor_recovery_codes;

    expect($codes)->toHaveCount(8)
        ->and($user->hasConfirmedTwoFactor())->toBeTrue()
        ->and($stored)->toHaveCount(8);

    foreach ($codes as $index => $code) {
        expect($code)->toMatch('/^[A-Z0-9]{5}-[A-Z0-9]{5}$/')
            ->and($stored[$index])->not->toBe($code)
            ->and(Hash::isHashed($stored[$index]))->toBeTrue()
            ->and(Hash::check($code, $stored[$index]))->toBeTrue();
    }
})->group('phase0');

it('returns null for a wrong enrolment code', function () {
    $user = userWithRole(RoleName::EMPLOYEE);
    $secret = $this->service->beginEnrolment($user);
    $wrong = $this->google2fa->getCurrentOtp($secret) === '000000' ? '111111' : '000000';

    expect($this->service->confirmEnrolment($user, $wrong))->toBeNull()
        ->and($user->fresh()->two_factor_confirmed_at)->toBeNull();
})->group('phase0');

it('accepts a code once and rejects its replay', function () {
    $secret = $this->service->generateSecret();
    $user = userWithRole(RoleName::ADMIN, $secret);
    $code = $this->google2fa->getCurrentOtp($secret);

    expect($this->service->verifyCode($user, $code))->toBeTrue()
        ->and($this->service->verifyCode($user, $code))->toBeFalse()
        ->and($this->service->verifyCode($user, 'abcdef'))->toBeFalse();
})->group('phase0');

it('accepts a recovery code once', function () {
    $user = userWithRole(RoleName::ACCOUNTANT, $this->service->generateSecret());
    $codes = $this->service->regenerateRecoveryCodes($user);

    expect($this->service->useRecoveryCode($user, '  '.strtolower($codes[3]).' '))->toBeTrue()
        ->and($this->service->useRecoveryCode($user, $codes[3]))->toBeFalse()
        ->and($this->service->useRecoveryCode($user, 'NOPE0-NOPE0'))->toBeFalse()
        ->and($user->fresh()->two_factor_recovery_codes)->toHaveCount(7)
        ->and($this->service->useRecoveryCode($user->fresh(), $codes[0]))->toBeTrue();
})->group('phase0');

it('refuses to disable 2FA for roles that require it', function (RoleName $role) {
    $user = userWithRole($role, $this->service->generateSecret());

    expect(fn () => $this->service->disable($user, $user))->toThrow(LogicException::class);

    expect($user->fresh()->hasConfirmedTwoFactor())->toBeTrue()
        ->and(AuditLog::count())->toBe(0);
})->with([RoleName::ADMIN, RoleName::ACCOUNTANT])->group('phase0');

it('disables 2FA for an employee with an audit row', function () {
    $user = userWithRole(RoleName::EMPLOYEE, $this->service->generateSecret());

    $this->service->disable($user, $user);

    $user->refresh();
    $audit = AuditLog::sole();

    expect($user->two_factor_secret)->toBeNull()
        ->and($user->two_factor_recovery_codes)->toBeNull()
        ->and($user->two_factor_confirmed_at)->toBeNull()
        ->and($audit->event)->toBe('user.two_factor_disabled')
        ->and($audit->actor_id)->toBe($user->id)
        ->and($audit->target_id)->toBe($user->id);
})->group('phase0');

it('renders the QR code as inline SVG with the otpauth URL', function () {
    $user = userWithRole(RoleName::ADMIN);
    $secret = $this->service->generateSecret();

    $svg = $this->service->qrCodeSvg($user, $secret);
    $url = $this->service->otpauthUrl($user, $secret);

    expect($svg)->toStartWith('<svg')
        ->and($svg)->toContain('width="192"')
        ->and($url)->toStartWith('otpauth://totp/')
        ->and($url)->toContain('secret='.$secret)
        ->and($url)->toContain('issuer='.rawurlencode((string) config('app.name')))
        ->and($url)->toContain(rawurlencode($user->email));
})->group('phase0');
