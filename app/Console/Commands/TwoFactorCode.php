<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;
use PragmaRX\Google2FA\Google2FA;

/**
 * Prints the six-digit code a seeded user's authenticator app would be showing right now.
 *
 * Why this exists: the launcher scripts used to print the enrolment *secret*, which is the
 * wrong thing entirely — the sign-in screen asks for a six-digit number, and turning the
 * secret into one needs an authenticator app. Anyone testing the real 2FA path had to set
 * up Google Authenticator first, which is a lot of ceremony to click one button.
 *
 * This is a development convenience and refuses to run in production, where printing a
 * live second factor to a terminal would defeat the point of having one. Outside
 * production the seeded secret is a well-known test value already committed to the dev
 * environment, so nothing is being revealed that was secret.
 */
#[Signature('hq:two-factor-code {user? : Email or id (default: the first user who has 2FA set up)}')]
#[Description('Print the current six-digit two-factor code for a user (development only)')]
class TwoFactorCode extends Command
{
    public function handle(Google2FA $google2fa): int
    {
        if (App::isProduction()) {
            $this->components->error(
                'Refused: this prints a live second factor, which would defeat it. Not available in production.'
            );

            return self::FAILURE;
        }

        $argument = $this->argument('user');

        $user = $argument === null
            ? User::whereNotNull('two_factor_secret')->orderBy('id')->first()
            : User::where('email', $argument)->orWhere('id', is_numeric($argument) ? (int) $argument : 0)->first();

        if (! $user instanceof User) {
            $this->components->error(
                $argument === null
                    ? 'No user has two-factor set up yet. Seed the demo data, or name a user.'
                    : "No user matches [{$argument}]."
            );

            return self::FAILURE;
        }

        if ($user->two_factor_secret === null) {
            $this->components->error(
                "{$user->email} has no two-factor secret, so there is no code to print. "
                .'They sign in with a password alone.'
            );

            return self::FAILURE;
        }

        $code = $google2fa->getCurrentOtp($user->two_factor_secret);

        // A TOTP step is 30 seconds wide and aligned to the epoch, not to when you asked.
        // Saying how long this one lasts is the difference between a code that works and a
        // code that expired between reading it and typing it.
        $secondsLeft = 30 - (time() % 30);

        $this->newLine();
        // role() is a method returning a RoleName enum, not an Eloquent relationship —
        // $user->role would go looking for one and throw.
        $this->components->twoColumnDetail('<fg=white;options=bold>'.$user->email.'</>', $user->role()?->value ?? '');
        $this->components->twoColumnDetail(
            '<fg=green;options=bold>'.$code.'</>',
            $secondsLeft <= 5
                ? "<fg=yellow>expires in {$secondsLeft}s — wait for the next one</>"
                : "valid for {$secondsLeft}s"
        );
        $this->newLine();

        if (! $user->hasConfirmedTwoFactor()) {
            $this->components->warn(
                'This secret is not confirmed yet, so this code is for completing enrolment, not for signing in.'
            );
        }

        return self::SUCCESS;
    }
}
