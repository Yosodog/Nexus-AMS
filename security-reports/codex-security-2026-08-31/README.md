# Codex Security Assessment — Nexus-AMS

- Assessment date: 2026-08-31
- Assessed release: `v0.8.0-beta`
- Assessed revision: `dab05936641013cdddfa0437d14e12bdf64ed765`
- Method: offline static source review; no exploit requests or external calls were executed

## Result

The assessment identified five medium-severity authorization findings. Each linked report is self-contained, traces the affected release history, distinguishes source-confirmed behavior from unexecuted reproduction, and proposes focused remediation and regression coverage.

| Finding | Severity | Earliest verified affected release |
| --- | --- | --- |
| [Limited user editors can promote another account to administrator](01-admin-promotion/report.md) | Medium | `v0.3.0-alpha` |
| [Limited user editors can rebind an account to another nation](02-nation-rebinding/report.md) | Medium | `v0.3.0-alpha` |
| [Finance administrators can act on accounts and withdrawals outside the managed alliance](03-finance-target-scope/report.md) | Medium | `v0.5.0-alpha` for the directly verified inconsistent scope |
| [Account detail exposes direct-deposit and MMR data without their dedicated permissions](04-specialized-account-data/report.md) | Medium | `v0.7.0-alpha` |
| [Member detail and inactivity exceptions accept nations outside the managed alliance](05-member-target-scope/report.md) | Medium | `v0.3.0-alpha` for the read branch; `v0.8.0-beta` for inactivity exceptions |

No fixed release was verified for any finding.

## Scope and coverage

The repository contained 2,210 tracked files. Review was risk-based and partial rather than an exhaustive line-by-line audit. It concentrated on:

- browser, member, administrator, Discord and internal API routes;
- authentication, MFA, Discord identity and administrator authorization;
- user-to-nation and account ownership boundaries;
- finance, withdrawal and member-management workflows;
- Discord relay proofs, actor resolution, connection isolation and replay controls;
- federation admission, cryptography, capabilities and direct HTTPS transport;
- hosted bootstrap, callbacks and tenant-event processing;
- subscription ingestion and Politics & War request construction;
- related models, policies, requests, migrations, views, configuration and security-focused tests.

The review did not have deployment-effective secrets or configuration, infrastructure ACLs, or the separate Discord, Subs, Setup and hosted control-plane source trees. Runtime exploitability and deployment prevalence remain unmeasured.

## Reviewed controls without a reportable finding

The inspected source did not establish a reportable bypass in the following areas:

- member self-service account, statement, loan, grant, transfer and export ownership checks;
- Discord relay-v2 method, target, body, actor, service-action, connection and replay binding;
- federation signature, encryption, capability, replay and SSRF protections;
- hosted bootstrap replay protection, tenant/release binding and callback authentication;
- tenant-event and subscription message authentication, freshness, schema and replay controls;
- Politics & War GraphQL construction and ambiguous mutation retry behavior.

## Deferred questions

- Legacy Discord v1 interaction proofs are not request-bound, but the reader is disabled by default and the source did not establish an attacker path to capture both a proof and the service bearer. Reassess if `DISCORD_RELAY_V1_READER_ENABLED` is enabled anywhere.
- The Milcom attach-room route accepts a signed interaction without actor or service-action authorization. The separate Discord producer was unavailable, so no user-controllable path to mint that command was established.
- The finance and member target-scope findings assume `AllianceMembershipService` defines the intended administrator boundary. If administrators are deliberately authorized over every historical local record, that policy should be documented and tested explicitly.

## Validation boundary

All findings were validated by tracing the selected identifier or privilege field from an authenticated entry point through the missing authorization decision to its downstream source-level sink. The expected reproductions in the individual reports were not executed and must not be treated as captured HTTP responses or observed database changes.
