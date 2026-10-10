#!/bin/bash
# plMail mailstore entrypoint: builds the user database from MAIL_ACCOUNTS
# ("user1:pass1,user2:pass2" — logins are user@MAIL_DOMAIN) and runs Dovecot.
set -euo pipefail

MAIL_DOMAIN="${MAIL_DOMAIN:-example.com}"
echo "== plMail mailstore: domain=${MAIL_DOMAIN}"
if [ "$MAIL_DOMAIN" = "example.com" ]; then
  echo "!! MAIL_DOMAIN is not set: put it in .env.mail.local (see .env.mail.dist)"
fi

# --- TLS cert for public IMAP (port 993) --------------------------------------
# Mounted real certs win (MAIL_CERTS_PATH -> /certs). Otherwise a self-signed
# one is generated so Dovecot still starts — external clients will warn about
# it, which is the nudge to mount the real cert.
TLS_CERT="${TLS_CERT:-/certs/fullchain.pem}"
TLS_KEY="${TLS_KEY:-/certs/privkey.pem}"
mkdir -p /etc/dovecot/tls
if [ -f "$TLS_CERT" ] && [ -f "$TLS_KEY" ]; then
  echo "-- using mounted certificates for IMAPS"
  cp "$TLS_CERT" /etc/dovecot/tls/cert.pem
  cp "$TLS_KEY" /etc/dovecot/tls/key.pem
else
  echo "-- no certs mounted at /certs, generating a self-signed one for IMAPS"
  openssl req -x509 -newkey rsa:2048 -nodes -days 825 \
    -subj "/CN=${MAIL_DOMAIN}" \
    -keyout /etc/dovecot/tls/key.pem -out /etc/dovecot/tls/cert.pem 2>/dev/null
fi
chmod 600 /etc/dovecot/tls/key.pem
chmod 644 /etc/dovecot/tls/cert.pem

mkdir -p /var/mail/vhosts /run/dovecot
: > /etc/dovecot/users
COUNT=0
IFS=',' read -ra PAIRS <<< "${MAIL_ACCOUNTS:-}" || true
for pair in "${PAIRS[@]}"; do
  user="${pair%%:*}"
  pass="${pair#*:}"
  if [ -z "$user" ] || [ "$pass" = "$pair" ]; then
    continue
  fi
  addr="${user}@${MAIL_DOMAIN}"
  hash="$(doveadm pw -s SHA512-CRYPT -p "$pass")"
  printf '%s:%s::::::\n' "$addr" "$hash" >> /etc/dovecot/users
  mkdir -p "/var/mail/vhosts/${MAIL_DOMAIN}/${user}"
  echo "  mailbox: $addr"
  COUNT=$((COUNT + 1))
done
chown -R 5000:5000 /var/mail/vhosts
chmod 600 /etc/dovecot/users

# SMTP keys are written by plMail (Settings -> SMTP keys) into the shared
# volume. Make sure the file exists, or every lookup that falls through to
# that passdb logs an error and fails as "temporary".
mkdir -p /etc/plmail-mail-config
touch /etc/plmail-mail-config/smtp_keys
echo "-- ${COUNT} mailbox(es) provisioned"

exec dovecot -F
