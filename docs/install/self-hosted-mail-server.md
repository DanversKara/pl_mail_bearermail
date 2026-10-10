# Self-hosted mail server

plMail is a mail client: it reads any IMAP mailbox and sends through any SMTP
server. This page adds the other half — a real mail server for your own
domain, built from Postfix (SMTP), Dovecot (IMAP/LMTP) and OpenDKIM, running
as part of the same compose stack. No third-party mail provider needed.

```
internet --port 25--> mta (Postfix) --LMTP--> mailstore (Dovecot)
plMail   --IMAP 143--> mailstore                (reading, like any account)
plMail   --SMTP 587--> mta                      (sending, DKIM-signed)
```

Because delivery lands in Dovecot and plMail syncs it over IMAP like any
other account, everything plMail does — threads, full-text search, filters,
push, the AI features — works on your domain mail with zero changes to the
app. And mail keeps arriving even when plMail itself is down: Postfix queues
it.

## Quick start

All mail-server settings — including the passwords — live in **`.env.mail.local`**
next to `compose.yaml`. Git and the Docker image build both ignore that file,
so no secret ends up in the repository or in an image. compose.yaml hands it to
the `php`, `worker`, `mta` and `mailstore` containers.

```bash
# 1. Create your settings file from the template and fill it in.
cp .env.mail.dist .env.mail.local
#    MAIL_DOMAIN=bearermail.com
#    MAIL_ACCOUNTS=support:CHOOSE-A-STRONG-PASSWORD,info:ANOTHER-STRONG-PASSWORD

# 2. Bring up plMail plus the mail server.
docker compose --profile mail up -d --build

# 3. Read the DKIM record the MTA generated (add it to your DNS, see below).
docker compose logs mta | sed -n '/== DKIM DNS/,/== end DKIM DNS/p'
```

`MAIL_ACCOUNTS` is `localpart:password` pairs, comma-separated. Logins are
the full address (`support@bearermail.com`). `postmaster@` and `abuse@` are
aliased to the first mailbox automatically. To add or change mailboxes later,
edit `.env.mail.local` and recreate the two containers:

```bash
docker compose --profile mail up -d --force-recreate mta mailstore
```

Each login may only send as its own address (Postfix
`reject_authenticated_sender_login_mismatch`).

**Aliases.** Extra addresses that land in an existing mailbox go in
`MAIL_ALIASES`, as `alias:mailbox` pairs:

```
MAIL_ALIASES=sales:support,billing:support
```

Mail to `sales@` is delivered into `support`'s mailbox, and `support` may send
as `sales@`. plMail's **Settings → Aliases** only chooses a From address; the
alias has to exist here too, or mail to it bounces with "User unknown".

Mail is stored in the `mail_data` volume (Maildir), DKIM keys in `mail_dkim` —
both survive restarts and rebuilds.

## DNS

These four records make your domain a working mail domain. Replace
`bearermail.com` with yours and `203.0.113.10` with your server's public IP.

| Record | Name | Value |
|---|---|---|
| MX | `@` | `10 mail.bearermail.com.` |
| A | `mail` | `203.0.113.10` (DNS-only — never proxy mail through Cloudflare) |
| TXT (SPF) | `@` | `v=spf1 mx ~all` |
| TXT (DKIM) | `mail._domainkey` | the record from `docker compose logs mta` (step 3 above) |
| TXT (DMARC) | `_dmarc` | `v=DMARC1; p=none; rua=mailto:postmaster@bearermail.com` |

Start DMARC at `p=none` (monitoring). Move to `quarantine`, then `reject`,
once you've watched real traffic for a week or two.

Two things DNS alone can't do:

- **Port 25 inbound must reach your server.** Many residential ISPs and some
  VPS providers block it. Test from outside: `telnet mail.bearermail.com 25`
  should show the Postfix banner. If your provider blocks port 25 inbound,
  you cannot receive internet mail directly — a VPS or a relay service is
  the way out.
- **A PTR (reverse-DNS) record** for your IP pointing at `mail.bearermail.com`,
  set by whoever owns the IP (your VPS/ISP panel). Gmail and friends weigh
  this heavily. Without it, expect spam-folder delivery.

## Wiring plMail to it

In plMail, **Settings → Mail accounts → Add account**:

| Field | Value |
|---|---|
| IMAP host | `mailstore` |
| IMAP port | `143` |
| IMAP encryption | None (it never leaves the compose network) |
| Username | `support@bearermail.com` |
| Password | the one from `MAIL_ACCOUNTS` (in `.env.mail.local`) |
| SMTP host | `mta` |
| SMTP port | `587` |
| SMTP encryption | None |
| SMTP username/password | same as IMAP |

The worker's IMAP supervisor will start syncing within a minute, and sent
mail goes out through your own Postfix, DKIM-signed.

## Reading mail in Thunderbird / phone mail apps

Dovecot also serves **public IMAP on port 993** (TLS), so any mail app can
connect directly:

