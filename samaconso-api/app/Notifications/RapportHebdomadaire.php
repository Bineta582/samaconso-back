<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RapportHebdomadaire extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param array{kwh: float, cout: float, variation_pct: float|null, top: string|null, conseil: string|null} $r */
    public function __construct(private readonly string $numeroCompteur, private readonly array $r)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('SamaConso — Votre bilan de la semaine')
            ->greeting('Bonjour '.$notifiable->prenom.',')
            ->line(sprintf('Compteur %s : %.1f kWh consommés la semaine dernière, soit environ %s FCFA.',
                $this->numeroCompteur, $this->r['kwh'], number_format($this->r['cout'], 0, ',', ' ')));

        if ($this->r['variation_pct'] !== null) {
            $mail->line(sprintf('%s%.0f%% par rapport à la semaine précédente.', $this->r['variation_pct'] >= 0 ? '+' : '', $this->r['variation_pct']));
        }
        if ($this->r['top']) {
            $mail->line('Équipement le plus consommateur : '.$this->r['top'].'.');
        }
        if ($this->r['conseil'] && ($notifiable->preferences()['recommandations'] ?? true)) {
            $mail->line('Conseil : '.$this->r['conseil']);
        }

        return $mail->action('Ouvrir mon tableau de bord', rtrim((string) config('services.frontend.url'), '/').'/tableau-de-bord');
    }
}
