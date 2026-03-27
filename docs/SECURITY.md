# Security — Login Throttle Bundle

## Scope

This Symfony bundle provides **login attempt throttling** (rate limiting), typically backed by persistence (e.g. database), and integrates with Symfony Security (firewall, login flows). It may expose **admin or diagnostic endpoints** in demos—production applications must configure **roles**, **firewalls**, and **environment** appropriately.

## Attack surface

- **HTTP requests** to login and related endpoints: attacker-controlled usernames, IPs, headers.
- **Persistence layer**: storage of attempt counters and metadata; SQL injection must be prevented (use Doctrine ORM/repository patterns as implemented).
- **Configuration**: YAML/XML parameters that define limits, identifiers (e.g. per IP vs. per user), and integration with security.
- **CLI commands** (if any): run in deployment context with elevated access—restrict who can run them.

## Threats and mitigations

| Threat | Mitigation |
|--------|------------|
| **Brute-force / credential stuffing** | Core purpose: throttle failed logins; configure limits and intervals per deployment risk. |
| **User enumeration** | Ensure responses and logging do not leak whether an account exists beyond what your product policy allows. |
| **Denial of service** | Cap resource usage (DB rows, request body size) at the application and infrastructure level; tune throttle storage cleanup. |
| **SQL injection** | Use parameterized queries / ORM only (bundle code should not concatenate raw user input into SQL). |
| **XSS** | Admin or dashboard UIs (if any) must use Twig escaping; demos are not production-hardened by default. |

## Secrets and cryptography

- **No passwords** for third-party services are required by the bundle itself.
- Database credentials belong in **environment variables**, not in committed `.env` files with real secrets.

## Logging

- Log **aggregated** throttle events where possible; avoid logging full passwords or raw secrets from requests.

## Dependencies

- Run `composer audit` in the bundle and in consuming applications.
- Keep Symfony Security and Doctrine components updated.

## Permissions and exposure

- Restrict **demo** and **debug** routes to non-production environments.
- Use `access_control` / roles so throttle management UIs are not public.

## Reporting a vulnerability

Please report security issues **privately** to the maintainers (see `composer.json`). Do not publish exploit details before a coordinated fix.

## Release security checklist (12.4.1)

Before tagging a release, confirm:

| Item | Notes |
|------|--------|
| **SECURITY.md** | This document is current. |
| **`.gitignore` and `.env`** | `.env`, `.env.local` ignored; demos use `.env.example` only. |
| **No secrets in repo** | No DB passwords, API keys, or tokens in tracked files. |
| **Recipe / Flex** | Default recipe values are safe (no production secrets). |
| **Input / output** | Request identifiers validated; ORM used for persistence; Twig escaping in any UI. |
| **Dependencies** | `composer audit` clean or documented. |
| **Logging** | No passwords or session tokens in logs. |
| **Cryptography** | If HTTPS termination is app concern, document for integrators; bundle does not embed keys. |
| **Permissions / exposure** | Document required roles for any admin features. |
| **Limits / DoS** | Throttle limits documented; storage growth considered. |

Record confirmation in the release PR or tag notes.
