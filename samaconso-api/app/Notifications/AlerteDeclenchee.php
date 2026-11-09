<?php

namespace App\Notifications;

use App\Models\Alerte;
use App\Services\Notifications\SmsChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Notification d'une alerte par email / SMS selon les préférences de l'abonné
 * (l'alerte elle-même est toujours consultable dans l'application : table `alertes`).
 * Les alertes critiques sont envoyées quelles que soient les préférences.
 */
class AlerteDeclenchee extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Alerte $alerte)
    {
    }

    public function via(object $notifiable): array
    {
        $prefs = $notifiable->preferences();
        $critique = $this->alerte->niveau === 'critique';

        $canaux = [];
        if ($critique || $prefs['email']) {
            $canaux[] = 'mail';
        }
        if (($critique || $prefs['sms']) && $notifiable->telephone) {
            $canaux[] = SmsChannel::class;
        }

        return $canaux;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('SamaConso — '.$this->alerte->type->label())
            ->greeting('Bonjour '.$notifiable->prenom.',')
            ->line($this->alerte->message)
            ->line('Alerte du '.$this->alerte->date_heure->format('d/m/Y à H:i').'.')
            ->action('Voir mes alertes', rtrim((string) config('services.frontend.url'), '/').'/alertes');
    }

    public function toSms(object $notifiable): string
    {
        return 'SamaConso : '.$this->alerte->message;
    }
}
