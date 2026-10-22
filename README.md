# SecX

**SecX is a multi-tenant security platform for small and mid-sized organisations, built as a control plane over established open-source security engines.**

SecX is a product of **Obaits**, a project of **AICexperts**. It is licensed under the **GNU AGPL v3** (see [`LICENSE`](LICENSE)).

> **Status: working SaaS, early stage.** The control plane described here runs in production on a single server. The on-premise ISO, the second product (Canopy) and several larger features are *not built yet*. [What is built and what is not](#status-what-is-built-and-what-is-not) is stated plainly below.

---

## The problem

Small organisations, particularly in Africa, face the same threats as large ones but cannot afford a security team, a SIEM, a vulnerability scanner and a compliance consultant. Good open-source tools exist for each of these jobs, but running and connecting them is a specialist job in itself.

SecX does not reinvent those tools. It **integrates** them behind one portal, isolates each customer ("tenant") from the others, and handles the parts that make them usable by a non-specialist: enrolling devices, scheduling scans, scoring findings, generating compliance evidence and billing.

## What SecX does

| Area | What the portal provides | Engine it drives |
|---|---|---|
| **Endpoint monitoring & detection** | Enrol Windows and Linux devices, view security alerts, file-integrity and threat-detection data | Wazuh |
| **Device inventory & live query** | Asset inventory, installed software, vulnerable software, ad-hoc and scheduled queries | Fleet / osquery |
| **Vulnerability scanning** | Scheduled and on-demand network scans, findings with severity, CVE, EPSS and CISA KEV scoring | OpenVAS / GVM |
| **Web application scanning** | Authenticated web scans | OWASP ZAP |
| **Incident response** | Case management and evidence handling | DFIR-IRIS |
| **Governance & compliance** | Policies, document builder with approvals, control mapping, evidence collection | CISO Assistant |
| **Infrastructure monitoring** | Host and service monitoring, linked to assets | Zabbix |
| **Identity & access** | Single sign-on, MFA, per-tenant groups, role-based permissions | Keycloak |
| **Private networking** | A separate private network per tenant, so agents and scanners reach tenant devices without exposing them | Headscale (Tailscale protocol) |
| **Backups** | Per-tenant backup plans and storage quotas | restic |
| **CCTV** | Per-tenant camera provisioning | lightNVR |
| **Reports** | Security-posture summary that prints cleanly | SecX |
| **Billing** | Per-device subscription, trial, invoices, receipts, grace period and suspension, refunds | Flutterwave |
| **Marketplace** | Catalogue of security services and trainings, with provider and request management | SecX |

The portal is available in English, French, German, Spanish and Dutch.

## Architecture

```
                     ┌──────────────────────────────────────────┐
   Browser ────────▶ │  nginx + ModSecurity (OWASP CRS)         │
                     └───────────────┬──────────────────────────┘
                                     ▼
                     ┌──────────────────────────────────────────┐
                     │  SecX control plane  (Laravel 13, PHP 8.3)│
                     │  Livewire portal · operator console · API │
                     │  scheduler + queue workers · PostgreSQL   │
                     └───┬─────────┬──────────┬─────────┬───────┘
          typed API      │         │          │         │
          clients        ▼         ▼          ▼         ▼
                      Wazuh     Fleet      GVM/ZAP   Keycloak · Headscale
                                                      IRIS · CISO Assistant
                                                      Zabbix · restic · lightNVR
```

Key design points:

- **The control plane owns the tenant model; the engines do not.** Every tenant-scoped table carries a tenant ID. Each tenant also gets its own Keycloak group and its own Headscale instance (with its own ports and DERP region), so isolation does not rely on one layer alone.
- **Typed clients for each engine** live in [`app/Clients/`](app/Clients) (`WazuhClient`, `FleetClient`, `GvmClient`, `ZapClient`, `IrisClient`, `CisoClient`, `ZabbixClient`, `KeycloakClient`).
- **A pool of scanner containers** ([`ops/gvm-pool/`](ops/gvm-pool)) lets one vulnerability scanner reach into tenant networks. Each pool member joins exactly one tenant network for one scan, then leaves, so a scanner never has two tenants' networks open at once.
- **Background work is explicit.** Around fifty Artisan commands in [`app/Console/Commands/`](app/Console/Commands) handle enrolment, scans, backups, billing enforcement, drift detection and reconciliation. They run from the scheduler and a queue worker.
- **Reconciliation over trust.** Because state lives in several systems, there are commands that compare them and report mismatches (for example `ReconcileAgents`, `ReconcileHeadscaleInstances`, `CheckDeviceDrift`).
- **Device enrolment** is delivered by generated scripts ([`resources/views/enroll/`](resources/views/enroll)) for Windows (PowerShell) and Linux (shell), authenticated per tenant.
- **Per-tenant outgoing mail.** A tenant can send its notifications through its own SMTP server after a passing test, falling back to the platform sender on failure.

## Repository layout

| Path | Contents |
|---|---|
| `app/Livewire/Portal/` | Customer-facing portal pages |
| `app/Livewire/Operator/` | Operator (platform admin) console |
| `app/Clients/` | API clients for the integrated engines |
| `app/Console/Commands/` | Scheduled and operational commands |
| `app/Support/` | Billing, compliance, backup, marketplace, notifications, licensing and health logic |
| `database/migrations/` | Schema (106 migrations) |
| `resources/views/enroll/` | Generated Windows/Linux enrolment and audit scripts |
| `lang/` | Translations (en, fr, de, es, nl) |
| `ops/` | Deployment material: nginx and ModSecurity configuration, GVM scanner pool compose file, sudoers rules, deploy script, backup scripts, compliance tooling |
| `ops/compliance/` | Component manifest, third-party notices, SBOM and licence-gate scripts |
| `tests/` | Automated tests |

## Status: what is built and what is not

**Built and running (SaaS):**
- Multi-tenant portal and operator console, with single sign-on and role-based access.
- Device enrolment and monitoring for Windows and Linux, with a per-tenant private network.
- Scheduled vulnerability scanning through the scanner pool, with finding scoring.
- Compliance, governance, evidence and reporting features.
- Per-device billing with trial, invoicing, receipts, grace period, suspension and refunds. **Payments are currently tested against the payment provider's sandbox with cards only.** Recurring billing and mobile money are not enabled.
- Per-tenant SMTP, device-drift detection, link checking and health alerts.

**Not built yet:**
- **The bootable on-premise ISO** and the installation code for it. SecX is SaaS-only today. Before any ISO ships, an on-premise telemetry design (aggregated, masked, disclosed and refusable) must be implemented; the design exists, the implementation does not.
- **Canopy**, a planned second product.
- **AI-assisted remediation** and the outcome recording that goes with it.
- A real AI assistant page (the current page is an honest placeholder).
- Scanner pool automation beyond the first phase.
- Proxmox Backup Server integration (restic is used today).
- **Automated response.** SecX provides detection and visibility through Wazuh. It does not currently claim to be an EDR with automated containment.

**Known limitations:**
- The service runs on a single server, geographically distant from many of its intended users. Production hosting is an open decision.
- Automated test coverage is thin. Verification so far has been mostly end-to-end on real hardware and virtual machines rather than by unit tests. Raising coverage and adding continuous integration are planned.
- A few deployment values (for example the server's public address in the Headscale provisioning command) are hard-coded and must be made configurable for the on-premise product.

## Licensing and third-party components

SecX is AGPL-3.0. The engines it integrates keep their own licences; see [`ops/compliance/COMPONENT-MANIFEST.md`](ops/compliance/COMPONENT-MANIFEST.md) and [`ops/compliance/THIRD-PARTY-NOTICES.md`](ops/compliance/THIRD-PARTY-NOTICES.md). A licence-gate script ([`ops/compliance/licence-gate.sh`](ops/compliance/licence-gate.sh)) fails the commit if commercially-licensed upstream code (such as Fleet Premium or CISO Assistant enterprise code) enters the repository, and CycloneDX SBOM generation is scripted ([`generate-sbom.sh`](ops/compliance/generate-sbom.sh)). Several licence questions about redistributing a bundled ISO are open and awaiting legal review; these are tracked as a condition of releasing the ISO.

## Running it

SecX needs a running stack of the integrated engines, which is not a one-command setup. To explore the control plane code on its own:

```bash
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate
php artisan test
npm install && npm run build
php artisan serve
```

Connecting the engines requires their endpoints and credentials in `.env` (not included in this repository). Production deployment is documented in [`ops/deploy/deploy.sh`](ops/deploy/deploy.sh) and the configuration under [`ops/`](ops).

## How this was built

Transparency about authorship:

- The product design, architecture decisions, requirements, and the operation and verification of the running system are by the project's founder.
- Much of the code was written **with AI assistance (Anthropic's Claude, via Claude Code)** working from those requirements, in an iterative loop where the founder reviews the results and tests them against real servers and devices. Commit messages and code comments are written to record the reasoning and the defects found, so the repository doubles as a knowledge base.
- This public repository is a **snapshot** exported from a private working repository, so it does not show the full commit history.

## Contributing and security

Issues and pull requests are welcome. To report a security vulnerability, please email **support@obaits.com** rather than opening a public issue.

## Contact

Obaits, a project of AICexperts · support@obaits.com
