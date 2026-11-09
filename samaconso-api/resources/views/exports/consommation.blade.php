<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #12201B; }
        h1 { font-size: 18px; margin-bottom: 2px; }
        .sous-titre { color: #6B7570; margin-bottom: 14px; }
        .kpis { margin-bottom: 14px; }
        .kpi { display: inline-block; margin-right: 24px; }
        .kpi b { font-size: 15px; display: block; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: right; font-size: 9px; color: #6B7570; border-bottom: 1px solid #ccc; padding: 5px; }
        td { text-align: right; padding: 4px 5px; border-bottom: 1px solid #eee; }
        th:first-child, td:first-child { text-align: left; }
    </style>
</head>
<body>
    <h1>SamaConso — Rapport de consommation</h1>
    <div class="sous-titre">
        Compteur {{ $compteur->numero_compteur }} · du {{ $du->format('d/m/Y') }} au {{ $au->format('d/m/Y') }}
    </div>
    <div class="kpis">
        <div class="kpi"><b>{{ number_format($total_kwh, 2, ',', ' ') }} kWh</b>Énergie consommée</div>
        <div class="kpi"><b>{{ number_format($cout_fcfa, 0, ',', ' ') }} FCFA</b>Coût estimé (grille SENELEC)</div>
    </div>
    <table>
        <thead>
            <tr><th>Heure</th><th>Énergie (kWh)</th><th>Puissance moy. (W)</th><th>Tension moy. (V)</th><th>Courant moy. (A)</th></tr>
        </thead>
        <tbody>
        @foreach ($lignes as $l)
            <tr>
                <td>{{ $l['heure'] }}</td>
                <td>{{ number_format($l['energie_kwh'], 3, ',', ' ') }}</td>
                <td>{{ number_format($l['puissance_moy_w'], 0, ',', ' ') }}</td>
                <td>{{ number_format($l['tension_moy_v'], 1, ',', ' ') }}</td>
                <td>{{ number_format($l['courant_moy_a'], 2, ',', ' ') }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
</body>
</html>
