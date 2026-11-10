<?php

declare(strict_types=1);

namespace Beziworld\EmailLabs\Messages;

use Beziworld\EmailLabs\Data\Address;
use Beziworld\EmailLabs\Exceptions\InvalidMessage;

/**
 * Fluent builder of a transactional e-mail sent through POST /v2.1/email.
 *
 * Every "to" recipient receives a separate copy with its own Message-ID and
 * variables; "cc" and "bcc" receive copies of each message.
 */
final class EmailLabsMessage
{
    public const int MAX_RECIPIENTS = 200;

    private ?string $subject = null;

    private ?string $html = null;

    private ?string $text = null;

    private ?string $templateId = null;

    private ?Address $from = null;

    private ?Address $replyTo = null;

    private ?string $smtpAccount = null;

    /** @var list<array{email: string, name?: string, messageId?: string, vars?: array<string, mixed>}> */
    private array $to = [];

    /** @var list<Address> */
    private array $cc = [];

    /** @var list<Address> */
    private array $bcc = [];

    /** @var list<string> */
    private array $tags = [];

    /** @var array<string, string> */
    private array $headers = [];

    /** @var array<string, mixed> */
    private array $globalVars = [];

    /** @var list<array{fileName: string, fileMime: string, fileContent: string, inline: bool}> */
    private array $attachments = [];

    public static function create(?string $subject = null): self
    {
        $message = new self();
        $message->subject = $subject;

        return $message;
    }

    public function subject(string $subject): self
    {
        $this->subject = $subject;

        return $this;
    }

    public function html(string $html): self
    {
        $this->html = $html;

        return $this;
    }

    public function text(string $text): self
    {
        $this->text = $text;

        return $this;
    }

    /**
     * Sends a stored template; html and text may then be omitted.
     *
     * @param array<string, mixed> $globalVars Variables shared by every recipient.
     */
    public function template(string $templateId, array $globalVars = []): self
    {
        $this->templateId = $templateId;
        $this->globalVars = [...$this->globalVars, ...$globalVars];

        return $this;
    }

    public function from(string $email, ?string $name = null): self
    {
        $this->from = new Address($email, $name);

        return $this;
    }

    public function replyTo(string $email, ?string $name = null): self
    {
        $this->replyTo = new Address($email, $name);

        return $this;
    }

    /**
     * Adds a recipient with its own variables and an optional Message-ID (unique, in the form local@domain).
     *
     * @param array<string, mixed> $vars
     */
    public function to(string $email, ?string $name = null, array $vars = [], ?string $messageId = null): self
    {
        $recipient = ['email' => $email];

        if ($name !== null && $name !== '') {
            $recipient['name'] = $name;
        }

        if ($messageId !== null && $messageId !== '') {
            $recipient['messageId'] = $messageId;
        }

        if ($vars !== []) {
            $recipient['vars'] = $vars;
        }

        $this->to[] = $recipient;

        return $this;
    }

    public function cc(string $email, ?string $name = null): self
    {
        $this->cc[] = new Address($email, $name);

        return $this;
    }

    public function bcc(string $email, ?string $name = null): self
    {
        $this->bcc[] = new Address($email, $name);

        return $this;
    }

    public function tag(string ...$tags): self
    {
        foreach ($tags as $tag) {
            if (trim($tag) !== '' && ! in_array(trim($tag), $this->tags, true)) {
                $this->tags[] = trim($tag);
            }
        }

        return $this;
    }

    public function header(string $name, string $value): self
    {
        $this->headers[$name] = $value;

        return $this;
    }

    /**
     * @param array<string, mixed> $vars
     */
    public function globalVars(array $vars): self
    {
        $this->globalVars = [...$this->globalVars, ...$vars];

        return $this;
    }

    /**
     * Disables link tracking for this message (X-TRACKING-OFF header).
     */
    public function withoutTracking(): self
    {
        return $this->header('X-TRACKING-OFF', '1');
    }

    /**
     * Sends through another SMTP account of the panel than the configured default.
     */
    public function smtpAccount(string $smtpAccount): self
    {
        $this->smtpAccount = $smtpAccount;

        return $this;
    }

    /**
     * Attaches raw file contents; inline attachments are referenced in HTML as cid:{name}.
     */
    public function attachData(string $contents, string $name, string $mime = 'application/octet-stream', bool $inline = false): self
    {
        $this->attachments[] = [
            'fileName' => $name,
            'fileMime' => $mime,
            'fileContent' => base64_encode($contents),
            'inline' => $inline,
        ];

        return $this;
    }

    public function getSmtpAccount(): ?string
    {
        return $this->smtpAccount;
    }

    /**
     * @return list<string>
     */
    public function recipients(): array
    {
        return array_map(static fn(array $recipient): string => $recipient['email'], $this->to);
    }

    /**
     * Request body of POST /v2.1/email.
     *
     * @return array<string, mixed>
     *
     * @throws InvalidMessage When a required field is missing or there are too many recipients.
     */
    public function toPayload(?string $defaultSmtpAccount = null): array
    {
        $smtpAccount = $this->smtpAccount ?? $defaultSmtpAccount;

        if ($smtpAccount === null || $smtpAccount === '') {
            throw InvalidMessage::missing('smtpAccount');
        }

        if ($this->to === []) {
            throw InvalidMessage::missing('to');
        }

        if (count($this->to) > self::MAX_RECIPIENTS) {
            throw InvalidMessage::tooManyRecipients(count($this->to));
        }

        if ($this->from === null || $this->from->email === '') {
            throw InvalidMessage::missing('from');
        }

        if ($this->subject === null || trim($this->subject) === '') {
            throw InvalidMessage::missing('subject');
        }

        if ($this->templateId === null && ($this->html ?? '') === '' && ($this->text ?? '') === '') {
            throw InvalidMessage::missing('content (html, text or templateId)');
        }

        $content = array_filter([
            'html' => $this->html,
            'text' => $this->text,
            'templateId' => $this->templateId,
        ], static fn(?string $value): bool => $value !== null && $value !== '');

        $payload = [
            'subject' => $this->subject,
            'smtpAccount' => $smtpAccount,
            'from' => $this->from->toArray(),
            'to' => $this->to,
            'content' => $content,
        ];

        if ($this->replyTo !== null) {
            $payload['replyTo'] = $this->replyTo->toArray();
        }

        if ($this->cc !== []) {
            $payload['cc'] = array_map(static fn(Address $address): array => $address->toArray(), $this->cc);
        }

        if ($this->bcc !== []) {
            $payload['bcc'] = array_map(static fn(Address $address): array => $address->toArray(), $this->bcc);
        }

        if ($this->tags !== []) {
            $payload['tags'] = $this->tags;
        }

        if ($this->headers !== []) {
            $payload['headers'] = $this->headers;
        }

        if ($this->globalVars !== []) {
            $payload['globalVars'] = $this->globalVars;
        }

        if ($this->attachments !== []) {
            $payload['attachments'] = $this->attachments;
        }

        return $payload;
    }
}
