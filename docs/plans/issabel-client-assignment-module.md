# Issabel Client Assignment and Follow-up Module Implementation Plan

> **For Hermes:** Use the `subagent-driven-development` skill to implement this plan task-by-task. Production changes require Jaime's explicit approval after the relevant design and rollback are explained.

**Goal:** Build a native Issabel module that assigns a fixed portfolio of clients to existing agents, supports ordered multi-phone calling and callbacks, records business outcomes, and provides operational statistics without changing the behavior of the traditional Call Center dialer.

**Architecture:** Create a separate Issabel PHP/Smarty module named `gestion_clientes`, with its own MySQL tables and state machine. Reuse Issabel web authentication, existing Call Center agent identities, SIP extensions, Asterisk outbound routes, CDRs, and recordings, but do not write to `call_center.campaign`, `calls`, or `call_attribute`. Calls are initiated through a restricted local AMI account and a small custom dialplan context; call results are correlated back to module attempts by a unique attempt ID.

**Tech Stack:** Issabel 4 module conventions, PHP 5.4-compatible code, Smarty, jQuery/vanilla JavaScript, MySQL/MariaDB, SQLite menu/ACL integration, Asterisk 11 AMI, SIP/Zoiper, CDR, MixMonitor or the existing recording policy, PHPUnit-compatible tests plus shell integration tests.

---

## 1. Verified platform context

Read-only inspection on July 31, 2026 confirmed:

- Issabel server: `192.168.10.5`
- PHP CLI: 5.4.16
- Asterisk: 11.25.3
- Existing native modules use `/var/www/html/modules/<module>/`
- Native module entry points expose `_moduleContent(&$smarty, $module_name)`
- Existing modules use `configs/default.conf.php`, `libs/`, `themes/default/`, `lang/`, and `index.php`
- Issabel menu records live in `/var/www/db/menu.db`, table `menu`
- Call Center customer attributes currently flow through `call_attribute -> ECCP -> agent_console`, but this new module will own its customer records and will not require ECCP for MVP operation

Constraints:

- All production PHP must run on PHP 5.4. Do not use namespaces, scalar type declarations, return types, null coalescing, anonymous classes, generators, or modern framework dependencies
- Asterisk is version 11. Do not assume modern ARI or PJSIP functionality
- The system is operational. Installation, dialplan reloads, AMI changes, and test calls require a backup, impact review, and explicit approval
- Existing Call Center campaigns and agents must continue working unchanged

## 2. Product scope

### 2.1 Roles

**Administrator/supervisor**

- Create and configure a portfolio campaign
- Import a client base with arbitrary fields and one or more phone columns
- Preview and validate the import before committing it
- Assign a quantity of clients to an existing agent, for example 50 to agent 506
- Reassign only eligible pending clients, with an audit record
- Configure business outcomes and their next action
- Monitor campaign and agent statistics
- Export operational data

**Agent**

- Authenticate with the existing Issabel web user
- Use the existing SIP extension in Zoiper
- See only clients currently assigned to that agent
- See the current client, ordered phone numbers, all imported fields, prior attempts, notes, and callbacks
- Start a call manually
- Try another number when the prior number does not answer
- Save a business outcome and notes
- Schedule a callback at a specific date and time
- Continue to the next eligible client

### 2.2 MVP

The first usable release includes:

1. Campaign creation
2. CSV import with preview, field mapping, and phone validation
3. Existing-user/agent/extension mapping
4. Fixed client assignment by quantity
5. Agent work queue
6. Ordered multi-phone display
7. Manual click-to-call through Asterisk
8. Outcome, notes, callback, and next-client workflow
9. Complete client and call-attempt audit history
10. Campaign, agent, date, and result dashboards
11. CSV export

### 2.3 Post-MVP

Implement only after the manual workflow is proven in production:

- Automatic dialing of the next client or next eligible phone
- Browser notifications for due callbacks
- Supervisor reassignment in bulk
- Live AMI event listener for second-by-second call state
- Advanced campaign filters and assignment rules
- Recording playback from the module
- Optional ECCP-based agent pause/readiness synchronization

## 3. Key design decisions

