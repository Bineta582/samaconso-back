#!/usr/bin/env bash
# Génère une autorité de certification privée et un certificat serveur pour le broker (TLS).
# Usage : ./gen-certs.sh broker.exemple.sn      (le nom doit correspondre à MQTT_HOST)
# Le fichier certs/ca.crt est à embarquer dans le firmware des ESP32 et dans MQTT_TLS_CA_FILE.
set -euo pipefail
cd "$(dirname "$0")"
HOST="${1:-localhost}"
mkdir -p certs && cd certs

openssl genrsa -out ca.key 4096
openssl req -x509 -new -nodes -key ca.key -sha256 -days 1825 -subj "/CN=SamaConso-CA" -out ca.crt

openssl genrsa -out server.key 2048
openssl req -new -key server.key -subj "/CN=${HOST}" -out server.csr
printf "subjectAltName=DNS:%s,DNS:localhost,IP:127.0.0.1\n" "$HOST" > san.ext
openssl x509 -req -in server.csr -CA ca.crt -CAkey ca.key -CAcreateserial -out server.crt -days 825 -sha256 -extfile san.ext

rm -f server.csr san.ext
chmod 600 ca.key server.key
echo "Certificats générés dans infra/mosquitto/certs (ne JAMAIS versionner ca.key / server.key)."
