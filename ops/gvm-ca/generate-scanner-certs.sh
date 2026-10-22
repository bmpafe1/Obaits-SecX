#!/bin/bash
# Generate the TLS material a networked GVM scanner needs.
#
# WHY THIS EXISTS: gvmd's create_scanner requires host, port and a
# credential_id -- there is no unix-socket variant (confirmed against
# python-gvm 27.5.0, GMPv225). The built-in "OpenVAS Default" scanner at
# /run/ospd/ospd-openvas.sock is a gvmd internal and cannot be replicated, so
# every additional scanner in the pool must speak OSP over TLS with client
# certificate authentication.
#
# The GVM stack ships NO certificate authority. Confirmed 2026-08-25:
# /var/lib/gvm/CA and /var/lib/gvm/private/CA do not exist, and the only certs
# on the box are nginx's own web server pair. So the CA is created here.
#
# ACKNOWLEDGED LIMITATION: this is a private CA whose key sits on the same host
# as everything it signs, with no rotation, revocation or expiry handling. It is
# adequate to prove the scanner pool works and inadequate for production. The
# intended replacement is a dedicated CA service (step-ca or equivalent), which
# would slot in without changing anything downstream -- GVM only ever sees a
# credential and a ca_pub.
#
# Usage:  generate-scanner-certs.sh <scanner-name> [scanner-hostname]
set -euo pipefail

NAME="${1:?usage: generate-scanner-certs.sh <scanner-name> [hostname]}"
HOSTNAME_CN="${2:-$NAME}"
DIR="${GVM_CA_DIR:-/etc/obaits/gvm-ca}"
DAYS_CA=3650
DAYS_CERT=825          # browsers cap at 825; kept consistent for all leaf certs

mkdir -p "$DIR/scanners/$NAME"
cd "$DIR"

# --- Certificate authority. Created once, reused for every scanner.
if [ ! -f ca.key ]; then
  echo "Creating certificate authority..."
  openssl genrsa -out ca.key 4096 2>/dev/null
  chmod 600 ca.key
  openssl req -x509 -new -nodes -key ca.key -sha256 -days "$DAYS_CA" \
    -subj "/CN=Obaits GVM Scanner CA/O=Obaits" \
    -out ca.crt 2>/dev/null
  echo "  CA created at $DIR/ca.crt"
else
  echo "Using existing CA at $DIR/ca.crt"
fi

# --- Client certificate for gvmd. One is enough: gvmd is the only client, and
# --- every scanner trusts the same CA.
if [ ! -f client.key ]; then
  echo "Creating gvmd client certificate..."
  openssl genrsa -out client.key 4096 2>/dev/null
  chmod 600 client.key
  openssl req -new -key client.key -subj "/CN=gvmd/O=Obaits" -out client.csr 2>/dev/null
  openssl x509 -req -in client.csr -CA ca.crt -CAkey ca.key -CAcreateserial \
    -out client.crt -days "$DAYS_CERT" -sha256 2>/dev/null
  rm -f client.csr
  echo "  client cert created"
else
  echo "Using existing gvmd client certificate"
fi

# --- Server certificate, one per scanner. The CN must match the host gvmd
# --- connects to, or verification fails.
S="$DIR/scanners/$NAME"
echo "Creating server certificate for scanner '$NAME' (CN=$HOSTNAME_CN)..."
openssl genrsa -out "$S/server.key" 4096 2>/dev/null
chmod 600 "$S/server.key"
openssl req -new -key "$S/server.key" -subj "/CN=$HOSTNAME_CN/O=Obaits" -out "$S/server.csr" 2>/dev/null
openssl x509 -req -in "$S/server.csr" -CA ca.crt -CAkey ca.key -CAcreateserial \
  -out "$S/server.crt" -days "$DAYS_CERT" -sha256 2>/dev/null
rm -f "$S/server.csr"
cp ca.crt "$S/ca.crt"

# REAL BUG FOUND 2026-08-25 on the first pool member: ospd-openvas drops to
# uid 1001 (its own ospd-openvas user) before reading its key, so a 0600
# root-owned key produced "PermissionError: [Errno 13] Permission denied" at
# load_cert_chain. The container deliberately drops privileges after setting
# raw-packet capabilities, and running it as root instead would be the wrong
# trade for a process that will hold tenant network access.
#
# Owned by 1001:1001 rather than made world-readable: this is a private key.
OSPD_UID="${OSPD_UID:-1001}"
chown -R "$OSPD_UID:$OSPD_UID" "$S"
chmod 600 "$S/server.key"
chmod 644 "$S/server.crt" "$S/ca.crt"

echo
echo "Done. Scanner '$NAME':"
echo "  server cert : $S/server.crt"
echo "  server key  : $S/server.key"
echo "  CA cert     : $S/ca.crt"
echo
echo "gvmd client credential (register these with create_credential):"
echo "  certificate : $DIR/client.crt"
echo "  private key : $DIR/client.key"
echo "  ca_pub      : $DIR/ca.crt"
