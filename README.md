# plugin_billing

`plugin_billing` is a standalone Cacti 1.2 plugin for 95th-percentile burstable transit and metered resource billing, combining an append-only 5-minute sample ledger, integer minor-unit tariff rating, SHA-256 statement dispute proofs, and signed ISP/ERP billing connectors.

## Why This Plugin Exists

RRDtool consolidates 5-minute samples into coarser averages over time, which makes historical 95th-percentile invoices impossible to reproduce from `.rrd` files months later during customer billing disputes. `plugin_billing` records aligned 5-minute counter deltas and metered resource units into an immutable MariaDB ledger (`plugin_billing_sample`), rates single-port and multi-circuit LAG contracts using exact integer arithmetic, seals finalized statements with a canonical SHA-256 proof digest, and bridges finalized invoices to external ISP billing and ERP platforms without patching Cacti core or adding runtime Composer dependencies.

## Key Capabilities

- **Immutable 5-minute billing ledger (`lib/Ledger/`)**: Ingests 64-bit and 32-bit octet counter deltas with exact unsigned wraparound arithmetic (no `bcmath` or `gmp` required), interface port-speed spike capping, and generic metered resource micro-units (`watts`/`kwh`, `tib_hours`, `gpu_hours`). Existing `(circuit_id, bucket_ts)` ledger rows are never overwritten.
- **95th-percentile and LAG rating engine (`lib/Rating/`)**:
  - Aligns 5-minute timestamps across all member circuits in a LAG or multi-port contract and sums aligned buckets before computing the Nth percentile, avoiding the over-billing error of summing per-port 95th percentiles.
  - Supports configurable Nth percentiles, five direction modes (`max_in_out`, `sum_in_out`, `separate_in_out`, `in_only`, `out_only`), basis-point drop-count rounding (`floor`, `ceil`, `nearest`), gap policies (`exclude`, `zero_fill`, `carry_forward`), Cacti maintenance window exclusions, and mid-month pro-rata active intervals.
  - Uses O(N) 3-way Quickselect over flat parallel integer arrays. On PHP 8.6, rating 1,000 2-circuit LAG contracts across 8,640 monthly intervals (17.28M circuit samples) plus streaming SHA-256 proof generation completes in 2.07 seconds with 16 MiB peak RSS (see [performance-profile.md](docs/architecture/performance-profile.md)).
  - Performs all currency math in integer minor units (cents or micro-dollars) across flat, stepped, and volume overage tiers, TB transfer caps, and pro-rata fixed commit fees.
  - Projects mid-cycle burst budget consumption (for example, 432 five-minute slots = 36 hours in a 30-day cycle) with `ok`, `warning`, `critical`, and `breached` alert states.
- **Tamper-evident finalized statements (`lib/Statement/`)**: Stores contract tariff snapshots, exact dropped peak intervals, and line-item totals sealed with a streaming SHA-256 digest verified in constant time via `StatementService::verifyIntegrity()`.
- **ISP/ERP connectors and usage exports (`lib/Connector/`, `lib/Security/`)**:
  - Structured payload adapters for WHMCS, HostBill, Blesta, UISP/UCRM, Kill Bill, Stripe Billing, and generic HMAC-SHA256 signed webhooks (`X-Billing-Signature-256` and `X-Billing-Timestamp` with replay-window verification).
  - FinOps FOCUS 1.0 JSON usage export and RFC 4180 CSV export with spreadsheet formula-injection neutralization.
- **Console UI, CLI, script server, and decoupled hooks**:
  - Three Console pages behind 3-tier RBAC (`Billing View`, `Billing Operator`, `Billing Admin`) with synchronizer-token CSRF protection on all POST mutations.
  - `cli/billing.php` supporting `rate`, `finalize`, `verify`, `forecast`, `export-usage`, `export`, `validate`, `plan`, `apply --confirm=<PLAN_HASH>`, and `health`.
  - PHP 8.0-parseable `scripts/ss_billing_stats.php` emitting `accounts_active`, `contracts_active`, `circuits_total`, `statements_finalized`, `accounts_over_commit`, `burst_warning_count`, `exports_failed`, and `samples_24h` (returning `U` on error).
  - Plain-array hooks (`casc_register_providers`, `doctor_register_checks`, `doctor_register_debug_info`, `abilities_register`, `api_register_resources`) with zero cross-plugin PHP imports at boot.

## Architecture and Config vs. State Split

`schema/billing.schema.yaml` defines a strict boundary between declarative Config and immutable State:

