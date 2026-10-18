#!/bin/bash
# plMail MTA entrypoint: renders config from env, provisions mailboxes and
# DKIM keys, then runs OpenDKIM + Postfix.
set -euo pipefail

MAIL_DOMAIN="${MAIL_DOMAIN:-example.com}"
MAIL_HELO="${MAIL_HELO:-mail.${MAIL_DOMAIN}}"
MAILSTORE_HOST="${MAILSTORE_HOST:-mailstore}"
# MAIL_RELAYHOST is the name in .env.mail.local; RELAYHOST is accepted too.
RELAYHOST="${RELAYHOST:-${MAIL_RELAYHOST:-}}"
MAIL_RELAY_USER="${MAIL_RELAY_USER:-}"
MAIL_RELAY_PASS="${MAIL_RELAY_PASS:-}"
DKIM_SELECTOR="${DKIM_SELECTOR:-mail}"

echo "== plMail MTA: domain=${MAIL_DOMAIN} helo=${MAIL_HELO} relay=${RELAYHOST:-<direct>}"
if [ "$MAIL_DOMAIN" = "example.com" ]; then
  echo "!! MAIL_DOMAIN is not set: put it in .env.mail.local (see .env.mail.dist)"
fi

# --- TLS: mounted real certs win, else a self-signed one ---------------------
# Port 25 is opportunistic (remote MXs don't verify), so self-signed is fine
# there. If you publish 587 to the outside world, mount real certs instead.
# Postfix logs to stdout (see maillog_file in main.cf.tpl) — no syslog here.

TLS_CERT="${TLS_CERT:-/certs/fullchain.pem}"
TLS_KEY="${TLS_KEY:-/certs/privkey.pem}"
if [ ! -f "$TLS_CERT" ] || [ ! -f "$TLS_KEY" ]; then
  echo "-- no certs mounted at /certs, generating a self-signed one"
  TLS_CERT="/etc/postfix/cert.pem"
  TLS_KEY="/etc/postfix/key.pem"
  openssl req -x509 -newkey rsa:2048 -nodes -days 825 \
    -subj "/CN=${MAIL_HELO}" -keyout "$TLS_KEY" -out "$TLS_CERT" 2>/dev/null
  chmod 600 "$TLS_KEY"
else
  echo "-- using mounted certificates"
fi
export TLS_CERT TLS_KEY

# --- mailboxes -> Postfix virtual map ----------------------------------------
# MAIL_ACCOUNTS="user1:pass1,user2:pass2"  (passwords live in the mailstore too)
# plMail adds mailboxes after start-up to /etc/plmail-mail-config/mailboxes
# ("user@domain:{SHA512-CRYPT}...", Dovecot passwd-file format); they are
# merged in here and again by the watcher below whenever the file changes.
PLMAIL_MAILBOXES="/etc/plmail-mail-config/mailboxes"
FIRST=""
IFS=',' read -ra PAIRS <<< "${MAIL_ACCOUNTS:-}" || true
for pair in "${PAIRS[@]}"; do
  user="${pair%%:*}"
  [ -n "$user" ] || continue
  if [ -z "$FIRST" ]; then FIRST="$user"; fi
