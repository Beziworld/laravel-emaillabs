<?php

declare(strict_types=1);

namespace Beziworld\EmailLabs\Transport;

use Beziworld\EmailLabs\Data\SendResult;
use Beziworld\EmailLabs\EmailLabsClient;
use Beziworld\EmailLabs\Exceptions\EmailLabsException;
use Beziworld\EmailLabs\Exceptions\InvalidMessage;
use Beziworld\EmailLabs\Messages\EmailLabsMessage;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Header\MetadataHeader;
use Symfony\Component\Mailer\Header\TagHeader;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\MessageConverter;

/**
 * Symfony Mailer transport sending through the EmailLabs API (registered as the "emaillabs" mail transport).
 *
 * The e-mail is mapped to POST /v2.1/email: every "To" recipient receives
 * its own copy with a generated Message-ID, Cc and Bcc are passed as copies,
 * attachments are base64 encoded and inline parts are referenced by file
 * name, as EmailLabs expects ("cid:" references to generated content IDs
 * are rewritten). Tags (Mailable::tag()) are
 * sent as EmailLabs tags, metadata (Mailable::metadata()) as X-Metadata-*
 * headers and other custom headers unchanged. The control headers
 * X-EmailLabs-Smtp-Account, X-EmailLabs-Template and X-EmailLabs-Vars (JSON
 * object) select the SMTP account, a stored template and its variables.
 */
final class EmailLabsTransport extends AbstractTransport
{
    public const string HEADER_SMTP_ACCOUNT = 'X-EmailLabs-Smtp-Account';

    public const string HEADER_TEMPLATE = 'X-EmailLabs-Template';

    public const string HEADER_VARS = 'X-EmailLabs-Vars';

    /** Header names that are part of the e-mail structure and never forwarded as custom headers. */
    private const array RESERVED_HEADERS = [
        'from', 'to', 'cc', 'bcc', 'reply-to', 'sender', 'subject', 'date', 'message-id', 'mime-version',
        'content-type', 'content-transfer-encoding', 'return-path', 'x-emaillabs-smtp-account',
        'x-emaillabs-template', 'x-emaillabs-vars',
    ];

    private ?SendResult $lastResult = null;

    public function __construct(
        private readonly EmailLabsClient $client,
        ?EventDispatcherInterface $dispatcher = null,
        ?LoggerInterface $logger = null,
    ) {
        parent::__construct($dispatcher, $logger);
    }

    public function __toString(): string
    {
        return 'emaillabs://' . ($this->client->config()->smtpAccount ?? $this->client->connectionName());
    }

    /**
     * Result of the last send request; Message-IDs of every recipient and partial rejections.
     */
    public function lastResult(): ?SendResult
    {
        return $this->lastResult;
    }

    /**
     * Converts a Symfony e-mail to the EmailLabs message builder.
     *
     * @throws InvalidMessage When the e-mail cannot be represented.
     */
    public function toEmailLabsMessage(Email $email): EmailLabsMessage
    {
        $headers = $email->getHeaders();
        $from = $email->getFrom()[0] ?? null;

        if ($from === null) {
            throw InvalidMessage::missing('from');
        }

        $message = EmailLabsMessage::create((string) $email->getSubject())
            ->from($from->getAddress(), self::name($from));

        $domain = substr((string) strrchr($from->getAddress(), '@'), 1);
        $domain = $domain !== '' ? $domain : 'emaillabs.local';

        foreach ($email->getTo() as $recipient) {
            $message->to($recipient->getAddress(), self::name($recipient), messageId: bin2hex(random_bytes(16)) . '@' . $domain);
        }

        foreach ($email->getCc() as $recipient) {
            $message->cc($recipient->getAddress(), self::name($recipient));
        }

        foreach ($email->getBcc() as $recipient) {
            $message->bcc($recipient->getAddress(), self::name($recipient));
        }

        if (($replyTo = $email->getReplyTo()[0] ?? null) !== null) {
            $message->replyTo($replyTo->getAddress(), self::name($replyTo));
        }

        $html = $email->getHtmlBody();
        $text = $email->getTextBody();
        $html = self::body($html);
        $cids = [];

        foreach ($email->getAttachments() as $attachment) {
            $inline = $attachment->getDisposition() === 'inline';
            $name = $attachment->getFilename() ?? ($inline ? 'inline-' . count($cids) : 'attachment');

            if ($inline && $attachment->hasContentId()) {
                $cids['cid:' . $attachment->getContentId()] = 'cid:' . $name;
            }

            $message->attachData(
                $attachment->getBody(),
                $name,
                $attachment->getMediaType() . '/' . $attachment->getMediaSubtype(),
                $inline,
            );
        }

        if ($html !== null) {
            $message->html($cids !== [] ? strtr($html, $cids) : $html);
        }

        $text = self::body($text);

        if ($text !== null) {
            $message->text($text);
        }

        if (($account = $headers->get(self::HEADER_SMTP_ACCOUNT)) !== null && trim($account->getBodyAsString()) !== '') {
            $message->smtpAccount(trim($account->getBodyAsString()));
        }

        if (($template = $headers->get(self::HEADER_TEMPLATE)) !== null && trim($template->getBodyAsString()) !== '') {
            $message->template(trim($template->getBodyAsString()));
        }

        if (($vars = $headers->get(self::HEADER_VARS)) !== null) {
            $decoded = json_decode($vars->getBodyAsString(), true);

            if (! is_array($decoded) || array_is_list($decoded) && $decoded !== []) {
                throw InvalidMessage::invalidVariables(self::HEADER_VARS);
            }

            /** @var array<string, mixed> $decoded */
            $message->globalVars($decoded);
        }

        foreach ($headers->all() as $header) {
            if ($header instanceof TagHeader) {
                $message->tag($header->getValue());

                continue;
            }

            if ($header instanceof MetadataHeader) {
                $message->header('X-Metadata-' . $header->getKey(), $header->getValue());

                continue;
            }

            if (! in_array(strtolower($header->getName()), self::RESERVED_HEADERS, true)) {
                $message->header($header->getName(), $header->getBodyAsString());
            }
        }

        return $message;
    }

    protected function doSend(SentMessage $message): void
    {
        try {
            $original = $message->getOriginalMessage();

            if (! $original instanceof Message) {
                throw new TransportException('EmailLabs can only send structured e-mails, not raw messages.');
            }

            $email = MessageConverter::toEmail($original);
            $request = $this->toEmailLabsMessage($email);
            $result = $this->client->send($request);
        } catch (EmailLabsException $exception) {
            throw new TransportException('EmailLabs could not send the e-mail: ' . $exception->getMessage(), 0, $exception);
        }

        $this->lastResult = $result;
        $messageId = $result->messageIds()[0] ?? null;

        if ($messageId !== null) {
            $message->setMessageId($messageId);
        }

        if ($result->messages === [] && $result->isPartial()) {
            throw new TransportException('EmailLabs rejected every recipient of the e-mail.');
        }
    }

    /**
     * Reads a body given as a string or a stream.
     *
     * @param resource|string|null $body
     */
    private static function body(mixed $body): ?string
    {
        if (is_string($body) || $body === null) {
            return $body;
        }

        $contents = stream_get_contents($body);

        return $contents !== false ? $contents : null;
    }

    private static function name(Address $address): ?string
    {
        return $address->getName() !== '' ? $address->getName() : null;
    }
}
