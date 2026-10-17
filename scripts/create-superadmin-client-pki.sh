#!/usr/bin/env bash
# Execute ONLY on a trusted, offline administrative machine.
# Never run this script on the CECT web server or inside a repository checkout.
set -euo pipefail
umask 077
if [[ $# -ne 2 ]]; then
  echo "Usage: $0 /secure/offline/pki-directory client-identifier" >&2
  exit 2
fi
OUT="$1"
CLIENT="$2"
if [[ ! "$CLIENT" =~ ^[a-zA-Z0-9_-]{3,48}$ ]]; then
  echo 'Invalid client identifier.' >&2
  exit 2
fi
mkdir -p "$OUT"
cd "$OUT"
if [[ ! -f ca.crt ]]; then
  [[ ! -e ca.key ]] || { echo 'ca.key exists without ca.crt; stop and investigate.' >&2; exit 1; }
  openssl req -x509 -newkey rsa:4096 -sha256 -days 3650 -aes256 \
    -keyout ca.key -out ca.crt -subj '/CN=CECT SuperAdmin Client Root CA' \
    -addext 'basicConstraints=critical,CA:TRUE,pathlen:0' \
    -addext 'keyUsage=critical,keyCertSign,cRLSign'
  chmod 600 ca.key
fi
[[ ! -e "${CLIENT}.key" && ! -e "${CLIENT}.crt" && ! -e "${CLIENT}.p12" ]] || {
  echo 'Client identity already exists; choose a new identifier.' >&2; exit 1;
}
openssl req -new -newkey rsa:3072 -nodes \
  -keyout "${CLIENT}.key" -out "${CLIENT}.csr" \
  -subj "/CN=CECT SuperAdmin ${CLIENT}"
cat >"${CLIENT}.ext" <<'EOT'
basicConstraints=critical,CA:FALSE
keyUsage=critical,digitalSignature
extendedKeyUsage=clientAuth
subjectKeyIdentifier=hash
authorityKeyIdentifier=keyid,issuer
EOT
openssl x509 -req -in "${CLIENT}.csr" -CA ca.crt -CAkey ca.key \
  -CAcreateserial -out "${CLIENT}.crt" -days 365 -sha256 -extfile "${CLIENT}.ext"
openssl pkcs12 -export -inkey "${CLIENT}.key" -in "${CLIENT}.crt" -certfile ca.crt \
  -out "${CLIENT}.p12" -name "CECT SuperAdmin ${CLIENT}"
chmod 600 "${CLIENT}.key" "${CLIENT}.p12"
echo "Client certificate SHA-256 (DER, pin it on the API server):"
openssl x509 -in "${CLIENT}.crt" -outform DER | sha256sum
echo "Copy ONLY ca.crt to Nginx. Transfer the encrypted .p12 to the owner's browser securely."
echo "NEVER copy ca.key, the unencrypted client .key, or the P12 passphrase to GitHub or the web server."
