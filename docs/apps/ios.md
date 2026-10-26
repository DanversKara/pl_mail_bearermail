# iPhone and iPad

The BearerMail app for iPhone and iPad. Like the Android app it connects only to your
own server. Downloads: [the iOS releases](https://github.com/DanversKara/pl_mail_ios/releases/latest).

## Ways to install

Apple allows three ways to put an app on an iPhone that is not in the App Store. Pick
one.

| Way | You need | Lasts |
|---|---|---|
| **Web app on the Home Screen** | Safari | For good — nothing to renew |
| **SideStore or AltStore** with the `.ipa` from the releases | A free Apple ID | 7 days, then it renews itself (SideStore) or from your computer (AltStore) |
| **Build it with Xcode** | A Mac with Xcode 26 and a free Apple ID | 7 days, then build again |

### Web app on the Home Screen

1. Open your BearerMail address in **Safari** and sign in.
2. Tap **Share → Add to Home Screen → Add**.
3. Open it from the Home Screen. Allow notifications when it asks (iOS 16.4 and newer).

### SideStore or AltStore

1. Install [SideStore](https://sidestore.io) or [AltStore](https://altstore.io) by
   following their own guide.
2. On the iPhone, download **BearerMail-iOS.ipa** from
   [the latest release](https://github.com/DanversKara/pl_mail_ios/releases/latest).
3. Open it with SideStore or AltStore and sign in with your Apple ID when asked. The
   app is signed for your phone only.

### Build it with Xcode

The step-by-step guide is in the app's repository:
[Putting BearerMail on your own iPhone or iPad](https://github.com/DanversKara/pl_mail_ios/blob/main/docs/INSTALL-ON-DEVICE.md).

## Pairing

Open the app and scan the code from **Settings → App passwords → Pair a device** on the web.

## Things that bite

- **"Untrusted Developer".** After a SideStore, AltStore or Xcode install, go to
  Settings → General → **VPN & Device Management**, tap your Apple ID and **Trust**.
- **The app stops opening after a week.** That is Apple's limit for apps signed with a
  free Apple ID. Refresh it in SideStore or AltStore, or build again. A paid Apple
  developer account (or the App Store) is the only way around it.
- **No notifications when the app is closed, and the widget is empty**, on a free Apple
  ID — Apple only grants push and widget data sharing to paid developer accounts. The
  web app on the Home Screen does get notifications.
