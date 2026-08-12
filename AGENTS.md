# Working on Issabel Power Dialer

## Purpose

This repository provides the **Gestión de Clientes** module for Issabel 4. It imports client portfolios, groups multiple phone numbers per client, assigns clients to agents, starts agent-first calls through Asterisk, records outcomes/callbacks, and exposes agent and supervisor views.

It is independent of Issabel's traditional dialer. Do not write to `call_center.campaign`, `calls`, or `call_attribute`; the module owns the `gestion_clientes` database and `gc_*` tables.

## Stack and constraints

- Issabel 4, PHP 5.4, Smarty, MySQL/MariaDB, Asterisk 11 and legacy `SIP/<extension>` channels.
- Production server: `192.168.10.5`; repository copy: `/root/issabel-powerdialer`; live module: `/var/www/html/modules/gestion_clientes`.
- PHP must remain 5.4-compatible: no scalar/return type declarations, short array syntax, null coalescing, anonymous classes, or other modern-only syntax.
- Secrets belong in `/etc/issabel/gestion_clientes.conf.php` and `/etc/issabel/gestion_clientes_ami.secret`, never in Git or the web root.
- Agent identity belongs to the person; the SIP extension is a session-selected seat and may change.
- Business outcomes are informational. Only callbacks have scheduling behavior.
- Never restart or reload Asterisk while agents are calling. Module-only PHP/Smarty/CSS/JS deployments do not require an Asterisk reload.
- Treat database migrations, menu/ACL changes, AMI changes, dialplan changes, cron installation, and destructive rollback as separately reviewed production operations.

## Important paths

- `module/gestion_clientes/`: Issabel module, controllers, services, templates and assets.
- `install/schema.sql`, `install/migrations/`: database schema and upgrades.
- `asterisk/`: reviewed dialplan and AMI examples; installation is intentionally manual.
- `bin/finalize_call.php`: immediate post-call settlement from the dialplan.
- `bin/reconcile_cdr.php`: fallback CDR reconciliation.
- `bin/cleanup_claims.php`: safely returns abandoned untouched clients to the queue.
- `bin/health_check.php`, `bin/production_check.sh`: database and integration readiness checks.
- `bin/backup.sh`, `bin/verify_backup.sh`: private, checksummed production backups.
- `tests/run.php`: main behavior and regression suite.
- `docs/architecture.md`, `docs/deployment.md`, `docs/rollback.md`: authoritative operational guidance.
- `docs/plans/issabel-client-assignment-module.md`: original product and implementation plan.

## Development rules

1. Read the relevant service, template, test, and architecture section before editing.
2. Preserve server-side authorization, ownership, CSRF, idempotency, prepared SQL, and transaction/locking checks.
3. Do not trust client-supplied agent, seat, campaign, client, or call state.
4. Keep telephony state separate from the agent's business outcome.
5. Escape imported data when rendering. Normalize and validate every dialed number.
6. Avoid unrelated formatting or broad rewrites; production runs an old and sensitive stack.
7. Add or update a regression test for behavior changes.

## Verification

Run before committing:

```sh
make check
make test
make install-smoke
make shell-check
make db-test
git diff --check
```

`make test` requires PHP. If local PHP is unavailable, `make check` still performs static PHP 5.4 checks; run `php tests/run.php` on the Issabel server before declaring success. The current expected result is **56 tests, 0 failures**; update this number when tests are added.

For Smarty changes, compile the template on Issabel before publishing because its real PHP/Smarty versions are authoritative.

## Deployment

The server has no Git client. Transfer the changed repository files into `/root/issabel-powerdialer`, then deploy from that complete source tree.

For module-only code or UI changes with no schema change:

```sh
cd /root/issabel-powerdialer
./install/install.sh --skip-db
php tests/run.php
```

For an approved schema installation or migration, provide the database password through `MYSQL_PWD` and omit `--skip-db`:

```sh
cd /root/issabel-powerdialer
MYSQL_PWD='...' ./install/install.sh \
  --module-root /var/www/html/modules \
  --db-host 127.0.0.1 --db-user root --db-name gestion_clientes
php tests/run.php
```

The installer builds a staged module, atomically replaces the live tree, and archives the previous version as `/var/www/html/modules/.gestion_clientes.previous.*`. It does **not** install menu/ACL, AMI, dialplan, or cron changes.

Operational CLI tools are installed separately with `install/install-operations.sh`. It does not replace cron unless `--install-cron` is explicitly supplied in an approved window. Apply schema migration 6 before installing the updated reconciler.

After verification, commit and push to `main`. Do not commit credentials, dumps, uploaded CSVs, generated files, or production configuration.

## Production safety

- Confirm whether agents are actively calling before any telephony work.
- UI/module-only changes are safe to deploy without touching Asterisk.
- Dialplan or AMI changes require validation and an approved quiet window.
- Use synthetic clients and controlled phone destinations for call tests.
- Do not retry ambiguous calls: inspect `gc_attempt`, Asterisk/CDR state, and logs first.
- Preserve the installer's `.previous.*` archive and follow `docs/rollback.md` for recovery.
- Run `gestion-clientes-production-check` before a pilot. Exit `2` means do not dial; exit `1` requires operational review.
