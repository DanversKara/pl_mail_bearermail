<!-- translated-from: apps/ios.md sha1:5ff99941017ce7e3e9537cbbcc5d3b38e121bb3b -->

# iPhone und iPad

Die BearerMail-App für iPhone und iPad. Wie die Android-App verbindet sie sich nur mit
deinem eigenen Server. Downloads: [die iOS-Veröffentlichungen](https://github.com/DanversKara/pl_mail_ios/releases/latest).

## Wege zur Installation

Apple erlaubt drei Wege, eine App aufs iPhone zu bringen, die nicht im App Store ist.
Wähl einen.

| Weg | Du brauchst | Hält |
|---|---|---|
| **Web-App auf dem Home-Bildschirm** | Safari | Dauerhaft — nichts zu erneuern |
| **SideStore oder AltStore** mit der `.ipa` aus den Veröffentlichungen | Eine kostenlose Apple-ID | 7 Tage, dann erneuert sie sich selbst (SideStore) oder vom Computer aus (AltStore) |
| **Mit Xcode bauen** | Einen Mac mit Xcode 26 und eine kostenlose Apple-ID | 7 Tage, dann neu bauen |

### Web-App auf dem Home-Bildschirm

1. Öffne deine BearerMail-Adresse in **Safari** und melde dich an.
2. Tippe auf **Teilen → Zum Home-Bildschirm → Hinzufügen**.
3. Öffne sie vom Home-Bildschirm. Erlaube Mitteilungen, wenn gefragt (ab iOS 16.4).

### SideStore oder AltStore

1. Installiere [SideStore](https://sidestore.io) oder [AltStore](https://altstore.io) nach
   deren eigener Anleitung.
2. Lade auf dem iPhone **BearerMail-iOS.ipa** aus
   [der neuesten Veröffentlichung](https://github.com/DanversKara/pl_mail_ios/releases/latest).
3. Öffne sie mit SideStore oder AltStore und melde dich mit deiner Apple-ID an. Die App wird
   nur für dein Gerät signiert.

### Mit Xcode bauen

Die Schritt-für-Schritt-Anleitung steht im Repository der App:
[BearerMail auf dein eigenes iPhone oder iPad bringen](https://github.com/DanversKara/pl_mail_ios/blob/main/docs/INSTALL-ON-DEVICE.md).

## Koppeln

Öffne die App und scanne den Code aus **Einstellungen → App-Passwörter → Gerät koppeln** im Web.

## Fallstricke

- **„Nicht vertrauenswürdiger Entwickler“.** Nach einer Installation mit SideStore, AltStore
  oder Xcode: Einstellungen → Allgemein → **VPN und Geräteverwaltung**, deine Apple-ID
  antippen und **Vertrauen**.
- **Die App startet nach einer Woche nicht mehr.** Das ist Apples Grenze für Apps, die mit
  einer kostenlosen Apple-ID signiert sind. Erneuere sie in SideStore oder AltStore oder baue
  sie neu. Nur ein bezahltes Apple-Entwicklerkonto (oder der App Store) umgeht das.
- **Keine Mitteilungen bei geschlossener App, und das Widget bleibt leer**, mit einer
  kostenlosen Apple-ID — Push und den Datenaustausch fürs Widget gibt Apple nur bezahlten
  Entwicklerkonten. Die Web-App auf dem Home-Bildschirm bekommt Mitteilungen.
