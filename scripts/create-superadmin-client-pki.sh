#!/usr/bin/env bash
# Run exclusively on a trusted OFFLINE administrative machine, never the web server.
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
mkdir -p newcerts
touch index.txt
[[ -f serial ]] || echo 1000 >serial
[[ -f crlnumber ]] || echo 1000 >crlnumber
cat >openssl-ca.cnf <<'EOT'
[ ca ]
default_ca = CA_default
[ CA_default ]
dir = .
database = $dir/index.txt
new_certs_dir = $dir/newcerts
certificate = $dir/ca.crt
private_key = $dir/ca.key
serial = $dir/serial
crlnumber = $dir/crlnumber
default_md = sha256
default_days = 365
default_crl_days = 7
policy = policy_common_name
unique_subject = no
email_in_dn = no
copy_extensions = none
[ policy_common_name ]
commonName = supplied
[ client_certificate ]
basicConstraints = critical,CA:FALSE
keyUsage = critical,digitalSignature
extendedKeyUsage = clientAuth
subjectKeyIdentifier = hash
authorityKeyIdentifier = keyid,issuer
EOT
[[ ! -e "${CLIENT}.key" && ! -e "${CLIENT}.crt" && ! -e "${CLIENT}.p12" ]] || {
  echo 'Client identity already exists; choose a new identifier.' >&2; exit 1;
}
openssl req -new -newkey rsa:3072 -nodes \
  -keyout "${CLIENT}.key" -out "${CLIENT}.csr" \
  -subj "/CN=CECT SuperAdmin ${CLIENT}"
openssl ca -batch -notext -config openssl-ca.cnf -extensions client_certificate \
  -in "${CLIENT}.csr" -out "${CLIENT}.crt"
openssl ca -gencrl -config openssl-ca.cnf -out clients.crl.pem
openssl verify -CAfile ca.crt -CRLfile clients.crl.pem -crl_check "${CLIENT}.crt"
openssl pkcs12 -export -inkey "${CLIENT}.key" -in "${CLIENT}.crt" -certfile ca.crt \
  -out "${CLIENT}.p12" -name "CECT SuperAdmin ${CLIENT}"
chmod 600 "${CLIENT}.key" "${CLIENT}.p12"
echo "Bootstrap client certificate SHA-256 (DER):"
openssl x509 -in "${CLIENT}.crt" -outform DER | sha256sum
echo "Publish only ca.crt AND clients.crl.pem to Nginx; update CRL at least every 7 days."
echo "To revoke: openssl ca -config openssl-ca.cnf -revoke CLIENT.crt && openssl ca -gencrl -config openssl-ca.cnf -out clients.crl.pem"
echo "Transfer the password-protected .p12 to the SuperAdmin's browser securely."
echo "Never publish ca.key, the client .key or any private key."