### 3.1 Separate domain from traditional Call Center

The module must not insert or mutate records in the traditional dialer's `campaign`, `calls`, or `call_attribute` tables. It may read existing agent metadata and CDR data. This avoids collisions with dialer retries, campaign completion logic, and ECCP state.

### 3.2 Existing identity with explicit mapping

Do not assume that Issabel username, Call Center agent number, and SIP extension are identical. Maintain a verified mapping:

- Issabel web username
- `call_center.agent.id` or agent number
- SIP extension used in Zoiper
- active/inactive flag

The agent workspace resolves the logged-in Issabel user to exactly one active mapping. A missing or ambiguous mapping blocks calling and shows a supervisor-readable error.

### 3.3 Business result and telephony result are different

Store both:

- Technical result: originated, ringing, answered, busy, no answer, failed, canceled, duration, billable/talk time
- Business outcome: interested, not interested, sale, invalid number, callback, pending, or campaign-specific values

Never infer a sale or interest from CDR disposition. Never treat agent-selected “no answer” as authoritative technical proof if CDR says otherwise; preserve both values for reconciliation.

### 3.4 Client-level state machine

Recommended canonical client states:

- `PENDING`: assigned but not yet attempted
- `IN_PROGRESS`: currently claimed by an agent/tab
- `NO_CONTACT`: attempted but no successful contact yet
- `CALLBACK`: callback scheduled
- `INTERESTED`: contacted and interested
- `SALE`: completed sale
- `NOT_INTERESTED`: contacted and declined
- `INVALID`: all usable numbers invalid
- `EXHAUSTED`: no eligible phone remains
- `CLOSED_OTHER`: terminal custom outcome

Each configured outcome defines:

- resulting client state
- whether the state is terminal
- whether callback date/time is required
- whether the next client should load
- whether the current client remains pending
- whether the selected phone is marked invalid or exhausted

### 3.5 Safe concurrency

Every “current client,” “start call,” and “save outcome” operation must validate ownership server-side. Use MySQL transactions and `SELECT ... FOR UPDATE` to prevent two browser tabs from taking the same client or creating duplicate attempts. Every mutating request receives an idempotency token.

### 3.6 Call origination

MVP call sequence:

1. Agent chooses an eligible phone
2. Module locks and validates the assignment
3. Module creates an attempt with a random correlation token
4. Module connects to a dedicated AMI user on `127.0.0.1`
5. Asterisk calls the agent's SIP extension first
6. After the agent answers, Asterisk enters `gestion-clientes-outbound` and sends the customer number through the existing `from-internal` route
7. The attempt token is inherited to all call legs and written to CDR `accountcode` or `userfield`
8. CDR reconciliation updates technical disposition, linked ID, duration, billsec, and recording reference

The discovery spike must verify the exact CDR behavior before this design is finalized. Specifically test agent-no-answer, customer-no-answer, busy, answered, and agent/customer hangup cases.

### 3.7 Readiness and auto-dial

For MVP, show extension readiness from Asterisk/AMI but require the agent to click “Llamar.” Do not auto-originate merely because a web session is active.

Post-MVP auto-next may run only when:

- campaign has auto-next enabled
- agent explicitly enables auto mode
- SIP extension is registered and not in use
- no active attempt exists
- no unsaved business disposition exists
- the previous call has ended
- a short visible countdown can be canceled

## 4. Proposed repository and deployment layout

Create a source repository before touching production:

