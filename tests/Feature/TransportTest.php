<?php

declare(strict_types=1);

use Beziworld\EmailLabs\Tests\TestCase;
use Beziworld\EmailLabs\Transport\EmailLabsTransport;
use Illuminate\Http\Client\Request;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Email;

it('registers the emaillabs mail transport', function (): void {
    $transport = Mail::mailer('emaillabs')->getSymfonyTransport();

    expect($transport)->toBeInstanceOf(EmailLabsTransport::class)
        ->and((string) $transport)->toBe('emaillabs://1.shop.smtp');
});

it('uses the connection and SMTP account of the mailer', function (): void {
    Http::fake(['api.emaillabs.io/*' => Http::response(TestCase::sendResponse())]);

    Mail::mailer('emaillabs-marketing')->raw('Spring sale', fn(Message $message) => $message->to('jane@example.com')->subject('Sale'));

    Http::assertSent(fn(Request $request): bool => $request->header('Application-Key') === ['marketing-app-key']
        && ($request->data()['smtpAccount'] ?? null) === '7.shop.smtp');
});

it('maps a Laravel mailable to the EmailLabs payload', function (): void {
    Http::fake(['api.emaillabs.io/*' => Http::response(TestCase::sendResponse(['jane@example.com', 'john@example.com']))]);

    $mailable = new class extends Mailable {
        public function envelope(): Envelope
        {
            return new Envelope(subject: 'Order shipped', tags: ['orders', 'shipping'], metadata: ['order_id' => '1001']);
        }

        public function content(): Content
        {
            return new Content(htmlString: '<p>Your order has shipped</p>');
        }
    };

    $sent = Mail::to([['email' => 'jane@example.com', 'name' => 'Jane'], 'john@example.com'])
        ->cc('boss@example.com')
        ->bcc('archive@example.com')
        ->send($mailable->replyTo('help@example.com', 'Help'));

    Http::assertSent(function (Request $request): bool {
        $body = $request->data();
        $to = $body['to'] ?? [];

        return $request->url() === 'https://api.emaillabs.io/v2.1/email'
            && $body['subject'] === 'Order shipped'
            && $body['smtpAccount'] === '1.shop.smtp'
            && $body['from'] === ['email' => 'shop@example.com', 'name' => 'Shop']
            && count($to) === 2
            && $to[0]['email'] === 'jane@example.com' && $to[0]['name'] === 'Jane'
            && str_ends_with($to[0]['messageId'], '@example.com')
            && $to[0]['messageId'] !== $to[1]['messageId']
            && $body['cc'] === [['email' => 'boss@example.com']]
            && $body['bcc'] === [['email' => 'archive@example.com']]
            && $body['replyTo'] === ['email' => 'help@example.com', 'name' => 'Help']
            && $body['tags'] === ['orders', 'shipping']
            && ($body['headers']['X-Metadata-order_id'] ?? null) === '1001'
            && $body['content']['html'] === '<p>Your order has shipped</p>';
    });

    expect($sent?->getMessageId())->toBe(md5('jane@example.com') . '@example.com');
});

it('honours the control headers for SMTP account, template and variables', function (): void {
    Http::fake(['api.emaillabs.io/*' => Http::response(TestCase::sendResponse())]);

    Mail::raw('fallback', function (Message $message): void {
        $message->to('jane@example.com')->subject('Welcome');
        $message->getHeaders()->addTextHeader(EmailLabsTransport::HEADER_SMTP_ACCOUNT, '9.shop.smtp');
        $message->getHeaders()->addTextHeader(EmailLabsTransport::HEADER_TEMPLATE, 'welcome-v2');
        $message->getHeaders()->addTextHeader(EmailLabsTransport::HEADER_VARS, json_encode(['name' => 'Jane'], JSON_THROW_ON_ERROR));
        $message->getHeaders()->addTextHeader('X-TRACKING-OFF', '1');
    });

    Http::assertSent(function (Request $request): bool {
        $body = $request->data();

        return $body['smtpAccount'] === '9.shop.smtp'
            && $body['content']['templateId'] === 'welcome-v2'
            && $body['content']['text'] === 'fallback'
            && $body['globalVars'] === ['name' => 'Jane']
            && $body['headers'] === ['X-TRACKING-OFF' => '1'];
    });
});

it('sends attachments and rewrites inline content ids to file names', function (): void {
    Http::fake(['api.emaillabs.io/*' => Http::response(TestCase::sendResponse())]);

    Mail::html('<p>x</p>', function (Message $message): void {
        $cid = $message->embedData('PNGDATA', 'logo.png', 'image/png');
        $message->to('jane@example.com')->subject('Logo')->html('<img src="' . $cid . '">');
        $message->attachData('PDF', 'invoice.pdf', ['mime' => 'application/pdf']);
    });

    Http::assertSent(function (Request $request): bool {
        $body = $request->data();
        $attachments = collect($body['attachments'] ?? [])->keyBy('fileName');

        return $body['content']['html'] === '<img src="cid:logo.png">'
            && $attachments['logo.png']['inline'] === true
            && $attachments['logo.png']['fileMime'] === 'image/png'
            && $attachments['logo.png']['fileContent'] === base64_encode('PNGDATA')
            && $attachments['invoice.pdf']['inline'] === false
            && $attachments['invoice.pdf']['fileMime'] === 'application/pdf';
    });
});

it('wraps API errors in a Symfony transport exception', function (): void {
    Http::fake(['api.emaillabs.io/*' => Http::response(['errors' => [['title' => 'ValidationError', 'message' => 'Field from is invalid', 'code' => 'E-01-01']], 'meta' => ['status' => 400]], 400)]);

    expect(fn() => Mail::raw('x', fn(Message $message) => $message->to('jane@example.com')->subject('x')))
        ->toThrow(TransportException::class, 'Field from is invalid');
});

it('fails when every recipient was rejected', function (): void {
    Http::fake(['api.emaillabs.io/*' => Http::response(['meta' => ['status' => 207], 'data' => [], 'errors' => [['title' => 'ValidationError', 'message' => 'invalid', 'code' => 'E-01-01']]], 207)]);

    expect(fn() => Mail::raw('x', fn(Message $message) => $message->to('jane@example.com')->subject('x')))
        ->toThrow(TransportException::class, 'rejected every recipient');
});

it('rejects a variables header that is not a JSON object', function (): void {
    $transport = Mail::mailer('emaillabs')->getSymfonyTransport();
    $email = (new Email())->from('shop@example.com')->to('jane@example.com')->subject('x')->text('x');
    $email->getHeaders()->addTextHeader(EmailLabsTransport::HEADER_VARS, '[1,2]');

    expect(fn() => $transport->toEmailLabsMessage($email))->toThrow(\Beziworld\EmailLabs\Exceptions\InvalidMessage::class, 'JSON object');
});
