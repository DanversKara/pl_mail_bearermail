# plMail — Bearer Mail edition

**This is a fork of [plMail](https://github.com/karatektus/pl_mail) by
[karatektus](https://github.com/karatektus)**, adapted to run a complete mail service for
`bearermail.com`: its own mail server, plus the additions listed below.

All credit for plMail itself goes to its author. This fork keeps the original licence
(**AGPL-3.0**, see [`LICENSE`](LICENSE)) and the original README, which follows further down,
unchanged. Anything not listed under "What this fork adds" is the original project's work.

### 📖 [Read the BearerMail Handbook / Guide →](https://docs.bearermail.com/)

Installing, pairing, the app's features, updates, and troubleshooting — at
[docs.bearermail.com](https://docs.bearermail.com/).

> **Not the official project.** For plMail itself, its handbook, releases, the Android and iOS
> apps, and support, go to [karatektus/pl_mail](https://github.com/karatektus/pl_mail). Please
> report problems with the additions below here, not upstream.

---

## What this fork adds

Changes made in October 2026, on top of upstream plMail at commit `0056fc09`.

### Its own mail server
Upstream plMail is a mail *client*: it reads mailboxes hosted somewhere else. This fork adds an
optional mail *server* to the same Docker stack, so your own domain can receive and send mail with
no outside mail host:

- **Postfix** receives internet mail on port 25 and sends outgoing mail, **DKIM-signed**
  (OpenDKIM), either directly or through a relay such as Mailjet.
- **Dovecot** stores the mailboxes and serves IMAP to plMail (and to phones and Thunderbird on
  port 993).
- **Aliases from Settings → Aliases are applied to the server automatically.** An alias receives
  into its mailbox and can be sent from, and disabling it in plMail stops it receiving.
- **Each login may only send as its own addresses**, so a leaked password or SMTP key cannot be
  used to fake another sender on the domain.
- **Admin → Mail server:** set the outbound relay in the UI, check your MX/SPF/DKIM/DMARC records,
  and read DMARC reports.
- **Settings → SMTP keys:** separate, revocable credentials for scripts and devices, each locked to
  one sending address.
- **Dynamic DNS:** keeps a Cloudflare A record pointed at a home connection's changing IP, checked
  every five minutes. It only ever contacts Cloudflare, and only once it's configured.

Off by default: start it with `docker compose --profile mail up -d`. Setup, DNS and testing are in
[`docs/install/self-hosted-mail-server.md`](docs/install/self-hosted-mail-server.md).

### Drive
Personal file storage inside plMail: folders, drag-and-drop uploads with progress, list or grid
view, search, rename, and share links with an optional password and expiry date. Recipients
download without an account. **Insert from Drive** in the composer drops a share link into a
message, and **Add from Drive** in a calendar event puts a link in its description. Files have
their own Docker volume and are included in `app:backup`.

- **Big files:** Drive takes files up to 2 GB (the admin can set a lower limit). A file too big to
  attach to an email (over 25 MB) is uploaded to Drive automatically and linked in the message
  instead, with a note saying why.
- **Admin → Drive:** the default storage per person, the largest file, storage used by each person
  with their own limit, and **reported links**. Anyone sent a link can press **Report this link**;
  the admin can revoke the link, switch off the owner's Drive, or dismiss the report.
- **Admin → Users → edit:** each person's own Drive limit, or switch Drive off for someone abusing
  it. That blocks uploads and stops every link they have shared.

The Drive also has a small API for the phone app (`/jmap/drive`, announced in the JMAP session as
`urn:bearermail:params:drive`), using the same app password as mail.

### BearerMail name and Android app
- The web app calls itself **BearerMail** (page titles, the installable web app, emails it sends).
  Set `APP_BRAND_NAME` in `.env.local` to use another name.
- A matching build of the plMail Android app (kept separately) is renamed BearerMail and installs
  as its own app (`com.bearermail.mail`), next to stock plMail. It adds:
  - **Drive:** browse folders, upload from the phone, open, save or send files, rename, move,
    delete, and make share links with a password and expiry.
  - **Share to "BearerMail Drive"** from any app's share sheet uploads straight into Drive.
  - **Insert Drive link** in the composer and **Add from Drive** in the event editor.
  - **Plans in emails** get an Add to calendar card, and **Insert event** in the composer's menu
    adds one of your events (details plus an `.ics`).
  - **On-the-spot aliases:** "New alias" in the drawer or the composer's From menu. Type a name or
    make a random one in one tap. It's copied to the clipboard, receives mail within seconds, and
    can be sent from right away.

### Calendar from mail, and mail from calendar
- **Plans in ordinary emails** ("meet me at 218 Main St at 10am", "lunch Friday at 12:30") get a
  card with **Add to calendar**, which opens the event editor already filled in: title, time,
  place and a note. Nothing is added until you press Save. Turn it off under Settings → Insights.
- **Insert an event** in the composer puts one of your upcoming events into the message and
  attaches it as an `.ics`, so the recipient can add it to their own calendar in one tap.

### Security additions
- Warning banners on mail that **fails sender authentication** (SPF/DKIM/DMARC), on mail with
  **links that pretend to go somewhere else**, and on sudden **floods of mail** that can be used to
  bury a security alert.
- **Admin → Security:** recent sign-ins, failed attempts, and addresses with repeated failures.

### Smaller things
- **Login codes** found in mail get a card with the code in large type and a one-click **Copy code**
  button.
- **No automatic calls home:** upstream's hourly update check (to GitHub and ghcr.io) is removed;
  Admin → Updates can still check on demand.
- **Secrets stay out of git:** mail-server passwords, relay keys and the Cloudflare token live in
  `.env.mail.local`, which is ignored by git and never copied into the Docker image. A template is
  in [`.env.mail.dist`](.env.mail.dist).
- Fixes found along the way: translation strings that had broken the Appearance settings page and
  the message menu, and two harmless browser notices no longer filling Admin → Logs.

---

## Install this fork

```bash
git clone https://github.com/DanversKara/pl_mail_bearermail.git
cd pl_mail_bearermail

# Mail-server settings and secrets: copy the template and fill it in
cp .env.mail.dist .env.mail.local
nano .env.mail.local      # MAIL_DOMAIN, MAIL_ACCOUNTS, relay, Cloudflare…

# Pull and start plMail plus the mail server
docker compose --profile mail pull
docker compose --profile mail up -d
```

This fork publishes its own image, `ghcr.io/danverskara/pl_mail_bearermail`, so the additions above
are included and Admin → Updates reports new builds of this fork rather than of upstream:

- **`latest`**, what `compose.yaml` pulls and the **Releases** channel follows, moves when a release
  is cut: **Actions → Release → Run workflow** tags the current `main` with the next version
  (v0.4.0, v0.4.1, …), makes a GitHub release titled with the version and its commit, and builds
  the image.
- **`main`** is rebuilt on every push to `main`, for the **Main** channel. Running it by hand
  needs `BEARERMAIL_TAG=main` in your shell's environment.

To upgrade, run the same `pull` and `up -d` again. Database changes are applied automatically on
start.

To have the server install new builds by itself, choose **Install automatically** in Admin → Updates
and schedule the installer once: `bin/auto-update install`. See
[Installing updates automatically](docs/install/upgrading.md#installing-updates-automatically).

`docker compose up -d --build` still builds from source, for a server that cannot reach ghcr.io,
but a local build has no commit stamped in, so Admin → Updates cannot compare it with anything.

**Back up** the database (`docker compose exec php php bin/console app:backup`), the secrets in
`var/secrets/generated.env`, and your `.env.mail.local`. The last one holds your mailbox passwords
and is deliberately not in git.

---

## Licence and credit

plMail is © its author, [karatektus](https://github.com/karatektus), and licensed under the
**GNU Affero General Public License v3.0**. The additions in this fork are released under the same
licence. AGPL-3.0 means:

- the full licence text stays in [`LICENSE`](LICENSE), unchanged;
- this README says what was changed and when (above);
- if you run this fork as a service other people use, you must offer them its source code. This
  public repository does that.

---

<details open>
<summary><strong>Original plMail README</strong> (from upstream, unchanged)</summary>

# plMail

Self-hosted mail with a calendar beside it. Connect the mailboxes you already have — IMAP, Gmail,
Outlook — and read them side by side in one inbox, on a server you own.

![PHP](https://img.shields.io/badge/PHP-8.4+-777BB4?logo=php&logoColor=white)
![Symfony](https://img.shields.io/badge/Symfony-8-000000?logo=symfony&logoColor=white)
![PostgreSQL](https://img.shields.io/badge/PostgreSQL-18-4169E1?logo=postgresql&logoColor=white)
![License](https://img.shields.io/badge/license-AGPL--3.0-green)

### 📖 [Read the handbook →](https://karatektus.github.io/pl_mail/docs/)

Installing on Docker, Linux, WSL2, macOS or a NAS · every feature in detail · connecting Gmail and
Outlook, with the exact permissions to tick · how the internals work.
Also on the [wiki](https://github.com/karatektus/pl_mail/wiki) and in [`docs/`](docs/) — all three
are built from the same files.

### 📱 Apps for your phone

- **Android** — [karatektus/pl_mail_android](https://github.com/karatektus/pl_mail_android)
- **iOS** — [karatektus/pl_mail_ios](https://github.com/karatektus/pl_mail_ios)

Both connect to your own plMail server.

---

## What it is

A mail client you host yourself. It connects to the mailboxes you already have — IMAP, Gmail,
Outlook — syncs them into your own PostgreSQL database, and gives them one inbox, one search box and
one set of labels in the browser.

It is **not** a mail server: it doesn't receive mail from the internet or host your domain. Because
everything is synced locally, search runs against your database, and your mail stays readable when a
provider doesn't.

![The plMail inbox](docs/screenshots/inbox.png)

## Highlights

- **Every account in one place** — IMAP, Gmail and Outlook side by side, sending aliases included.
  Credentials are encrypted before they touch the database.
- **New mail arrives on its own** — IMAP IDLE, Gmail and Outlook push, browser notifications.
- **Search that means it** — full text plus `from:`, `subject:`, `label:`, `has:`, `before:` and
  friends, completed as you type. Every hit shows the words it matched on, marked, in a window of
  the message around them. What the words found comes first; what the model merely thought looked
  similar follows, labelled, and never pushes a real match off the page.
- **Filters as a tree** — any/all/none, nested, restated in plain English and counted against real
  mail before you save. Apply one to mail that arrived before it existed.
- **Filed where you drop it** — drag a conversation onto a folder, a label or one of the inbox tabs,
  several at once if they are ticked. A folder moves it and a label just adds itself, so dropping on
  "Receipts" does not take the mail out of your inbox; **Starred** stars it and **Unread** — an
  optional list of every unread conversation, whatever folder it is in — marks it unread, both where
  it lies. Only the targets that will accept it light up, and a tab you choose by hand stays chosen
  when the next message in the thread arrives.
- **A calendar beside the mail** — two-way sync with Google, Microsoft and CalDAV, a drag-to-move
  week grid that pans rather than squeezes on a phone, invitations answered in place and landing in
  the calendar as you answer them, share and booking links. The time is always in view — on the Happening Soon button, under the name, or at
  the foot of the sidebar with the next thing coming up.
- **Files where you keep them** — attach from and save to Drive, Photos, OneDrive, Dropbox,
  Nextcloud, Immich and Paperless-ngx.
- **A language model, if you want one** — point plMail at an Ollama box on your own network, or at
  any OpenAI-compatible endpoint, and it can search by meaning, draft replies, summarise a thread
  and sort mail into tabs. A long thread is summarised from as much of it as fits, and says so; ask
  for the whole conversation and it runs as a job with a context window to match. What it has been told about how you write is editable in the
  composer, for one attempt at a time. Off by default, each of the four switched on separately. Mail
  goes to the model host you name and nowhere else, so with a model on your own machine nothing
  leaves your network.
- **You decide what sorts your mail** — header rules that answer the same way every time, or the
  model reading each message; and on Gmail, whether either of them is allowed to disagree with
  Google. Change your mind and the mailbox re-sorts itself in the background. Every message's
  **Details** panel shows what all three said and which one won, and a **Report** button tells your
  administrator when it got it wrong.
- **It tells you when something breaks** — a sign-in that has expired, a calendar that stopped
  syncing, push that lapsed, work the queue gave up on. Each with what it means for your mail and
  the smallest thing that fixes it. A Google sign-in dying every week gets named for what it is —
  Google expires tokens for an app still in Testing — and warned about before the next one.
- **And when there is something newer** — follow releases or the main branch, and the
  administrators hear about each new build once: on their devices and at the top of the admin area,
  with what changed since the one they run.
- **Yours** — two-factor auth, sign-in through Authentik, Keycloak or any other OpenID Connect
  provider, per-app JMAP passwords, 41 themes, ten icons for the tab and the phone's home screen,
  English, German, French, Simplified Chinese and Pirate.

## A look around

|  |  |
|---|---|
| ![Reading a thread](docs/screenshots/thread.png) | ![Writing a message](docs/screenshots/compose.png) |
| **Threads** — replies collapse into one conversation. | **Compose** — rich text, autocomplete, undo send. |
| ![Dark mode](docs/screenshots/inbox-dark.png) | ![Calendar](docs/screenshots/calendar.png) |
| **Themes** — 41 of them, light and dark. | **Calendar** — docked beside the mail, or its own page. |
| ![Filters](docs/screenshots/filters.png) | ![Admin](docs/screenshots/admin.png) |
| **Filters** — counted before you save. | **Admin** — workers, push health, logs, queue. |
| ![Choosing an icon](docs/screenshots/icons.png) | ![Choosing its colours](docs/screenshots/colourways.png) |
| **Icons** — ten of them, a blue horn among them. | **Colourways** — each icon in its own colours, or any of 32. |

<sub>Screenshots use demo data — none of the accounts, senders or messages shown are real.</sub>

## Install

Images are published for `linux/amd64` and `linux/arm64`. Nothing to fill in first: the secrets are
generated on first start, and the public address is asked for on the setup screen.

### Docker

```bash
git clone https://github.com/karatektus/pl_mail.git
cd pl_mail
docker compose up -d
```

Open [https://localhost](https://localhost) and create your account. From another machine, open
`https://` and the server's address instead; the browser warns about the self-signed certificate
once and lets you continue.

### TrueNAS

plMail is in the TrueNAS app catalogue. **Apps → Discover Apps**, search for **plMail**, and
install it. The form asks for one thing, a database password; everything else has a default that
works.

Reach it on port `30519`, and put a reverse proxy in front of it if it should be reachable from
outside. Updates arrive through the catalogue like any other app's.

### Then

Add an IMAP mailbox in **Settings → Mail accounts** and you're done. Gmail and Outlook need OAuth
credentials from Google or Microsoft first — the handbook walks through both, including the
optional Pub/Sub topic for instant Gmail delivery.

> **Back up two things, separately:** the PostgreSQL data and `var/secrets/generated.env`. The
> database holds your mail; the key inside that file unlocks the credentials in it. Keeping them
> apart is the point — and losing the key means the stored mailbox passwords are gone.

## Contributing

Bug reports and pull requests welcome — see [CONTRIBUTING.md](CONTRIBUTING.md) for the dev setup,
the test suites and the console commands.

## License

AGPL-3.0.

</details>