```text
gestion-clientes-issabel/
├── README.md
├── CHANGELOG.md
├── docs/
│   ├── architecture.md
│   ├── data-dictionary.md
│   ├── deployment.md
│   ├── rollback.md
│   └── operator-guide.md
├── module/
│   └── gestion_clientes/
│       ├── index.php
│       ├── configs/default.conf.php
│       ├── libs/
│       │   ├── paloSantoGestionClientes.class.php
│       │   ├── GestionClientesAuth.class.php
│       │   ├── GestionClientesImport.class.php
│       │   ├── GestionClientesAssignment.class.php
│       │   ├── GestionClientesDialer.class.php
│       │   ├── GestionClientesStats.class.php
│       │   └── GestionClientesValidator.class.php
│       ├── themes/default/
│       │   ├── campaign_list.tpl
│       │   ├── campaign_form.tpl
│       │   ├── import_preview.tpl
│       │   ├── assignment.tpl
│       │   ├── agent_workspace.tpl
│       │   ├── callbacks.tpl
│       │   ├── dashboard.tpl
│       │   ├── audit.tpl
│       │   ├── css/gestion_clientes.css
│       │   └── js/gestion_clientes.js
│       ├── lang/es.lang
│       ├── lang/en.lang
│       ├── help/es.hlp
│       └── help/en.hlp
├── install/
│   ├── schema.sql
│   ├── seed_outcomes.sql
│   ├── menu.sql
│   ├── acl.sql
│   ├── install.sh
│   ├── upgrade.sh
│   └── uninstall.sh
├── asterisk/
│   ├── extensions_gestion_clientes.conf
│   └── manager_gestion_clientes.conf.example
├── bin/
│   └── reconcile_cdr.php
└── tests/
    ├── bootstrap.php
    ├── unit/
    ├── integration/
    ├── fixtures/
    └── fakes/FakeAmiServer.php
```

Production paths after approval:

- Module: `/var/www/html/modules/gestion_clientes/`
- Custom dialplan: `/etc/asterisk/extensions_gestion_clientes.conf`
- Include: one guarded include in `/etc/asterisk/extensions_custom.conf`
- Dedicated AMI definition: `/etc/asterisk/manager_custom.conf` or the installation's verified custom include
- CDR reconciliation command: `/usr/local/sbin/gestion-clientes-reconcile-cdr`
- Scheduled reconciliation: `/etc/cron.d/gestion-clientes` initially; replace with a service only if live events are justified

Install the approved reconciliation schedule with:

```sh
install -o root -g root -m 0755 bin/reconcile_cdr.php /usr/local/sbin/gestion-clientes-reconcile-cdr
install -o root -g root -m 0644 install/gestion-clientes.cron /etc/cron.d/gestion-clientes
```

The cron runs once per minute under a non-overlapping `flock`. Its two-minute
minimum age allows Asterisk to finish writing all linked CDR legs, so a finished
call will normally settle in the UI within one to three minutes.

## 5. Data model

Use table prefix `gc_` in a dedicated database named `gestion_clientes` unless the Phase 0 inspection shows a safer established convention. Use InnoDB, explicit foreign keys, UTC timestamps, and utf8.

### Core tables

**`gc_campaign`**

- id, name, description, status
- timezone, outbound_context, default_phone_order
- manual_or_auto mode
- created_by, created_at, updated_at, started_at, ended_at

**`gc_import_batch`**

- campaign_id, original_filename, file_hash
- total_rows, accepted_rows, rejected_rows
- field_mapping_json as LONGTEXT
- imported_by, imported_at

**`gc_client`**

- campaign_id, external_key
- display_name
- state, terminal flag, priority
- custom_data_json as LONGTEXT
- next_action_at, last_attempt_at, managed_at
- created_at, updated_at, row_version
- unique `(campaign_id, external_key)` when an external key is supplied

**`gc_client_phone`**

- client_id, original_value, normalized_value
- phone_type, sort_order
- state: available, attempted, answered, no_answer, invalid, do_not_call
- attempt_count, last_attempt_at
- unique `(client_id, normalized_value)`

**`gc_agent_map`**

- issabel_username
- callcenter_agent_id and agent_number
- sip_extension
- active, created_at, updated_at
- unique active mapping per Issabel username

**`gc_assignment`**

- campaign_id, client_id, agent_map_id
- assignment_state, assigned_at, assigned_by
- released_at, release_reason
- unique active assignment per client

**`gc_attempt`**

- campaign_id, client_id, phone_id, assignment_id, agent_map_id
- correlation_token, idempotency_key
- requested_at, originated_at, answered_at, ended_at
- technical_state, business_outcome_id
- asterisk_uniqueid, linkedid, cdr_accountcode
- duration_seconds, talk_seconds
- recording_path or recording_key
- agent_note, raw_error_code

**`gc_outcome`**

