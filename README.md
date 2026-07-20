<h1 align="center">DukaRelay</h1>

<p align="center">
  <strong>Reliable WhatsApp order notifications for WooCommerce.</strong><br>
  Self-hosted · your own official WhatsApp Cloud API connection · no monthly SaaS, no middleman.
</p>

<p align="center">
  <img alt="Status" src="https://img.shields.io/badge/status-alpha-orange">
  <img alt="Version" src="https://img.shields.io/badge/version-0.1.0--alpha-blue">
  <img alt="WordPress" src="https://img.shields.io/badge/WordPress-6.4%2B-21759b">
  <img alt="PHP" src="https://img.shields.io/badge/PHP-7.4%2B-777bb4">
  <img alt="License" src="https://img.shields.io/badge/license-GPLv2%2B-green">
</p>

---

## What it is

DukaRelay sends WooCommerce order and status notifications over WhatsApp using **your own** official WhatsApp Cloud API connection. You pay Meta directly — there is no monthly SaaS subscription and no per-message markup from a middleman. Your store data and WhatsApp credentials never leave your server.

It is built to fix the two things the WhatsApp-notification plugin category consistently gets wrong: **reliability** (messages that silently stop sending) and **transparency** (no way to see what actually happened to a message).

> **Alpha.** Core order notifications and the reliability layer are the current focus. A full two-way inbox and marketing features are on the roadmap below.

## Why it's different

Most WhatsApp-for-WooCommerce plugins either route your messages through a third-party SaaS (monthly fee + per-message markup + your customer data on their servers) or connect the API but fail quietly the moment a token expires. DukaRelay's wedge is **the category's known rough edges, fixed by design**:

| Rough edge elsewhere | DukaRelay |
|---|---|
| Messages fail silently | Every message logged with real delivery status + plain-English failure reason |
| You find out the API broke when customers complain | Token-health watchdog warns you *before* messages stop — in wp-admin **and** forwarded to your phone |
| Templates limited to a few fixed variables | Unlimited variables, including product/item names and shipping tracking links |
| Your data sits on a vendor's servers | Nothing phones home; credentials stay on your server |
| Monthly subscription + per-message cut | You pay Meta directly, DukaRelay takes nothing |

## Features

**Shipping in release 0.1**
- WhatsApp notifications on WooCommerce order and status changes
- Guided, step-by-step **Connection Wizard** — each step proves itself with a live API call before advancing
- **Message Ledger** — one table, single source of truth, every message with its delivery status
- **Delivery log** admin page — see exactly what was sent, to whom, and why anything failed
- Editable message **templates** with unlimited variables (item names, tracking links, order fields)
- **Token-health watchdog** — check-on-send that never fails silently, plus a WP-Cron heartbeat for early warning
- **Inbound forward** — a customer message to your Store Number is relayed one-way to your personal phone

**On the roadmap**
- Two-way **Swipe-Reply Routing** (reply from your phone → routed back to the customer)
- Marketing-class messages with durable, audited opt-in consent
- Standalone Core (WhatsApp relay on any WordPress site, WooCommerce not required)

## How it works

DukaRelay always connects a **new, second phone number** — the *Store Number* — to the WhatsApp Cloud API. A number registered to the Cloud API can no longer be used in the normal WhatsApp app (this is a one-way door), so onboarding deliberately **refuses to register your existing personal/business number**. That *Primary Number* stays untouched in the WhatsApp app and serves as the destination for relayed messages.

The plugin is split into **Core** and **Modules**:

```
Core (loads on any WordPress site)
├── Connection + Cloud API sender
├── Webhook receiver
├── Message Ledger (single source of truth)
├── Templates
├── Token-health watchdog
└── Inbound relay

WooCommerce Module (loads only if WooCommerce is active AND enabled)
└── Order/status → notification mapping
```

The dependency points **module → Core, never the reverse**. Inactive modules load no files, hooks, or assets.

## Installation

1. Install and activate the plugin.
2. Open **DukaRelay → Connection** and follow the setup wizard to connect your WhatsApp Cloud API number.
3. Choose which order statuses send notifications, and edit your message templates.

A written setup guide walks through creating your Meta app and connecting a **new** WhatsApp number — your existing personal/business WhatsApp number stays untouched.

## FAQ

**Do I need a separate WhatsApp number?**
Yes. A number connected to the WhatsApp Cloud API can no longer be used in the normal WhatsApp app, so DukaRelay always uses a new, second number for your store. Your existing number stays exactly as it is.

**Is there a monthly fee?**
No. DukaRelay is self-hosted and connects to your own Meta account. You pay Meta directly for messaging (many message types are free or very cheap); DukaRelay takes no cut.

**What about sending limits?**
Full transparency: an *unverified* WhatsApp business can send business-initiated messages to a limited number of customers per 24 hours (currently 250) until you complete Meta's free Business Verification. Customer-initiated conversations are not limited the same way. DukaRelay shows you exactly where you stand.

## Requirements

- WordPress 6.4+
- PHP 7.4+
- WooCommerce (for the order-notification module)
- A Meta Business account + a spare phone number for the WhatsApp Cloud API

## Development

```bash
composer install     # dev tooling (PHP_CodeSniffer + WPCS)
composer lint        # phpcs against WordPress Coding Standards
composer lint:fix    # phpcbf autofix
```

Engineering entry point and subsystem docs live in [`docs/README.md`](docs/README.md). The plugin is built to WordPress.org review + WPCS standards.

## License

[GPLv2 or later](LICENSE). Made in Zimbabwe by [Nigel Rodgers](https://github.com/NigelRodgers).
