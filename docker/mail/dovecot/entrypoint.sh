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
# Mailboxes plMail adds later (a second passdb, read on every login).
touch /etc/plmail-mail-config/mailboxes
mkdir -p "/var/mail/vhosts/${MAIL_DOMAIN}"
chown 5000:5000 "/var/mail/vhosts/${MAIL_DOMAIN}"
APP_MAILBOXES=$(grep -c ':' /etc/plmail-mail-config/mailboxes || true)
echo "-- ${APP_MAILBOXES} mailbox(es) added from plMail"
echo "-- ${COUNT} mailbox(es) provisioned"

# --- BearerMail: block addresses that keep failing to sign in --------------
# Dovecot asks the app before every login (auth policy). The app counts
# wrong passwords on the public port (993) and refuses an address that has
# too many; Admin -> Security shows the blocked ones with Unblock. The app's
# own logins (143, Postfix) are never counted.
# The shared key lives on the mail-config volume, which the app reads too.
# If the app is down or the key is wrong, logins go through as before
# (auth_policy_reject_on_fail = no): it can switch blocking off, never lock out.
POLICY_CONF=/etc/dovecot/auth-policy.conf
: > "$POLICY_CONF"
if [ "${MAIL_LOGIN_GUARD:-on}" != "off" ]; then
  KEY_FILE=/etc/plmail-mail-config/auth_policy_key
  if [ ! -s "$KEY_FILE" ]; then
    openssl rand -hex 32 > "$KEY_FILE"
  fi
  chmod 644 "$KEY_FILE"
  POLICY_KEY="$(tr -d '[:space:]' < "$KEY_FILE")"
  cat > "$POLICY_CONF" <<CONF
auth_policy_server_url = ${MAIL_LOGIN_GUARD_URL:-http://php/internal/mail-auth-policy}
auth_policy_server_api_header = X-Mail-Policy-Key: ${POLICY_KEY}
auth_policy_server_timeout_msecs = 2000
auth_policy_hash_nonce = ${POLICY_KEY}
auth_policy_request_attributes = login=%{requested_username} remote=%{rip} lport=%{lport} protocol=%s
auth_policy_reject_on_fail = no
auth_policy_check_before_auth = yes
auth_policy_check_after_auth = no
auth_policy_report_after_auth = yes
CONF
  echo "-- failed-login blocking: on (Admin -> Security)"
else
  echo "-- failed-login blocking: off (MAIL_LOGIN_GUARD=off)"
fi
chmod 600 "$POLICY_CONF"

exec dovecot -F