- campaign_id nullable for global defaults
- code, label, display_order, active
- resulting_client_state, terminal
- requires_callback, mark_phone_invalid, advance_to_next

**`gc_callback`**

- client_id, assignment_id, attempt_id
- due_at_utc, timezone, status
- note, created_by, completed_at, canceled_at
- index `(status, due_at_utc, assignment_id)`

**`gc_client_event`**

- append-only audit log with client_id, actor username, event type, previous/new state, metadata JSON text, IP, timestamp

**`gc_import_error`**

- batch_id, row_number, field_name, raw_value, error_code, message

### Required indexes

Add indexes for:

- campaign + client state
- agent + active assignment state
- due callbacks
- attempt date + agent + technical state
- attempt date + business outcome
- normalized phone lookup
- CDR correlation token/accountcode

## 6. API and page contract

The module remains server-rendered for compatibility, with JSON actions routed through `index.php?action=...&rawmode=yes` after verifying Issabel's raw response convention.

### Administrator actions

- `campaign_list`
- `campaign_create`
- `campaign_edit`
- `import_upload`
- `import_preview`
- `import_commit`
- `assignment_preview`
- `assignment_commit`
- `agent_mapping`
- `outcome_catalog`
- `dashboard`
- `export_csv`
- `audit_view`

### Agent actions

- `workspace`
- `api_current_client`
- `api_claim_next`
- `api_start_call`
- `api_attempt_status`
- `api_save_outcome`
- `api_schedule_callback`
- `api_client_history`

Every JSON response uses:

```json
{
  "ok": true,
  "code": "STABLE_MACHINE_CODE",
  "message": "Localized user-facing text",
  "data": {},
  "request_id": "uuid"
}
```

All mutation endpoints require POST, CSRF validation, ownership checks, an idempotency key, and a transaction.

## 7. Statistics definitions

Define each metric before coding to avoid contradictory dashboards:

- **Assigned clients:** active assignments during the selected scope
- **Managed clients:** clients currently in a terminal state
- **Pending clients:** non-terminal assigned clients excluding future callbacks
- **No contact:** clients with one or more attempts but no answered/customer-contact outcome
- **Callback scheduled:** open callbacks, split into future, due, and overdue
- **Sale/interest:** clients whose current terminal or active state is `SALE` or `INTERESTED`
- **Numbers attempted:** distinct client-phone pairs with at least one attempt
- **Total calls:** attempt rows accepted by Asterisk, with rejected originate requests shown separately
- **Answered/not answered:** CDR-derived technical disposition, not the agent's business response
- **Talk time:** sum of reconciled customer-leg billsec, not total channel duration
- **Agent progress:** terminal assigned clients divided by currently assigned clients, with numerator and denominator displayed
- **Base remaining:** imported valid clients without a terminal state
- **Campaign status:** configured status plus computed totals and completion percentage
- **Performance:** grouped by local business date, agent, technical disposition, and business outcome

Dashboards must label metrics as live/current, date-scoped, or cumulative. Reconcile campaign totals against agent and result breakdowns.

## 8. Implementation phases and tasks

### Phase 0: Read-only discovery and design validation

**Objective:** Resolve installation-specific assumptions before schema or code is finalized.

1. Capture versions, active modules, PHP extensions, MySQL/MariaDB version, database collations, CDR backend, recording format, and web server user
2. Inspect the exact schemas for `call_center.agent`, Issabel web users/ACL, Asterisk SIP extensions, and CDR
3. Identify how the current agent 506 maps across Issabel username, Call Center agent, and SIP extension
4. Inspect an existing native module's menu and ACL install mechanism
5. Verify whether `rawmode=yes` is available for JSON responses
6. Verify the correct custom include files for dialplan and AMI
7. In a non-production context or approved test window, run five controlled origination cases and document CDR/recording correlation
8. Write the findings to `docs/architecture.md` and update any assumptions in this plan

**Validation:** No database writes, reloads, or calls during discovery without separate approval. Produce a discovery report and a final call-flow sequence diagram.

### Phase 1: Repository, compatibility harness, and installer skeleton

**Objective:** Establish a repeatable build/install/rollback process before feature code.

