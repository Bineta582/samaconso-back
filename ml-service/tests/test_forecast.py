import math

import numpy as np
import pandas as pd
import pytest
from fastapi.testclient import TestClient

from app.forecaster import MIN_JOURS_ENTRAINEMENT, prevoir, serie_journaliere
from app.main import app

client = TestClient(app)


def historique_synthetique(jours: int, fin: str = "2026-09-30", bruit: float = 0.05, seed: int = 1):
    """Consommation réaliste : base + pic le week-end + légère tendance + bruit."""
    rng = np.random.default_rng(seed)
    dates = pd.date_range(end=fin, periods=jours, freq="D")
    kwh = [8.0 + (2.5 if d.dayofweek >= 5 else 0) + 0.01 * i + rng.normal(0, bruit * 8) for i, d in enumerate(dates)]
    return [(d.date().isoformat(), max(0.5, float(v))) for d, v in zip(dates, kwh)]


def test_prevision_et_precision_sur_serie_reguliere():
    r = prevoir(historique_synthetique(90), [7, 30])

    assert r.modele == "RandomForestRegressor"
    assert r.precision is not None and r.precision > 0.85          # série régulière → bonne précision
    assert len(r.previsions[7]["daily"]) == 7
    assert len(r.previsions[30]["daily"]) == 30
    assert r.previsions[7]["daily"][0]["date"] == "2026-10-01"      # lendemain du dernier jour connu
    # une semaine ≈ 7 × ~9,5 kWh
    assert 55 < r.previsions[7]["total_kwh"] < 85


def test_le_week_end_est_plus_eleve():
    r = prevoir(historique_synthetique(90), [7])
    jours = {pd.Timestamp(j["date"]).dayofweek: j["kwh"] for j in r.previsions[7]["daily"]}
    assert jours[5] > jours[2] and jours[6] > jours[2]              # samedi, dimanche > mercredi


def test_intervalle_encadre_la_prevision_et_s_elargit():
    r = prevoir(historique_synthetique(90), [30])
    f = r.previsions[30]
    assert f["low_kwh"] <= f["total_kwh"] <= f["high_kwh"]
    premier, dernier = f["daily"][0], f["daily"][-1]
    assert (dernier["high"] - dernier["low"]) >= (premier["high"] - premier["low"])
    assert all(j["low"] >= 0 for j in f["daily"])


def test_determinisme():
    h = historique_synthetique(60)
    assert prevoir(h, [7]).previsions[7]["total_kwh"] == prevoir(h, [7]).previsions[7]["total_kwh"]


def test_historique_court_donne_avertissement_sans_precision():
    r = prevoir(historique_synthetique(16), [7])
    assert r.precision is None
    assert r.avertissements


def test_historique_insuffisant_refuse():
    with pytest.raises(ValueError):
        prevoir(historique_synthetique(MIN_JOURS_ENTRAINEMENT - 1), [7])


def test_trous_interpoles():
    h = historique_synthetique(60)
    h = h[:20] + h[23:]                                              # 3 jours manquants
    s = serie_journaliere(h)
    assert len(s) == 60 and not s.isna().any()


def test_api_forecast_contrat():
    h = [{"date": d, "kwh": v} for d, v in historique_synthetique(60)]
    rep = client.post("/v1/forecast", json={"history": h, "horizons": [7, 30]})
    assert rep.status_code == 200
    corps = rep.json()
    assert set(corps["forecasts"]) == {"7", "30"}                    # clés JSON en chaînes
    assert {"model", "precision", "metrics", "trained_on_days", "forecasts"} <= set(corps)
    assert {"total_kwh", "low_kwh", "high_kwh", "daily"} <= set(corps["forecasts"]["7"])
    assert math.isclose(corps["forecasts"]["7"]["total_kwh"], sum(j["kwh"] for j in corps["forecasts"]["7"]["daily"]), abs_tol=0.01)


def test_api_validation():
    h = [{"date": d, "kwh": v} for d, v in historique_synthetique(60)]
    assert client.post("/v1/forecast", json={"history": h[:5], "horizons": [7]}).status_code == 422
    assert client.post("/v1/forecast", json={"history": h, "horizons": [0]}).status_code == 422
    assert client.post("/v1/forecast", json={"history": h, "horizons": [365]}).status_code == 422
    assert client.post("/v1/forecast", json={"history": [{"date": "x", "kwh": 1}] * 20}).status_code == 422


def test_api_jeton(monkeypatch):
    monkeypatch.setenv("ML_SERVICE_TOKEN", "secret")
    h = [{"date": d, "kwh": v} for d, v in historique_synthetique(30)]
    assert client.post("/v1/forecast", json={"history": h, "horizons": [7]}).status_code == 401
    ok = client.post("/v1/forecast", json={"history": h, "horizons": [7]}, headers={"Authorization": "Bearer secret"})
    assert ok.status_code == 200
