<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #12201B; }
        h1 { font-size: 20px; margin-bottom: 0; }
        .ref { color: #6B7570; margin-bottom: 18px; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        td { padding: 8px 4px; border-bottom: 1px solid #e5e5e5; }
        td:last-child { text-align: right; font-weight: bold; }
        .total td { border-top: 2px solid #12201B; font-size: 14px; }
        .code { margin-top: 20px; padding: 12px; background: #FFF4DC; font-size: 16px; letter-spacing: 1px; text-align: center; }
        .note { color: #6B7570; font-size: 10px; margin-top: 16px; }
    </style>
</head>
<body>
    <h1>Reçu de recharge Woyofal</h1>
    <div class="ref">Référence {{ $p->reference }} · {{ ($p->paye_le ?? $p->created_at)->format('d/m/Y à H:i') }}</div>

    <table>
        <tr><td>Abonné</td><td>{{ $p->abonne->user->nomComplet() }}</td></tr>
        <tr><td>Compteur</td><td>{{ $p->compteur->numero_compteur }}</td></tr>
        <tr><td>Moyen de paiement</td><td>{{ $p->moyen->label() }}</td></tr>
        <tr><td>Montant du crédit</td><td>{{ number_format($p->montant_fcfa, 0, ',', ' ') }} FCFA</td></tr>
        <tr><td>Frais</td><td>{{ number_format($p->frais_fcfa, 0, ',', ' ') }} FCFA</td></tr>
        <tr><td>Énergie estimée</td><td>≈ {{ number_format($p->kwh_estimes, 1, ',', ' ') }} kWh</td></tr>
        <tr class="total"><td>Total payé</td><td>{{ number_format($p->total(), 0, ',', ' ') }} FCFA</td></tr>
    </table>

    @if ($p->code_recharge)
        <div class="code">Code de recharge : {{ $p->code_recharge }}</div>
    @endif

    <div class="note">Document généré par SamaConso. La quantité d'énergie est une estimation selon la grille tarifaire en vigueur.</div>
</body>
</html>