| Category | Scope | Exported by CasC | Overwritten by Apply |
| --- | --- | --- | --- |
| Config | Plugin settings (`billing_*`), account/contract/circuit catalog metadata (excluding secrets) | Yes | Yes (with `--confirm=<PLAN_HASH>`) |
| State | `plugin_billing_sample`, `plugin_billing_statement`, `plugin_billing_export_log`, runtime telemetry | Never | Never |

## Security Boundaries

- **Outbound HTTPS and SSRF defense (`lib/Security/OutboundGuard.php`)**: Rejects non-HTTPS schemes, embedded URL credentials, non-443/8443 ports, and destinations resolving to loopback, RFC 1918 private networks, link-local / cloud metadata (`169.254.0.0/16`, `fe80::/10`), CGNAT (`100.64.0.0/10`), ULA (`fc00::/7`), multicast, unspecified, or IPv4-mapped IPv6 addresses. Disables HTTP redirects (`CURLOPT_FOLLOWLOCATION => false`, `CURLOPT_MAXREDIRS => 0`), enforces peer TLS verification, and caps timeouts and response size.
- **Connector secret encryption (`lib/Security/SecretVault.php`)**: Encrypts ERP API keys and webhook signing secrets at rest with AES-256-GCM authenticated encryption backed by an external 256-bit keyfile, binding ciphertext AAD to the owning `account_code`. Key material and decrypted secrets are never exported in CasC snapshots, API responses, or diagnostic output.
- **CSRF and CSV injection defense**: Operator and Admin POST forms enforce constant-time synchronizer CSRF tokens (`lib/Ui/CsrfGuard.php`). CSV exports prefix cells beginning with `=`, `+`, `-`, `@`, tab, or carriage return (including whitespace-prefixed variants) with a single quote while preserving plain numeric values.
- **PHP version floor**: Entry points (`setup.php`, `billing_*.php`, `poller_billing.php`, `cli/billing.php`, `scripts/ss_billing_stats.php`, `include/autoload.php`) remain PHP 8.0-parseable so hosts on PHP 8.0/8.1 receive a clean refusal rather than a parse fatal; runtime classes require PHP 8.2+.

## Installation

1. Copy or clone this repository into `<cacti_root>/plugins/billing`.
2. Install and enable via Cacti Plugin Management or CLI:

```bash
php cli/plugin_manage.php --plugin=billing --install --enable
```

3. Optional: create a 32-byte (or 64-hex-char) external keyfile with mode `0600` owned by the Cacti runtime user and configure `billing_secret_keyfile` under Console -> Configuration -> Settings -> Billing.

## CLI Quick Reference

```bash
# Check ledger and export health
php plugins/billing/cli/billing.php health --format=json

# Dry-run rate a contract for a UTC period
php plugins/billing/cli/billing.php rate --contract=TRANSIT-10G --start=2025-01-01 --end=2025-02-01

# Finalize an immutable statement with SHA-256 dispute proof
php plugins/billing/cli/billing.php finalize --contract=TRANSIT-10G --start=2025-01-01 --end=2025-02-01 --actor=ops

# Verify statement integrity
php plugins/billing/cli/billing.php verify --statement=STMT-TRANSIT-10G-202501

# Export FOCUS 1.0 JSON or RFC 4180 CSV usage
php plugins/billing/cli/billing.php export-usage --statement=STMT-TRANSIT-10G-202501 --format=focus
php plugins/billing/cli/billing.php export-usage --statement=STMT-TRANSIT-10G-202501 --format=csv

# Declarative Config plan and hash-confirmed apply
php plugins/billing/cli/billing.php plan --file=billing-config.json
php plugins/billing/cli/billing.php apply --file=billing-config.json --confirm=<PLAN_HASH>
```

## Requirements and Compatibility

- Cacti: `1.2.31` and `1.2.x` (verified in CI against live MariaDB 11.8)
- PHP: `8.2`, `8.3`, `8.4`, `8.5` (entry points PHP 8.0-parseable)
- Runtime PHP extensions: `ext-json`, `ext-openssl`, `ext-pdo` / Cacti database layer (zero runtime Composer packages)

## Development and Verification

```bash
# Install dev-only QA tools (PHPUnit, PHPStan)
composer install

# Run unit, security, PHPDoc AST, and zero-import tests
vendor/bin/phpunit -c phpunit.xml

# Run PHPStan Level 9 against a Cacti 1.2.31 checkout
CACTI_PATH=/path/to/cacti vendor/bin/phpstan analyse -c phpstan.neon --memory-limit=1G

# Run the 95th-percentile rating benchmark
php tests/Benchmark/benchmark_rating.php
```

## License

GNU General Public License v2.0 or later (`GPL-2.0-or-later`). See [LICENSE](LICENSE) and [REUSE.toml](REUSE.toml).
