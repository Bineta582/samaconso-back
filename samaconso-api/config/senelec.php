<?php

/*
|--------------------------------------------------------------------------
| Grille tarifaire SENELEC — prépaiement (WOYOFAL)
|--------------------------------------------------------------------------
| Source : CRSE, « Grilles tarifaires Senelec et CER applicables à compter du
| 1er janvier 2026 » (décision n°2025-140), document daté du 26/12/2025.
| Prix de l'énergie en FCFA/kWh, redevance d'électrification rurale (0,7 F/kWh)
| incluse. Les tranches sont cumulées PAR MOIS.
|
| Note officielle : « pour les besoins de la facturation au prépaiement, la
| quantité consommée sur la troisième tranche est valorisée au tarif de la
| deuxième tranche » → la 3e tranche a donc le même prix que la 2e.
|
| ⚠ À re-vérifier à chaque évolution tarifaire (CRSE) : modifier uniquement ce
| fichier — aucun tarif n'est codé ailleurs dans l'application.
*/
return [
    'devise' => 'FCFA',
    'en_vigueur_depuis' => '2026-01-01',

    // Catégorie tarifaire selon le type d'abonnement :
    //  menage             → DPP (Domestique Petite Puissance)
    //  petite_entreprise  → PPP (Professionnel Petite Puissance)
    'tarifs' => [
        'DPP' => [
            ['jusqua' => 150, 'prix' => 82.00],
            ['jusqua' => 250, 'prix' => 136.49],
            ['jusqua' => null, 'prix' => 136.49],
        ],
        'PPP' => [
            ['jusqua' => 50, 'prix' => 147.43],
            ['jusqua' => 500, 'prix' => 189.84],
            ['jusqua' => null, 'prix' => 189.84],
        ],
    ],

    // Recharge Woyofal
    'recharge_min_fcfa' => (int) env('RECHARGE_MIN_FCFA', 500),
    'recharge_max_fcfa' => (int) env('RECHARGE_MAX_FCFA', 500000),

    // Frais du prestataire de paiement en % du montant (à adapter à chaque contrat).
    'frais_paiement_pct' => [
        'orange_money' => (float) env('FRAIS_ORANGE_MONEY_PCT', 0),
        'wave' => (float) env('FRAIS_WAVE_PCT', 0),
        'free_money' => (float) env('FRAIS_FREE_MONEY_PCT', 0),
        'carte' => (float) env('FRAIS_CARTE_PCT', 0),
    ],

    // Objectif mensuel par défaut (kWh) quand le compteur n'en définit pas.
    'objectif_mensuel_kwh_defaut' => 300,

    // Rétention des mesures brutes (jours) avant purge. Les agrégats horaires restent.
    'retention_mesures_jours' => (int) env('RETENTION_MESURES_JOURS', 30),

    // Un capteur sans nouvelle mesure depuis N minutes est considéré hors ligne.
    'capteur_hors_ligne_minutes' => (int) env('CAPTEUR_HORS_LIGNE_MINUTES', 10),
];