| Field | Value |
|---|---|
| IMAP host | `mail.bearermail.com` (your mail hostname) |
| IMAP port | `993` |
| Encryption | SSL/TLS |
| Username | your full address (`support@bearermail.com`) |
| Password | the one from `MAIL_ACCOUNTS` |

For a valid certificate, mount your Let's Encrypt certs so Dovecot uses them:

```bash
# MAIL_CERTS_PATH is read by compose itself, so it goes in your shell or the
# project .env, not .env.mail.local.
export MAIL_CERTS_PATH="/path/to/certs"   # contains fullchain.pem + privkey.pem
docker compose --profile mail up -d --build --force-recreate mta mailstore
```

Without mounted certs Dovecot generates a self-signed one — mail apps will
warn about it, which is the nudge to mount the real cert. (Sending from those
apps still goes through plMail or the Mailjet relay; direct SMTP submission
from the outside is a separate step.)

## Sending: direct or relayed

By default Postfix delivers outbound mail itself, by looking up each
recipient's MX (direct delivery). That needs **port 25 outbound** open, which
is also commonly blocked. If it is, or if deliverability is poor, relay
through a provider such as Mailjet instead.

### Mailjet

1. In Mailjet: **Account → API keys** — copy the **API key** and the
   **Secret key**. Never paste the secret into chat or a ticket.
2. In Mailjet: **Account → Sender domains** — validate your domain, and add
   the SPF/DKIM records Mailjet shows you there.
3. Either put the login in `.env.mail.local`:

   ```
   MAIL_RELAYHOST=[in-v3.mailjet.com]:587
   MAIL_RELAY_USER=your-mailjet-api-key
   MAIL_RELAY_PASS=your-mailjet-secret-key
   ```

   and run `docker compose --profile mail up -d --force-recreate mta`,

4. or enter the same three values in **Admin → Mail server**. The mta picks
   that up within about 15 seconds — no restart. What you save there wins over
   `.env.mail.local`; clearing the host field in the UI falls back to the file.

Outbound mail is DKIM-signed by your own server first, then handed to the
relay over TLS (TLS is required whenever a relay login is set). The relay adds
its own DKIM signature too — two signatures on one mail is normal.

Either way, keep SPF/DKIM/DMARC from the DNS section: receivers check them
no matter who carries the mail.

## SMTP keys for scripts and devices

**Settings → SMTP keys** makes a separate login (`smtp_<n>` plus a random
password, shown once) for a script, printer or backup job. A key can only
send as the mailbox it was made for, and revoking it takes effect at once.
Submission (port 587) is only reachable inside the compose network, as host
`mta`; to use a key from another machine, publish 587 with real certificates
first (see Certificates below).

## Dynamic DNS (home connections)

If your public IP changes, plMail can keep the A record for
`mail.bearermail.com` pointed at it through the Cloudflare API. Add to
`.env.mail.local`:

```
CLOUDFLARE_API_TOKEN=a token with DNS edit rights on the zone
CLOUDFLARE_ZONE_ID=your zone id
DYNAMIC_DNS_HOSTS=mail.bearermail.com
```

The worker checks every five minutes on its own (no cron job needed), asking
Cloudflare both for your current IP and to update the record — no other
service is contacted. With the three values unset, nothing is sent anywhere.
To run it by hand: `docker compose exec worker php bin/console app:mail:update-dynamic-dns`.

## Testing

```bash
# From OUTSIDE your network — does the internet reach your MX?
telnet mail.bearermail.com 25
# expect: 220 mail.bearermail.com ESMTP plMail

# Send yourself a mail from anywhere (Gmail etc.) to support@bearermail.com,
# then watch it land:
docker compose logs -f mta

# Inside plMail, check Settings → Mail accounts: the account should show a
# healthy sync, and the message appears in the inbox.
```

If nothing arrives: `docker compose logs mta` shows every SMTP conversation.
`NOQUEUE: reject: RCPT ... Relay access denied` means the recipient isn't in
`MAIL_ACCOUNTS`. `Sender address rejected: not owned by user` means a login
tried to send as an address that isn't its own. `status=bounced` with a DNS error means the container can't
resolve — check the host's DNS.

## Certificates

Port 25 uses opportunistic TLS; remote servers don't verify it, so the
self-signed certificate the MTA generates on first start is fine there.

Port 587 is intentionally **not published** to the host — submission stays
inside the compose network between plMail and Postfix. If you later want
phones or laptops to submit mail from outside, publish the port and mount
real certificates:

```bash
export MAIL_CERTS_PATH=/etc/letsencrypt/live/mail.bearermail.com
```

(the directory containing `fullchain.pem` and `privkey.pem`), then require
TLS on submission. Until then, don't publish 587.

## What this is not (yet)

- No spam filtering (no Rspamd/SpamAssassin yet) and no greylisting.
- No sieve filters, no quotas, no per-domain config — one domain per stack.
- Mailbox management is the `MAIL_ACCOUNTS` setting in `.env.mail.local`, not a UI.
- Webmail *is* plMail itself — that's the point.

All of those are reasonable next steps; the shape above is deliberately the
smallest thing that sends and receives real mail.