1. Create the repository layout listed above
2. Add a PHP 5.4 syntax compatibility check
3. Add test bootstrap and fake database/AMI adapters
4. Create idempotent `install.sh`, `upgrade.sh`, and `uninstall.sh`
5. Make uninstall preserve business data unless `--purge-data` is explicitly supplied
6. Add backup and rollback documentation

**Verification:** Run syntax checks over every PHP file; install into a disposable directory twice; verify the second install performs no duplicate menu or schema writes.

### Phase 2: Schema and repositories

**Objective:** Implement the module-owned transactional data layer.

For each table:

1. Write a failing repository/integration test
2. Add the smallest schema migration
3. Implement prepared-query repository methods
4. Test rollback on failed transactions
5. Commit the table and repository together

Critical tests:

- duplicate external keys
- duplicate normalized phones per client
- only one active client assignment
- two-tab claim race
- duplicate start-call idempotency
- callback required for callback outcomes
- terminal client cannot be called without supervisor reopen
- event log is append-only

### Phase 3: Issabel authentication, ACL, and agent mapping

**Objective:** Reuse existing sessions safely and map them to telephony identities.

1. Add the menu root “Gestión de Clientes”
2. Add supervisor and agent child pages
3. Define ACL resources for view, import, assign, call, report, export, and administer
4. Resolve the authenticated Issabel username from the existing session
5. Build agent mapping administration with verification against live agent and SIP records
6. Block inactive, missing, or ambiguous mappings
7. Test that agent A cannot view or mutate agent B's assignments

**Verification:** Test with an administrator, mapped agent, unmapped user, and unauthorized user.

### Phase 4: Campaign creation and CSV import

**Objective:** Load clean, auditable client bases without relying on the traditional campaign importer.

1. Build campaign form and validation
2. Upload CSV to a private temporary directory outside the web root
3. Detect delimiter and encoding, then show a preview
4. Let supervisor map external ID, display name, phone columns, and arbitrary data columns
5. Normalize Ecuador phone numbers while preserving original values
6. Reject rows without a dialable phone; report exact row errors
7. Deduplicate within the file and existing campaign by configured rule
8. Show accepted, rejected, duplicate, and warning counts before commit
9. Commit the import in chunks inside guarded transactions
10. Store file hash and import audit record
11. Delete temporary source files after commit/cancel according to retention policy

**Acceptance test:** Import a fixture with multiple phone columns, duplicate phones, invalid values, accents, empty cells, and repeated external IDs. Verify every count and field mapping.

### Phase 5: Assignment engine

**Objective:** Assign a controlled quantity of eligible clients to a mapped agent.

1. Build assignment preview with campaign, agent, quantity, priority/order, and eligibility filters
2. Lock candidate clients during commit
3. Create assignment history records instead of overwriting prior assignments
4. Prevent assigning a terminal or already actively assigned client
5. Support reassignment of pending clients only through a separate audited action
6. Show before/after counts

**Acceptance test:** Assign 50 clients to agent 506 and verify exactly 50 active assignments, zero duplicates, stable ordering, and an audit event per assignment batch.

### Phase 6: Agent workspace

**Objective:** Deliver the core portfolio workflow.

1. Build pending, callback, overdue, and completed summary cards
2. Implement deterministic next-client selection: overdue callback, due callback, priority, assigned time, client ID
3. Claim the current client transactionally with a short lease
4. Display all imported fields with HTML escaping
5. Display ordered phone numbers and each phone's attempt state
6. Display attempt, outcome, callback, and note history
7. Prevent navigation away while an unsaved outcome is required
8. Release stale claims safely after a configurable timeout

**Acceptance test:** Open two tabs as the same agent and prove they cannot create two active claims or conflicting outcomes.

### Phase 7: AMI click-to-call and dialplan

**Objective:** Originate a traceable call without weakening AMI security or bypassing outbound policy.

