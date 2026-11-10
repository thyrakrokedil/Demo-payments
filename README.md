# Krokedil demo payments
Ett övningsbetalsätt för WooCommerce som kör mot en mock-betalleverantör. Pluginet visar hur en betalgateway hanterar olika svar, fel och säkerhet.

## Vad pluginet gör
Detta pluginet är en betallösning som kan hantera både inline-och-redirect flöden, även manuellt flöde. Detta plugin har funktion för att fungera både i blockkassan och shortcode-kassan. De tre lägen som finns är:

- **Manuellt:** inget API-anrop. Ordern sätts som *Pausad* och betalningen bekräftas manuellt.
- **Inline:** kunden stannar i kassan medan butikens server anropar betal-API:t.
- **Redirect:** kunden skickas till en betalsida och sedan tillbaka till butiken.

Varje utfall hanteras separat (godkänt, nekat, väntande, HTTP-fel, timeout och otolkbart svar) och ger en bestämd orderstatus, ordernotering och loggrad.

## Hur man laddar ned pluginet
1.  Klona repot genom att köra: ```git clone https://github.com/thyrakrokedil/Demo-payments.git```
2. Aktivera pluginet i WordPress under **Tillägg-> Installerade tillägg**
3. Om du vill, ändra inställningarna under **WooCommerce → Inställningar → Betalningar → Krokedil Demo Payments**

## Hur man sätter upp mock-endpointen
Mock-endpointen finns i filerna mock-payment.php och mock-redirect.php
**Inline: `mock-payment.php`.** Svaret styrs av parametern `scenario`.
Tillgängliga scenarier: `approved`, `declined`, `pending`, `error500`, `error400`, `badjson` (200 med trasig JSON) och `timeout` (svarar efter 20 s, längre än gatewayens timeout på 15 s).

**Redirect: `mock-redirect.php`.** En betalsida med knapparna Godkänn och Neka.
1. Skapa en nyckel: t.ex med en random password generator
2. Lägg in den i `mock-redirect.php`: `const SHARED_SECRET = '<nyckeln>';`
3. Klistra in samma nyckel i inställningen **Delad hemlighet**.
4. Sätt endpoint-URL:en till filens adress, utan `scenario`.

## Ofärdigt

- **Dubbelklick:** inget skydd på serversidan mot två samtidiga betalförsök, vilket kan ge dubbel debitering.
- **Inga webhooks:** om kunden stänger fliken efter betalning i redirect-läget får butiken aldrig veta det.