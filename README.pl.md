# Laravel EmailLabs

[![tests](https://github.com/beziworld/laravel-emaillabs/actions/workflows/tests.yml/badge.svg)](https://github.com/beziworld/laravel-emaillabs/actions/workflows/tests.yml)
[![Latest Version](https://img.shields.io/packagist/v/beziworld/laravel-emaillabs.svg)](https://packagist.org/packages/beziworld/laravel-emaillabs)
[![License](https://img.shields.io/packagist/l/beziworld/laravel-emaillabs.svg)](LICENSE.md)

Integracja Laravela z [EmailLabs](https://emaillabs.io) (Vercom): transport Laravel Mail oparty na REST API v2.1,
szablony ze zmiennymi, tagi i własne nagłówki, podpisane webhooki doręczeń, odbić, otwarć i kliknięć jako zdarzenia
Laravela, czarna lista (lista wykluczeń), statystyki wysyłek oraz wiele paneli i kont SMTP.

[English documentation](README.md)

## Funkcje

- Transport poczty `emaillabs` (`Mail::extend`): każdy Mailable i każde powiadomienie idą przez API, z osobnym
  Message-ID dla każdego odbiorcy, Cc, Bcc, Reply-To, załącznikami i obrazkami inline.
- `tag()` i `metadata()` z Mailable mapowane na tagi EmailLabs i nagłówki `X-Metadata-*`; nagłówki sterujące kontem
  SMTP, szablonem i jego zmiennymi.
- Bezpośrednie API: builder `EmailLabsMessage` ze zmiennymi per odbiorca, zmiennymi globalnymi i szablonami.
- Szablony: lista, dodawanie, usuwanie (`{{ zmienna }}`, warunki i pętle).
- Trasa webhooka z weryfikacją `X-Webhook-Checksum` (SHA1 z sekretu, daty i ID żądania), opcjonalnym Basic auth i
  zdarzeniami `EmailDelivered`, `EmailBounced`, `EmailDropped`, `EmailDeferred`, `EmailOpened`, `EmailClicked`,
  `EmailEventReceived`, `WebhookReceived`.
- Statusy, otwarcia i kliknięcia wysłanych wiadomości, statystyki zagregowane, lista kont SMTP.
- Czarna lista adresów i domen: lista, dodawanie, usuwanie, sprawdzanie.
- Wiele paneli (nazwane połączenia, klucze w runtime per tenant) i wiele kont SMTP w panelu.
- Bez SDK: klient HTTP Laravela, więc działa `Http::fake()`; odczyty ponawiane po 5xx i 429, wysyłka nigdy.
- Typowane, niezmienne odpowiedzi, jedna hierarchia wyjątków, PHP 8.3+, Laravel 12 i 13, PHPStan level 8.

## Dlaczego ten pakiet

| Pakiet | Stan (Packagist, listopad 2026) |
|---|---|
| [`emaillabs/emaillabs-php-api`](https://packagist.org/packages/emaillabs/emaillabs-php-api) (50 tys. pobrań) | Oficjalne SDK, tylko `dev-master`, ostatnia zmiana 2018, starsze API v1 (`api.emaillabs.net.pl`), bez integracji z Laravelem |
| [`sigrun/lelpl`](https://packagist.org/packages/sigrun/lelpl) (2 tys.) | `dev-master` z 2019, nakładka na powyższe SDK |
| [`beryldev/laravel-emaillabs`](https://packagist.org/packages/beryldev/laravel-emaillabs) (374) | v1.0.0 z 2016, Laravel 5.2 |

Ten pakiet korzysta z aktualnego API v2.1 (`api.emaillabs.io`, nagłówki `Application-Key` i `Authorization`), działa
jako transport Symfony Mailer w Laravel Mail, weryfikuje sumy kontrolne webhooków i zamienia raporty doręczeń na
zdarzenia, wspiera Laravel 12 i 13 i jest w pełni przetestowany.

## Instalacja

```bash
composer require beziworld/laravel-emaillabs
php artisan vendor:publish --tag=emaillabs-config   # opcjonalnie
```

```dotenv
EMAILLABS_APP_KEY=twoj-application-key
EMAILLABS_SECRET_KEY=twoj-128-znakowy-klucz-authorization
EMAILLABS_SMTP_ACCOUNT=1.twojpanel.smtp
EMAILLABS_WEBHOOK_SECRET=sekret-wygenerowany-dla-webhooka   # dla webhooków
```

Klucze generuje się w panelu: Konto, Ustawienia, API.

## Transport poczty

Dodaj mailer w `config/mail.php` i ustaw go jako domyślny (albo używaj wybiórczo):

```php
'mailers' => [
    'emaillabs' => [
        'transport' => 'emaillabs',
        // 'connection' => 'default',            // połączenie z config/emaillabs.php
        // 'smtp_account' => '2.twojpanel.smtp', // inne konto SMTP panelu
    ],
],
```

```dotenv
MAIL_MAILER=emaillabs
```

Od teraz każdy Mailable, powiadomienie i `Mail::raw()` idą przez API:

```php
use Illuminate\Mail\Mailables\Envelope;

public function envelope(): Envelope
{
    return new Envelope(
        subject: 'Twoje zamówienie zostało wysłane',
        tags: ['zamowienia', 'wysylka'],            // tagi EmailLabs (raporty, webhooki)
        metadata: ['order_id' => $this->order->id], // wysyłane jako X-Metadata-order_id
    );
}
```

Każdy odbiorca „To” dostaje osobną kopię z własnym Message-ID (EmailLabs wymaga unikalnych ID, by łączyć statusy
z webhooków). ID pierwszego odbiorcy staje się Message-ID `SentMessage`; wszystkie ID są w `$transport->lastResult()`
i w zdarzeniu `EmailSent`.

Nagłówki sterujące (nie są przekazywane dalej):

| Nagłówek | Działanie |
|---|---|
| `X-EmailLabs-Smtp-Account` | wysyłka przez inne konto SMTP |
| `X-EmailLabs-Template` | użycie zapisanego szablonu (templateId) |
| `X-EmailLabs-Vars` | obiekt JSON ze zmiennymi szablonu (`globalVars`) |

```php
use Beziworld\EmailLabs\Transport\EmailLabsTransport;

public function headers(): Headers
{
    return new Headers(text: [
        EmailLabsTransport::HEADER_TEMPLATE => 'powitanie-v2',
        EmailLabsTransport::HEADER_VARS => json_encode(['name' => $this->user->name]),
        'X-TRACKING-OFF' => '1', // wyłącza śledzenie linków w tej wiadomości
    ]);
}
```

Pozostałe własne nagłówki trafiają do EmailLabs bez zmian. Obrazki inline (`$message->embed()`) są wskazywane nazwą
pliku, tak jak oczekuje EmailLabs.

## Wysyłka przez API

```php
use Beziworld\EmailLabs\Facades\EmailLabs;
use Beziworld\EmailLabs\Messages\EmailLabsMessage;

$result = EmailLabs::send(
    EmailLabsMessage::create('Cześć {{ name }}')
        ->from('sklep@example.com', 'Sklep')
        ->to('jan@example.com', 'Jan', vars: ['name' => 'Jan'], messageId: 'zamowienie-1001@example.com')
        ->to('anna@example.com', 'Anna', vars: ['name' => 'Anna'])
        ->html('<p>Cześć {{ name }}</p>')
        ->text('Cześć {{ name }}')
        ->tag('zamowienia')
        ->header('X-Order', '1001')
        ->attachData($pdf, 'faktura.pdf', 'application/pdf')
);

$result->messageIdFor('jan@example.com'); // zamowienie-1001@example.com
$result->isPartial();                     // true, gdy EmailLabs odpowiedział 207
$result->errors;                          // odrzuceni odbiorcy
```

Szablony:

```php
$id = EmailLabs::addTemplate('Powitanie', '<p>Drogi {{ name }}</p>', 'Drogi {{ name }}', externalId: 'powitanie-v2');

EmailLabs::send(EmailLabsMessage::create('Witamy')->from('sklep@example.com')
    ->to('jan@example.com', vars: ['name' => 'Jan'])
    ->template('powitanie-v2', ['shop' => 'Example']));

EmailLabs::templates();
EmailLabs::deleteTemplates('powitanie-v2');
```

## Statusy, otwarcia, kliknięcia i statystyki

```php
EmailLabs::email('zamowienie-1001@example.com');      // EmailEvent: status, statusTime, history
EmailLabs::emails(['to' => 'jan@example.com', 'tags' => ['zamowienia'], 'dateFrom' => now()->subDay()]);
EmailLabs::allEmails(['smtpAccount' => '1.twojpanel.smtp'])->each(fn ($event) => ...); // LazyCollection
EmailLabs::opens(['messageId' => 'zamowienie-1001@example.com']); // przechowywane 7 dni
EmailLabs::clicks(['dateFrom' => now()->subDays(2)]);
EmailLabs::statistics(now()->subWeek(), now(), withTags: true);   // dane zagregowane bez zmian
EmailLabs::smtpAccounts();
```

Statusy (`EmailStatus`): `Injected`, `Ok`, `Deferred`, `Softbounce`, `Hardbounce`, `Spambounce`, `Dropped`, `Open`,
`Click`, z pomocnikami `isDelivered()`, `isBounce()`, `isPermanentFailure()`, `isEngagement()`.

## Czarna lista

```php
use Beziworld\EmailLabs\Enums\BlacklistReason;

EmailLabs::addToBlacklist('jan@example.com', BlacklistReason::Unsubscribe, 'Żądanie RODO');
EmailLabs::isBlacklisted('jan@example.com');
EmailLabs::blacklist();                     // pierwsza strona (do 1000)
EmailLabs::allBlacklisted();                // LazyCollection po wszystkich stronach
EmailLabs::removeFromBlacklist(['jan@example.com']);
EmailLabs::addDomainToBlacklist('spam.example');
EmailLabs::blacklistReasons();              // hardbounce, unsubscribe, spam_complaint, other
```

## Webhooki

1. Włącz trasę:

   ```dotenv
   EMAILLABS_WEBHOOKS_ENABLED=true
   EMAILLABS_WEBHOOK_SECRET=sekret-wygenerowany-w-panelu
   ```

   Rejestruje `POST /emaillabs/webhook` (trasa `emaillabs.webhook`).

2. W panelu (Konto, Ustawienia, Webhooki, e-maile transakcyjne) wpisz URL, wygeneruj SecretKey i skopiuj go do
   `EMAILLABS_WEBHOOK_SECRET`. Przy włączonym Basic auth ustaw `webhook_username` i `webhook_password` połączenia.

3. Nasłuchuj zdarzeń:

   ```php
   use Beziworld\EmailLabs\Events\EmailBounced;
   use Beziworld\EmailLabs\Events\EmailDelivered;

   Event::listen(function (EmailBounced $event) {
       if ($event->event->status->isPermanentFailure()) {
           User::where('email', $event->event->to->email)->update(['email_bounced_at' => now()]);
       }
   });

   Event::listen(fn (EmailDelivered $event) => MailLog::where('message_id', $event->event->messageId)
       ->update(['delivered_at' => $event->event->statusTime]));
   ```

Middleware porównuje `X-Webhook-Checksum` z `sha1("{secret}|{X-Webhook-Date}|{Request-Id}")` dla sekretu każdego
połączenia (oraz `EmailLabs::resolveWebhookSecretsUsing()` dla tenantów), więc zdarzenie wie, z którego panelu
przyszło. Odpowiedź to zawsze `ok` z HTTP 200, jak wymaga EmailLabs; EmailLabs czeka tylko 500 ms, więc wolne listenery
kolejkuj. `WebhookReceived` zawiera wszystkie elementy żądania, także zdarzenia SMS i push, które nie są mapowane.
Otwarcia i kliknięcia przychodzą tylko przy włączonym śledzeniu na koncie SMTP.

## Wiele paneli i kont SMTP

```php
'connections' => [
    'default' => ['app_key' => env('EMAILLABS_APP_KEY'), 'secret_key' => env('EMAILLABS_SECRET_KEY'), 'smtp_account' => '1.sklep.smtp'],
    'marketing' => ['app_key' => env('EMAILLABS_MARKETING_APP_KEY'), 'secret_key' => env('EMAILLABS_MARKETING_SECRET_KEY'), 'smtp_account' => '1.news.smtp'],
],
```

```php
EmailLabs::connection('marketing')->send($message);
EmailLabs::connection()->forSmtpAccount('2.sklep.smtp')->send($message);
EmailLabs::using(['app_key' => $tenant->el_app_key, 'secret_key' => decrypt($tenant->el_secret), 'smtp_account' => $tenant->el_smtp]);
```

`php artisan emaillabs:smtp-accounts --connection=marketing` wyświetla konta SMTP i sprawdza klucze.

## Obsługa błędów

Wszystkie wyjątki dziedziczą po `Beziworld\EmailLabs\Exceptions\EmailLabsException`:

| Wyjątek | Kiedy |
|---|---|
| `ApiException` | EmailLabs odrzucił żądanie (`errorCode()`, np. `E-0-003`, `errorTitle()`, `httpStatus()`, `uniqId()`, `errors()`, `retryAfter()`, `isRateLimited()`, `isAuthenticationError()`, `isValidationError()`, `isNotFound()`) lub jest nieosiągalny |
| `InvalidMessage` | brak odbiorcy, nadawcy, tematu lub treści, ponad 200 odbiorców, błędny `X-EmailLabs-Vars` |
| `InvalidConfiguration` | brak kluczy lub konta SMTP, nieznane połączenie |

Transport poczty opakowuje je w `TransportException` z Symfony, czego oczekuje Laravel.

Odczyty (GET, OPTIONS) są ponawiane przy błędach połączenia, HTTP 5xx i HTTP 429 (z poszanowaniem `Retry-After`,
maks. 60 s). Wysyłka nigdy nie jest ponawiana automatycznie: żądanie, które przekroczyło czas, mogło zostać już
przyjęte.

## Testy w aplikacji

```php
Http::fake(['api.emaillabs.io/*' => Http::response(['meta' => ['status' => 200], 'data' => []])]);

Mail::to('jan@example.com')->send(new OrderShipped($order));

Http::assertSent(fn ($request) => $request->url() === 'https://api.emaillabs.io/v2.1/email');
```

## Rozwój

```bash
composer install
composer ci   # pint --test, phpstan (level 8), pest
```

## Autor

Tworzy i utrzymuje **Kamil Ćwiertnia**, [beziworld.eu](https://beziworld.eu).

- E-mail: [biuro@beziworld.eu](mailto:biuro@beziworld.eu)
- Zgłoszenia i propozycje: [GitHub issues](https://github.com/beziworld/laravel-emaillabs/issues)
- Podatności bezpieczeństwa: zobacz [SECURITY.md](SECURITY.md)

Potrzebujesz integracji? Skontaktuj się przez [beziworld.eu](https://beziworld.eu).

## Licencja

MIT. Zobacz [LICENSE.md](LICENSE.md). Pakiet nie jest powiązany z Vercom S.A. ani EmailLabs, nie jest przez nie
wspierany ani sponsorowany. „EmailLabs” jest znakiem towarowym jego właściciela.
