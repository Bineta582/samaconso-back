"""Microservice de prédiction SamaConso (FastAPI).

Lancer :  uvicorn app.main:app --host 0.0.0.0 --port 8001
Contrat : POST /v1/forecast  (consommé par App\\Services\\PredictionService côté Laravel)
"""
from __future__ import annotations

import hmac
import os
from datetime import date

from fastapi import Depends, FastAPI, Header, HTTPException
from pydantic import BaseModel, Field, field_validator

from app.forecaster import prevoir

app = FastAPI(title="SamaConso — service de prédiction", version="1.0.0")


def verifier_jeton(authorization: str | None = Header(default=None)) -> None:
    """Si ML_SERVICE_TOKEN est défini, exige « Authorization: Bearer <jeton> » (comparaison à temps constant)."""
    attendu = os.getenv("ML_SERVICE_TOKEN")
    if not attendu:
        return
    fourni = (authorization or "").removeprefix("Bearer ").strip()
    if not hmac.compare_digest(fourni, attendu):
        raise HTTPException(status_code=401, detail="Jeton invalide")


class Point(BaseModel):
    date: date
    kwh: float = Field(ge=0, le=100_000)


class Requete(BaseModel):
    history: list[Point] = Field(min_length=14, max_length=1000)
    horizons: list[int] = Field(default_factory=lambda: [7, 30], min_length=1, max_length=5)

    @field_validator("horizons")
    @classmethod
    def horizons_valides(cls, v: list[int]) -> list[int]:
        if any(h < 1 or h > 90 for h in v):
            raise ValueError("chaque horizon doit être compris entre 1 et 90 jours")
        return v


@app.get("/health")
def health() -> dict:
    return {"status": "ok"}


@app.post("/v1/forecast", dependencies=[Depends(verifier_jeton)])
def forecast(req: Requete) -> dict:
    try:
        r = prevoir([(p.date.isoformat(), p.kwh) for p in req.history], req.horizons)
    except ValueError as e:
        raise HTTPException(status_code=422, detail=str(e)) from e

    return {
        "model": r.modele,
        "trained_on_days": r.jours_entraines,
        "precision": r.precision,
        "metrics": r.metriques,
        "warnings": r.avertissements,
        # clés d'objet JSON = chaînes ("7", "30")
        "forecasts": {str(h): f for h, f in r.previsions.items()},
    }
