# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `emaillabs` mail transport (Symfony Mailer) on the EmailLabs API v2.1 with per-recipient Message-IDs, Cc, Bcc, Reply-To, attachments, inline images, tags, metadata, custom and control headers.
- `EmailLabsMessage` builder for direct sending with variables, global variables and templates; partial (HTTP 207) results.
- Templates, statuses, opens, clicks, aggregated statistics and SMTP accounts.
- Blacklist of addresses and domains.
- Webhook route with X-Webhook-Checksum verification, optional Basic auth and delivery, bounce, drop, deferral, open and click events.
- Multiple panels through named connections, runtime keys, per-mailer and per-message SMTP accounts and a webhook secret resolver.
- `emaillabs:smtp-accounts` artisan command.
