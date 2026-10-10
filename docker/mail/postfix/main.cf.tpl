# plMail MTA — rendered by entrypoint.sh from the MAIL_* environment.
# Postfix's own $variables below are literal; only ${UPPER_CASE} names are
# substituted by the entrypoint.

# Log to stdout, not syslog: there is no syslog daemon in this container.
# /dev/stdout (with `postfix start-fg`) is what puts every SMTP conversation
# in `docker compose logs mta` — a file under /var/log hid them all.
maillog_file = /dev/stdout

# Current defaults, not the pre-3.0 ones; silences the startup warning.
compatibility_level = 3.6

myhostname = ${MAIL_HELO}
mydomain = ${MAIL_DOMAIN}
myorigin = $mydomain

inet_interfaces = all
inet_protocols = ipv4

# We are the MX for our own domain only. Local(8) delivery is disabled;
# the domain is virtual and handed to Dovecot over LMTP.
mydestination =
local_recipient_maps =
local_transport = error:local delivery disabled

virtual_mailbox_domains = ${MAIL_DOMAIN}
virtual_mailbox_base = /var/mail/vhosts
# texthash: plain-text map, always available (Alpine's postfix has no hash:/bdb).
virtual_mailbox_maps = texthash:/etc/postfix/virtual
# Aliases (MAIL_ALIASES, postmaster@, abuse@) are rewritten to a real mailbox
# BEFORE delivery, so Dovecot only ever sees logins it knows.
virtual_alias_maps = texthash:/etc/postfix/virtual_alias
virtual_minimum_uid = 100
virtual_uid_maps = static:5000
virtual_gid_maps = static:5000
virtual_transport = lmtp:inet:${MAILSTORE_HOST}:24

# Outbound: direct MX delivery unless MAIL_RELAYHOST is set.
relayhost = ${RELAYHOST}
smtp_tls_security_level = may
smtp_tls_loglevel = 1

# Inbound TLS is opportunistic (sending MXs don't verify it).
smtpd_banner = $myhostname ESMTP plMail
smtpd_tls_cert_file = ${TLS_CERT}
smtpd_tls_key_file = ${TLS_KEY}
smtpd_tls_security_level = may
smtpd_tls_received_header = yes
# lmdb, not btree: Alpine's Postfix is built without Berkeley DB, and a
# btree: map is "unsupported dictionary type" the first time TLS is used.
smtpd_tls_session_cache_database = lmdb:${data_directory}/smtpd_scache

# Never relay for strangers. Port 25 accepts mail FOR our domain;
# authenticated submission (587, see master.cf) may send anywhere.
smtpd_relay_restrictions = permit_mynetworks, reject_unauth_destination
smtpd_recipient_restrictions = permit_mynetworks, reject_unauth_destination
smtpd_helo_required = yes
# Which SASL login may use which From address (built by the entrypoint from
# MAIL_ACCOUNTS and plMail's SMTP keys). Enforced on submission in master.cf.
smtpd_sender_login_maps = texthash:/etc/postfix/sender_logins
disable_vrfy_command = yes

# DKIM-sign everything our users send.
smtpd_milters = inet:127.0.0.1:8891
non_smtpd_milters = inet:127.0.0.1:8891
milter_default_action = accept
milter_protocol = 6
