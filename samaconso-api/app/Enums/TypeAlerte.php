<?php

namespace App\Enums;

/**
 * Types d'alertes. Les mêmes valeurs servent de clé aux seuils configurables
 * (table seuils_alerte) : la colonne `valeur` s'exprime dans l'unité() du type.
 */
enum TypeAlerte: string
{
    case PicConsommation = 'pic_consommation';          // seuil : puissance max (W)
    case TensionHaute = 'tension_haute';                // seuil : tension max (V)
    case TensionBasse = 'tension_basse';                // seuil : tension min (V)
    case ConsommationJournaliere = 'consommation_journaliere'; // seuil : kWh / jour
    case DepenseJournaliere = 'depense_journaliere';    // seuil : FCFA / jour
    case DureeEquipement = 'duree_equipement';          // seuil : minutes de fonctionnement continu
    case CapteurHorsLigne = 'capteur_hors_ligne';       // seuil : minutes sans mesure
    case CreditBas = 'credit_bas';                      // seuil : jours d'autonomie restants
    case Anomalie = 'anomalie';                         // détection automatique (pas de seuil)

    public function unite(): ?string
    {
        return match ($this) {
            self::PicConsommation => 'W',
            self::TensionHaute, self::TensionBasse => 'V',
            self::ConsommationJournaliere => 'kWh',
            self::DepenseJournaliere => 'FCFA',
            self::DureeEquipement, self::CapteurHorsLigne => 'min',
            self::CreditBas => 'jours',
            self::Anomalie => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::PicConsommation => 'Pic de consommation',
            self::TensionHaute => 'Tension anormale (haute)',
            self::TensionBasse => 'Tension anormale (basse)',
            self::ConsommationJournaliere => 'Consommation élevée',
            self::DepenseJournaliere => 'Dépense élevée',
            self::DureeEquipement => 'Équipement allumé trop longtemps',
            self::CapteurHorsLigne => 'Capteur hors ligne',
            self::CreditBas => 'Crédit Woyofal bas',
            self::Anomalie => 'Comportement atypique',
        };
    }

    public function niveau(): string
    {
        return match ($this) {
            self::PicConsommation, self::TensionHaute, self::TensionBasse, self::CreditBas => 'critique',
            self::Anomalie, self::ConsommationJournaliere, self::DepenseJournaliere, self::DureeEquipement => 'warning',
            self::CapteurHorsLigne => 'info',
        };
    }

    /** Valeurs par défaut proposées à l'inscription (cf. page « Seuils d'alerte »). */
    public static function seuilsParDefaut(): array
    {
        return [
            self::PicConsommation->value => 1800,
            self::TensionHaute->value => 240,
            self::TensionBasse->value => 210,
            self::ConsommationJournaliere->value => 10,
            self::CapteurHorsLigne->value => 60,
            self::CreditBas->value => 2,
        ];
    }
}
