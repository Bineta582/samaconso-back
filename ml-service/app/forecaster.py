"""Prévision de la consommation électrique journalière (kWh) par Random Forest Regressor.

Principe
--------
* Série d'entrée : kWh consommés par jour (jours manquants interpolés si l'écart est court).
* Variables explicatives d'un jour J (toutes connues la veille, donc sans fuite d'information) :
  jour de la semaine (encodage cyclique), jour du mois, retards J-1, J-2, J-7, J-14,
  moyennes mobiles 7 et 28 jours, écart-type 7 jours.
* Prévision à N jours : récursive (la prédiction de J alimente les retards de J+1).
* Précision : validation « walk-forward » — on se place 3 fois dans le passé (-21, -14, -7 jours),
  on entraîne sur ce qui précède, on prévoit 7 jours et on compare au réel.
  precision = 1 - WAPE (somme des erreurs absolues / somme du réel), bornée à [0, 1].
* Intervalle : dispersion des arbres de la forêt, plancher = erreur WAPE observée (heuristique).
"""
from __future__ import annotations

import math
from dataclasses import dataclass

import numpy as np
import pandas as pd
from sklearn.ensemble import RandomForestRegressor

MODELE = "RandomForestRegressor"
MIN_JOURS_ENTRAINEMENT = 14
HORIZON_BACKTEST = 7
Z_80 = 1.2816  # quantile d'un intervalle à 80 %


@dataclass
class Resultat:
    modele: str
    jours_entraines: int
    precision: float | None
    metriques: dict
    previsions: dict[int, dict]
    avertissements: list[str]


def serie_journaliere(historique: list[tuple[str, float]]) -> pd.Series:
    """Série continue (un point par jour). Trous ≤ 7 jours interpolés, le reste rempli par la médiane."""
    s = pd.Series({pd.Timestamp(d): float(v) for d, v in historique}).sort_index()
    s = s[~s.index.duplicated(keep="last")]
    complete = s.reindex(pd.date_range(s.index.min(), s.index.max(), freq="D"))
    complete = complete.interpolate(limit=7, limit_direction="both").fillna(complete.median())
    return complete.clip(lower=0.0)


def _variables(valeurs: np.ndarray, date: pd.Timestamp) -> list[float]:
    """Vecteur de variables pour prévoir le jour `date`, à partir des valeurs des jours précédents."""
    n = len(valeurs)

    def retard(k: int) -> float:
        return float(valeurs[n - k]) if n - k >= 0 else float(valeurs[0])

    sept = valeurs[max(0, n - 7):]
    vingt_huit = valeurs[max(0, n - 28):]
    return [
        math.sin(2 * math.pi * date.dayofweek / 7),
        math.cos(2 * math.pi * date.dayofweek / 7),
        date.day / 31.0,
        retard(1), retard(2), retard(7), retard(14),
        float(np.mean(sept)),
        float(np.mean(vingt_huit)),
        float(np.std(sept)),
    ]


def _jeu_apprentissage(serie: pd.Series) -> tuple[np.ndarray, np.ndarray]:
    vals = serie.to_numpy()
    X, y = [], []
    for i in range(7, len(vals)):  # au moins une semaine de passé
        X.append(_variables(vals[:i], serie.index[i]))
        y.append(vals[i])
    return np.asarray(X), np.asarray(y)


def _entrainer(serie: pd.Series) -> RandomForestRegressor:
    X, y = _jeu_apprentissage(serie)
    modele = RandomForestRegressor(
        n_estimators=300, min_samples_leaf=2, max_features=0.8, random_state=42, n_jobs=-1
    )
    modele.fit(X, y)
    return modele


def _prevoir(modele: RandomForestRegressor, serie: pd.Series, jours: int) -> list[dict]:
    """Prévision récursive jour par jour, avec dispersion des arbres."""
    vals = list(serie.to_numpy())
    date = serie.index[-1]
    sortie = []
    for _ in range(jours):
        date = date + pd.Timedelta(days=1)
        x = np.asarray([_variables(np.asarray(vals), date)])
        par_arbre = np.asarray([arbre.predict(x)[0] for arbre in modele.estimators_])
        kwh = float(max(0.0, par_arbre.mean()))
        sortie.append({"date": date.date().isoformat(), "kwh": kwh, "ecart_type": float(par_arbre.std())})
        vals.append(kwh)
    return sortie


def _backtest(serie: pd.Series) -> tuple[float | None, dict]:
    """Walk-forward : retourne (précision, métriques) ou (None, {...}) si l'historique est trop court."""
    n = len(serie)
    coupures = [n - HORIZON_BACKTEST * k for k in (3, 2, 1) if n - HORIZON_BACKTEST * k >= MIN_JOURS_ENTRAINEMENT]
    if not coupures:
        return None, {"wape": None, "mae": None, "plis": 0}

    erreurs_abs, reel_total = 0.0, 0.0
    for c in coupures:
        modele = _entrainer(serie.iloc[:c])
        prevu = _prevoir(modele, serie.iloc[:c], HORIZON_BACKTEST)
        reel = serie.iloc[c:c + HORIZON_BACKTEST].to_numpy()
        p = np.asarray([x["kwh"] for x in prevu])[: len(reel)]
        erreurs_abs += float(np.abs(reel - p).sum())
        reel_total += float(reel.sum())

    wape = erreurs_abs / reel_total if reel_total > 0 else 1.0
    mae = erreurs_abs / (len(coupures) * HORIZON_BACKTEST)
    return max(0.0, min(1.0, 1.0 - wape)), {"wape": round(wape, 4), "mae": round(mae, 4), "plis": len(coupures)}


def prevoir(historique: list[tuple[str, float]], horizons: list[int]) -> Resultat:
    serie = serie_journaliere(historique)
    avertissements: list[str] = []

    if len(serie) < MIN_JOURS_ENTRAINEMENT:
        raise ValueError(f"Historique insuffisant : {len(serie)} jour(s), minimum {MIN_JOURS_ENTRAINEMENT}.")
    if len(serie) < 30:
        avertissements.append("Moins de 30 jours d'historique : prévision peu fiable (saisonnalité non apprise).")

    precision, metriques = _backtest(serie)
    if precision is None:
        avertissements.append("Historique trop court pour évaluer la précision.")

    modele = _entrainer(serie)
    plus_long = max(horizons)
    brut = _prevoir(modele, serie, plus_long)
    wape = metriques.get("wape") or 0.0

    previsions: dict[int, dict] = {}
    for h in sorted(set(horizons)):
        jours = []
        for i, j in enumerate(brut[:h]):
            # demi-largeur 80 % : dispersion des arbres, plancher = erreur observée ; s'élargit avec l'éloignement
            demi = max(Z_80 * j["ecart_type"], wape * j["kwh"]) * (1 + i / 60)
            jours.append({
                "date": j["date"], "kwh": round(j["kwh"], 3),
                "low": round(max(0.0, j["kwh"] - demi), 3), "high": round(j["kwh"] + demi, 3),
                "_demi": demi,
            })
        total = sum(j["kwh"] for j in jours)
        demi_total = math.sqrt(sum(j["_demi"] ** 2 for j in jours))  # somme en quadrature (approximation)
        previsions[h] = {
            "total_kwh": round(total, 3),
            "low_kwh": round(max(0.0, total - demi_total), 3),
            "high_kwh": round(total + demi_total, 3),
            "daily": [{k: v for k, v in j.items() if k != "_demi"} for j in jours],
        }

    return Resultat(MODELE, len(serie), None if precision is None else round(precision, 4),
                    metriques, previsions, avertissements)