done
build_mailboxes() {
  : > /etc/postfix/virtual
  local pair user addr
  for pair in "${PAIRS[@]}"; do
    user="${pair%%:*}"
    [ -n "$user" ] || continue
    echo "${user}@${MAIL_DOMAIN} ${MAIL_DOMAIN}/${user}/" >> /etc/postfix/virtual
  done
  if [ -f "$PLMAIL_MAILBOXES" ]; then
    while IFS=':' read -r addr _; do
      addr="$(echo "${addr:-}" | tr 'A-Z' 'a-z' | tr -d '[:space:]')"
      case "$addr" in *"@${MAIL_DOMAIN}") ;; *) continue ;; esac
      grep -qi "^${addr} " /etc/postfix/virtual && continue
      echo "${addr} ${MAIL_DOMAIN}/${addr%@*}/" >> /etc/postfix/virtual
    done < "$PLMAIL_MAILBOXES"
  fi
}
mkdir -p /etc/plmail-mail-config
touch "$PLMAIL_MAILBOXES"
build_mailboxes
# --- aliases -----------------------------------------------------------------
# Every address that lands in an existing mailbox, rewritten through Postfix's
# ALIAS map — not the mailbox map, whose entries reach Dovecot over LMTP under
# the alias name, which is no Dovecot login and was refused there. Three
# sources, rebuilt together (and again whenever plMail changes its file):
#   1. postmaster@ and abuse@ (RFC-required) -> the first mailbox
#   2. MAIL_ALIASES="sales:support,billing:support"  (alias:mailbox local parts)
#   3. /etc/plmail-mail-config/aliases, written by plMail from
#      Settings -> Aliases: "alias@domain mailbox@domain" per line
# Each alias's mailbox may also SEND as it (see sender_logins below).
PLMAIL_ALIASES="/etc/plmail-mail-config/aliases"
is_mailbox() { grep -qi "^$1 " /etc/postfix/virtual; }
add_alias() {  # $1 alias address, $2 mailbox address, $3 where it came from
  local alias_addr target_addr
  alias_addr="$(echo "$1" | tr 'A-Z' 'a-z')"
  target_addr="$(echo "$2" | tr 'A-Z' 'a-z')"
  case "$alias_addr" in *"@${MAIL_DOMAIN}") ;; *) return ;; esac
  is_mailbox "$alias_addr" && return   # already a real mailbox
  if ! is_mailbox "$target_addr"; then
    echo "!! alias ${alias_addr} ($3) points at ${target_addr}, which is not in MAIL_ACCOUNTS; skipped"
    return
  fi
  grep -qi "^${alias_addr} " /etc/postfix/virtual_alias && return
  echo "${alias_addr} ${target_addr}" >> /etc/postfix/virtual_alias
}
build_aliases() {
  : > /etc/postfix/virtual_alias
  if [ -n "$FIRST" ]; then
    for role in postmaster abuse; do
      add_alias "${role}@${MAIL_DOMAIN}" "${FIRST}@${MAIL_DOMAIN}" "role"
    done
  fi
  local apair alias_lp target_lp
  IFS=',' read -ra ALIAS_PAIRS <<< "${MAIL_ALIASES:-}" || true
  for apair in "${ALIAS_PAIRS[@]}"; do
    alias_lp="$(echo "${apair%%:*}" | tr -d '[:space:]')"
    target_lp="$(echo "${apair#*:}" | tr -d '[:space:]')"
    if [ -z "$alias_lp" ] || [ "$apair" = "$alias_lp" ] || [ -z "$target_lp" ]; then
      continue
    fi
    add_alias "${alias_lp}@${MAIL_DOMAIN}" "${target_lp}@${MAIL_DOMAIN}" "MAIL_ALIASES"
  done
  if [ -f "$PLMAIL_ALIASES" ]; then
    while read -r from to _; do
      [ -n "${from:-}" ] && [ -n "${to:-}" ] && add_alias "$from" "$to" "plMail"
    done < "$PLMAIL_ALIASES"
  fi
  # The same pairs, for who may send as whom.
  ALIAS_LINES="$(cat /etc/postfix/virtual_alias)
"
  echo "-- mailboxes: $(wc -l < /etc/postfix/virtual), aliases: $(wc -l < /etc/postfix/virtual_alias)"
  sed 's/ / -> /; s/^/  alias: /' /etc/postfix/virtual_alias
}
mkdir -p /etc/plmail-mail-config
touch "$PLMAIL_ALIASES"
build_aliases

# texthash needs no postmap; Postfix reads the text file directly.

# --- who may send as whom (submission port) --------------------------------
# Each mailbox may send as itself (role addresses as the first mailbox), and
# each SMTP key only as the mailbox it was made for — plMail writes those
# lines to /etc/plmail-mail-config/smtp_key_senders. Without this map any
# authenticated login could put any address in From.
SENDER_KEYS="/etc/plmail-mail-config/smtp_key_senders"
build_sender_logins() {
  {
    # Every mailbox (MAIL_ACCOUNTS and plMail's) may send as itself.
    awk '{ print $1, $1 }' /etc/postfix/virtual
    printf '%s' "$ALIAS_LINES"
  } > /etc/postfix/sender_logins.base
  # Merge key logins into the mailbox's own line.
  awk 'FILENAME == ARGV[1] { k[tolower($1)] = $2; next }
       { a = tolower($1); if (a in k) { print $1, $2 "," k[a]; delete k[a] } else print }
       END { for (a in k) print a, k[a] }' \
    "${SENDER_KEYS}" /etc/postfix/sender_logins.base 2>/dev/null > /etc/postfix/sender_logins \
    || cp /etc/postfix/sender_logins.base /etc/postfix/sender_logins
}
mkdir -p /etc/plmail-mail-config
touch "$SENDER_KEYS"
build_sender_logins

# --- DKIM --------------------------------------------------------------------
KEYDIR="/etc/opendkim/keys/${MAIL_DOMAIN}"
mkdir -p "$KEYDIR"
if [ ! -f "$KEYDIR/${DKIM_SELECTOR}.private" ]; then
  echo "-- generating DKIM key ${DKIM_SELECTOR}._domainkey.${MAIL_DOMAIN}"
  opendkim-genkey -b 2048 -d "${MAIL_DOMAIN}" -s "${DKIM_SELECTOR}" -D "$KEYDIR" >/dev/null
  chmod 600 "$KEYDIR/${DKIM_SELECTOR}.private"
else
  echo "-- reusing existing DKIM key"
fi
cat > /etc/opendkim/key.table <<EOF
${DKIM_SELECTOR}._domainkey.${MAIL_DOMAIN} ${MAIL_DOMAIN}:${DKIM_SELECTOR}:${KEYDIR}/${DKIM_SELECTOR}.private
EOF
cat > /etc/opendkim/signing.table <<EOF
*@${MAIL_DOMAIN} ${DKIM_SELECTOR}._domainkey.${MAIL_DOMAIN}
EOF
cat > /etc/opendkim/trusted.hosts <<EOF
127.0.0.1
::1
172.16.0.0/12
10.0.0.0/8
192.168.0.0/16
EOF
cat > /etc/opendkim/opendkim.conf <<EOF
Mode sv
Socket inet:8891@127.0.0.1
PidFile /run/opendkim.pid
KeyTable /etc/opendkim/key.table
SigningTable refile:/etc/opendkim/signing.table
InternalHosts /etc/opendkim/trusted.hosts
EOF
echo ""
echo "== DKIM DNS: add this TXT record at ${DKIM_SELECTOR}._domainkey.${MAIL_DOMAIN} =="
cat "$KEYDIR/${DKIM_SELECTOR}.txt"
echo "== end DKIM DNS =="
echo ""

# --- render Postfix config ----------------------------------------------------
export MAIL_HELO MAIL_DOMAIN MAILSTORE_HOST RELAYHOST
envsubst '${MAIL_HELO} ${MAIL_DOMAIN} ${MAILSTORE_HOST} ${RELAYHOST} ${TLS_CERT} ${TLS_KEY}' \
  < /etc/postfix/main.cf.tpl > /etc/postfix/main.cf
sed -i "s/@MAILSTORE_HOST@/${MAILSTORE_HOST}/g" /etc/postfix/master.cf

# --- relay config: env vars + plMail UI (shared volume) ---------------------------
# The plMail admin UI writes /etc/plmail-mail-config/relay.conf:
#   RELAYHOST="[in-v3.mailjet.com]:587"
#   MAIL_RELAY_USER="api-key"
#   MAIL_RELAY_PASS="secret"
# The UI-written file wins when present; env vars are the fallback.
# A background watcher re-applies the file and reloads Postfix on change,
# so the UI takes effect without a container restart.
RELAY_CONF="/etc/plmail-mail-config/relay.conf"
mkdir -p /etc/plmail-mail-config
chmod 777 /etc/plmail-mail-config

read_relay_file() {
  FILE_RELAYHOST=""; FILE_RELAY_USER=""; FILE_RELAY_PASS=""
  if [ -f "$RELAY_CONF" ]; then
    # Strip exactly the outer quotes; the value itself may contain any
    # character but a line break (RelayConfigWriter refuses those).
    FILE_RELAYHOST=$(grep -E "^RELAYHOST=" "$RELAY_CONF" | head -1 | cut -d= -f2- | sed -E 's/^"(.*)"$/\1/')
    FILE_RELAY_USER=$(grep -E "^MAIL_RELAY_USER=" "$RELAY_CONF" | head -1 | cut -d= -f2- | sed -E 's/^"(.*)"$/\1/')
    FILE_RELAY_PASS=$(grep -E "^MAIL_RELAY_PASS=" "$RELAY_CONF" | head -1 | cut -d= -f2- | sed -E 's/^"(.*)"$/\1/')
  fi
}

apply_relay() {
  read_relay_file
  local host="${FILE_RELAYHOST:-$RELAYHOST}"
  local user="${FILE_RELAY_USER:-$MAIL_RELAY_USER}"
  local pass="${FILE_RELAY_PASS:-$MAIL_RELAY_PASS}"
  # Also feed the template render below.
  RELAYHOST="$host"

  if [ -n "$host" ] && [ -n "$user" ]; then
    printf '%s %s:%s\n' "$host" "$user" "$pass" > /etc/postfix/sasl_passwd
    chmod 600 /etc/postfix/sasl_passwd
    postconf -e "relayhost = $host" \
      "smtp_sasl_auth_enable = yes" \
      "smtp_sasl_password_maps = texthash:/etc/postfix/sasl_passwd" \
      "smtp_sasl_security_options = noanonymous" \
      "smtp_tls_security_level = encrypt"
    echo "-- outbound relaying through $host (authenticated, TLS required)"
  elif [ -n "$host" ]; then
    postconf -e "relayhost = $host" "smtp_sasl_auth_enable = no"
    echo "-- outbound relaying through $host (no auth)"
  else
    postconf -e "relayhost =" "smtp_sasl_auth_enable = no"
    echo "-- outbound direct delivery (no relay)"
  fi
}

apply_relay

postfix check

# Watch the UI-written relay config; reload Postfix when it changes.
(
  last=""
  while true; do
    sleep 15
    current=$(cat "$RELAY_CONF" "$SENDER_KEYS" "$PLMAIL_ALIASES" "$PLMAIL_MAILBOXES" 2>/dev/null | md5sum)
    if [ "$current" != "$last" ]; then
      if [ -n "$last" ]; then
        echo "-- relay, SMTP keys, aliases or mailboxes changed, re-applying"
      fi
      last="$current"
      apply_relay
      build_mailboxes
      build_aliases
      build_sender_logins
      postfix reload 2>/dev/null || true
    fi
  done
) &

echo "== starting OpenDKIM + Postfix =="
opendkim -x /etc/opendkim/opendkim.conf
exec postfix start-fg