1. Create a dedicated AMI user restricted to localhost and minimum read/write classes
2. Store AMI credentials outside the module source with restrictive permissions
3. Add `GestionClientesDialer.class.php` with connect, authenticate, originate, response parsing, timeout, and redacted logging
4. Validate phone number and ownership again immediately before originate
5. Create attempt row before sending AMI action
6. Originate the agent SIP extension first
7. Route the customer leg through `gestion-clientes-outbound` into the existing outbound route
8. Inherit the attempt correlation token across channel legs
9. Apply or verify recording policy
10. Handle agent unavailable, agent no answer, originate rejection, customer busy/no-answer, answer, and hangup
11. Never expose AMI credentials or raw manager output to the browser

**Verification commands after approved deployment:**

```bash
asterisk -rx 'dialplan show gestion-clientes-outbound'
asterisk -rx 'manager show user gestion_clientes'
asterisk -rx 'sip show peer 506'
asterisk -rx 'core show channels concise'
```

**Acceptance test:** Complete the five-case call matrix and reconcile attempt, CDR, recording, and UI states for every case.

### Phase 8: Outcomes, callbacks, and client progression

**Objective:** Make every call end in an explicit, auditable next action.

1. Seed default outcomes
2. Build campaign-specific outcome configuration
3. Require a selected outcome after a completed/failed attempt
4. Apply outcome effects in one transaction
5. Require callback date, time, timezone, and note when configured
6. Mark only the selected phone invalid when the outcome says “invalid number”
7. Mark the client `INVALID` only when no usable phone remains or the outcome explicitly closes it
8. Advance to the next client only after the transaction commits
9. Show due and overdue callbacks first
10. Allow callback cancel/reschedule with audit history

**Acceptance test:** Exercise every default outcome against single-phone and multi-phone clients and verify state transitions.

### Phase 9: CDR and recording reconciliation

**Objective:** Populate trustworthy technical statistics independently of agent input.

1. Implement `bin/reconcile_cdr.php`
2. Query only unreconciled attempts in bounded batches
3. Locate all CDR legs by correlation token and linked ID
4. Identify agent and customer legs using the validated Phase 0 rules
5. Derive technical state, duration, talk time, unique ID, linked ID, and recording reference
6. Make reconciliation idempotent
7. Mark ambiguous matches for supervisor review rather than guessing
8. Run manually first, then schedule at an approved interval
9. Add daily reconciliation totals and error logging

**Acceptance test:** Re-run the reconciler repeatedly and verify no duplicate or changing totals for closed calls.

### Phase 10: Dashboards and exports

**Objective:** Provide reconciled operational reporting.

1. Implement a shared date/time scope parser using campaign timezone
2. Build campaign summary
3. Build agent progress table
4. Build daily performance table
5. Build business outcome table
6. Build technical call-result table
7. Build callbacks due/overdue report
8. Add CSV exports with the same filters and totals as the UI
9. Add drill-down links from totals to underlying clients/attempts
10. Reconcile totals across every grouping

**Acceptance test:** Seed a deterministic fixture and verify every metric definition in Section 7, including zero-denominator percentages.

### Phase 11: Security, operations, and usability hardening

**Objective:** Prepare for controlled production use.

1. Add CSRF protection to all writes
2. Use prepared SQL and centralized validation
3. Escape all imported data and notes on output
4. Restrict uploads by size, extension, MIME, and storage location
5. Add request IDs and redacted structured logs
6. Add login/session expiry handling
7. Add rate limits and active-attempt guards to call initiation
8. Add do-not-call support
9. Verify file and credential permissions
10. Add database backup, restore, and rollback scripts
11. Write agent and supervisor guides in Spanish
12. Run accessibility and keyboard-flow checks

### Phase 12: Staged rollout

**Objective:** Prove the module without affecting the traditional Call Center.

1. Back up exact files, menu/ACL rows, new database, and Asterisk custom configs
2. Install module code and schema while no agents are affected
3. Reload only manager or dialplan components that changed
4. Verify existing campaigns, queues, agents, and active calls are unchanged
5. Pilot with one supervisor, one agent, and 5 synthetic/test clients
6. Run the full call matrix
7. Pilot with agent 506 and a small approved real base
8. Compare UI statistics to CDR and recordings daily
9. Expand to 50 clients only after acceptance criteria pass
10. Keep rollback artifacts until Jaime signs off

