# Security Policy

SEOCart handles orders, customer data and payments. Reports of security problems are
welcome, and they are handled privately until a fix is available.

## Reporting a vulnerability

**Never open a public issue, pull request or discussion for a vulnerability.** A public
report puts every store that runs the plugin at risk before a fix exists.

Report privately instead, through either channel:

1. **GitHub Security Advisories.** Once this repository is public, open the
   **Security** tab of [the repository](https://github.com/cirkuitnet/seocart) and choose
   **Report a vulnerability**. The report is visible only to you and to the maintainers.
2. **E-mail.** Write to <security@seocart.com>.

Include as much of the following as you can:

- the SEOCart version, the WordPress version and the PHP version;
- the steps to reproduce the problem, or a proof of concept;
- what an attacker gains: which data or which action, and with which level of access;
- whether the problem is already public or known to anyone else.

Do not include real customer data, real payment data or live credentials in a report. Test
against a site you own. Do not test against a store you do not have permission to test.

## What to expect

- You receive an acknowledgement within **5 business days**.
- The maintainers confirm the problem, agree a severity with you, and keep you informed
  while a fix is prepared.
- The fix is released before the details are published. You are credited in the release
  notes unless you ask not to be.

## Supported versions

SEOCart has not reached version 1.0. Until it does, only the **latest tagged release**
receives security fixes. No compatibility or back-port promise exists before 1.0.

| Version                   | Supported |
| ------------------------- | --------- |
| Latest tagged 0.x release | Yes       |
| Any older 0.x release     | No        |
| Untagged development code | No        |

From version 1.0 this table will change to cover the latest minor release of the latest
major version, with security back-ports to the previous minor line for a published window,
on the current WordPress major version and the two before it. The
[compatibility and support policy](docs/architecture/target-architecture.md#17-compatibility-and-support-policy-recommended)
records that plan.

## Scope

In scope: the code in this repository and the release packages built from it.

Out of scope: WordPress core, other plugins and themes, the hosting environment, and the
payment providers' own services. Report those to their own maintainers. The payment gateway
plugins for SEOCart live in their own repositories; each one publishes its own supported
versions and uses the reporting channels described here.

## Security design

The plugin's security model — trust boundaries, authorization, payment safety, secrets and
privacy — is documented in [docs/architecture/security.md](docs/architecture/security.md).
