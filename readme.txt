=== DukaRelay ===
Contributors: nigelrodgers
Tags: whatsapp, woocommerce, notifications, order notifications, cloud api
Requires at least: 6.4
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.1.0-alpha
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Reliable WhatsApp order notifications for WooCommerce, using your own official WhatsApp Cloud API connection. Self-hosted. No monthly SaaS, no middleman.

== Description ==

DukaRelay sends WooCommerce order and status notifications over WhatsApp using **your own** official WhatsApp Cloud API connection — so you pay Meta directly, with no monthly SaaS subscription and no per-message markup from a middleman.

Built for reliability and transparency, the two things the category gets wrong:

* **Never fails silently.** Every message is logged with its real delivery status and a plain-English reason if it fails.
* **Connection health monitoring.** DukaRelay watches your API connection and warns you *before* messages stop going out.
* **Templates with unlimited variables**, including product/item names and shipping tracking links.
* **Safe by design.** Your data stays on your site. No phone-home. Your WhatsApp credentials never leave your server.

> This is an early (alpha) release. Core order notifications and reliability features are the focus; a full two-way inbox and other features are on the roadmap.

**Important, up front (full transparency):** an *unverified* WhatsApp business can send business-initiated messages to a limited number of customers per day (currently 250 per 24 hours) until you complete Meta's free Business Verification. Customer-initiated conversations are not limited in the same way. DukaRelay shows you exactly where you stand.

== Installation ==

1. Install and activate the plugin.
2. Open **DukaRelay → Connection** and follow the setup wizard to connect your WhatsApp Cloud API number.
3. Choose which order statuses send notifications, and edit your message templates.

A written setup guide walks through creating your Meta app and connecting a **new** WhatsApp number (your existing personal/business WhatsApp number stays untouched).

== Frequently Asked Questions ==

= Do I need a separate WhatsApp number? =
Yes. A number connected to the WhatsApp Cloud API can no longer be used in the normal WhatsApp app, so DukaRelay always uses a new, second number for your store. Your existing number stays exactly as it is.

= Is there a monthly fee? =
No. DukaRelay is self-hosted and connects to your own Meta account. You pay Meta directly for messaging (many message types are free or very cheap); DukaRelay takes no cut.

== Changelog ==

= 0.1.0-alpha =
* Initial scaffold: plugin structure, message ledger, activation/uninstall.
