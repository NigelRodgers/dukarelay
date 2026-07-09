# DukaRelay — developer docs

Engineering entry point for the plugin. The **planning source of truth** (venture research, teardown, validation, the full build spec) lives in the venture workspace at `C:\Users\User\indie-ventures\dukarelay`. This folder holds what a developer needs at the code.

## Read these before writing code
1. **Coding standards** — [../docs/dev/coding-standards.md] *(canonical copy in the venture workspace: `indie-ventures/dukarelay/docs/dev/coding-standards.md`)*. Drives both the dev loop and the review-loop checklist. Non-negotiable.
2. **Domain glossary** — `indie-ventures/dukarelay/CONTEXT.md`. The ubiquitous language (Store Number vs Primary, Core vs Module, Message Ledger, Conversation, Order/Trade Comms, Templates, Consent).
3. **Decision records** — `indie-ventures/dukarelay/docs/adr/`:
   - ADR-0001 — new Store Number, protected Primary Number.
   - ADR-0002 — one message ledger, nullable Order link.
   - ADR-0003 — plugin vs operator-tooling boundary.
4. **Build spec (release 0.1)** — `indie-ventures/dukarelay/11-v1-build-spec.md`. Scope, schema, flows.

## Architecture in one breath
Core loads on any WordPress site (connection, ledger, templates, webhook, token-health, relay). The WooCommerce module loads only if WooCommerce is present **and** the user enabled it. Module depends on Core; never the reverse.

## Subsystem narrative docs (written as each is built, for a non-coder reader)
- [ ] `connection.md`
- [ ] `webhook.md`
- [ ] `ledger.md`
- [ ] `token-health.md`
- [ ] `templates.md`
- [ ] `woo-module.md`

## Current state
Scaffold only: the plugin loads, creates its tables on activation, and cleans up on uninstall. Core/module classes are stubbed (commented `require_once` lines in `includes/class-dukarelay-plugin.php`) and filled in through release 0.1.

## Local tooling not yet on PATH
`php`, `composer`, `wp` are not on the machine PATH — PHP lives inside the WordPress Studio / wp-support stacks. Lint (`phpcs`) and load-testing run against a Studio site, not bare CLI.
