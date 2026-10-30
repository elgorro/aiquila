# Security Policy

## Supported versions

AIquila ships two components on one shared version number: the MCP server
(`aiquila-mcp` on npm, `ghcr.io/elgorro/aiquila-mcp`) and the Nextcloud app.
Security fixes land in the **latest release** only. Upgrade to it before
reporting, and check whether the issue still reproduces there.

## Reporting a vulnerability

**Please do not open a public issue, discussion or pull request for a security
problem.**

Report it privately through GitHub:
[**Report a vulnerability**](https://github.com/elgorro/aiquila/security/advisories/new)
(Security tab → *Report a vulnerability*). Only the maintainers can see the report.

Useful details:

- the affected component and version
- the configuration involved (transport, `MCP_AUTH_ENABLED`, relevant env vars),
  with secrets and hostnames removed
- steps to reproduce, and what an attacker gains
- any fix or mitigation you have in mind

## What to expect

- We confirm receipt and give a first assessment as soon as we can. This is a
  volunteer project, so allow a few days.
- We work on the fix in a private advisory and may invite you to collaborate on it.
- Once a patched release is out, we publish the advisory, request a CVE where it
  applies, and credit you unless you prefer to stay anonymous.

Please give us a reasonable chance to release a fix before disclosing the issue
publicly.

## Scope

In scope: this repository's code and its default configurations, including the
Docker and Hetzner deployment stacks.

Out of scope: vulnerabilities in Nextcloud itself or other upstream projects
(report those upstream; Nextcloud runs a program on HackerOne) and findings that
need an already-compromised server or administrator account.