## 9. Test strategy

### Unit tests

- Phone normalization and ordering
- State transition matrix
- Outcome rules
- Callback time conversion
- Statistics formulas
- Permission and ownership decisions
- AMI message construction and response parsing
- CDR leg selection

### Database integration tests

- Schema install/upgrade idempotency
- Transactions and rollbacks
- Concurrent assignment and claim
- Idempotent call creation and disposition
- Import deduplication
- Reconciliation idempotency

### Issabel integration tests

- Existing session and ACL
- Menu rendering
- Agent mapping
- SIP registration/readiness
- AMI originate
- Outbound route behavior
- CDR correlation
- Recording availability

### Regression checks

Before and after deployment compare:

- active Asterisk channels
- SIP peer registration
- agent and queue state
- traditional campaign counts
- dialer service status
- recent Asterisk/Apache/PHP errors

## 10. Acceptance criteria

The MVP is accepted only when:

1. A supervisor can import a base and see exact accepted/rejected counts
2. A supervisor can assign exactly 50 eligible clients to agent 506 without duplicates
3. Agent 506 sees only assigned clients after normal Issabel login
4. Multiple phones appear in configured order
5. “Llamar” rings the agent's Zoiper extension first and then the selected customer number
6. The agent can try the second phone without closing the client incorrectly
7. Every call attempt remains in history even if the originate fails
8. Every business outcome causes the configured and tested transition
9. Callbacks appear at the correct local time and are prioritized when due
10. Technical call status and talk time reconcile to CDR
11. Reports reconcile across campaign, agent, day, and outcome
12. Existing Call Center campaigns and agent operation remain unchanged
13. Installation and rollback are documented and tested

## 11. Risks and mitigations

- **Legacy PHP:** Keep dependencies minimal, enforce PHP 5.4 syntax in CI, and avoid modern frameworks
- **Identity mismatch:** Require explicit verified mapping instead of assuming username = agent = extension
- **Duplicate calls:** Use transactions, active-attempt constraints, CSRF, and idempotency keys
- **Wrong CDR leg/talk time:** Complete the controlled call matrix before reporting production metrics
- **Recording mismatch:** Correlate by unique/linked ID and validated recording policy, not filename guesses
- **Agent not ready:** Check SIP registration/device state and require manual call initiation in MVP
- **Two browser tabs:** Use client claim leases and unique active-attempt guards
- **Data leakage:** Enforce server-side assignment ownership and ACL on every request
- **Production interruption:** Use only custom include files, narrow reloads, backups, and staged rollout
- **ECCP fragility:** Keep MVP independent of ECCP. Add ECCP only if a later requirement genuinely needs Call Center pause/readiness semantics
- **Metric disagreement:** Publish metric definitions and reconcile totals before release

## 12. Open product decisions before Phase 4

Recommended defaults are included so implementation can proceed after confirmation:

1. Module display name: **Gestión de Clientes**
2. Assignment behavior: fixed manual quantity with oldest/highest-priority eligible clients first
3. Initial dialing mode: manual click-to-call
4. Callback behavior: reminder and priority queue, not automatic dialing
5. Default outcomes: pendiente, callback, interesado, no interesado, venta, número inválido, sin contacto, cerrado otro
6. Import identifier: supervisor selects an external ID column; otherwise module generates one
7. Phone normalization: Ecuador-first rules, while preserving original input
8. Reassignment: only pending/non-terminal clients, supervisor-only, fully audited
9. Agent notes: append-only after save; corrections become a new event
10. Data retention: define retention for CSV source files, client PII, recordings, and audit logs before production

## 13. Recommended execution order

Build and accept vertical slices rather than completing all administration screens first:

1. Discovery and call-correlation spike
2. Schema + identity mapping
3. Import five test clients
4. Assign them to one agent
5. Render the agent workspace
6. Complete one click-to-call end to end
7. Save one outcome and one callback
8. Reconcile CDR and recording
9. Add dashboards from proven data
10. Harden, document, pilot, and expand

This sequence proves the highest-risk integration early: Issabel identity, SIP readiness, origination, CDR correlation, and recording correlation.
