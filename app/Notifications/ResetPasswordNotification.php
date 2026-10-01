<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * The emailed password-reset link, sent through Laravel's own mailer (whatever MAIL_MAILER is).
 */
class ResetPasswordNotification extends ResetPassword implements ShouldQueue
{
    use Queueable;

    /**
     * @param  mixed  $notifiable
     */
    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Reset your goodERP password')
            ->greeting('Hello '.$notifiable->name.',')
            ->line('Somebody (hopefully you) asked to reset the password for '.$notifiable->getEmailForPasswordReset().' on goodERP.')
            ->action('Reset password', url(route('password.reset', [
                'token' => $this->token,
                'email' => $notifiable->getEmailForPasswordReset(),
            ], false)))
            ->line('This link works for 60 minutes and only once.')
            ->line('If you did not ask for this, ignore this email — your password stays as it is.');
    }
}
