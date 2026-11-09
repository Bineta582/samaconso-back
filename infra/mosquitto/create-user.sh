#!/usr/bin/env bash
# Crée / met à jour un utilisateur MQTT (backend ou capteur) dans le fichier passwd.
# Usage : ./create-user.sh samaconso-backend 'mot-de-passe'
#         ./create-user.sh esp32-awa-pzem 'mot-de-passe-du-capteur'
set -euo pipefail
cd "$(dirname "$0")"
touch passwd
docker run --rm -v "$PWD:/m" eclipse-mosquitto:2 mosquitto_passwd -b /m/passwd "$1" "$2"
echo "Utilisateur « $1 » enregistré. Redémarrer le broker : docker compose restart mosquitto"
