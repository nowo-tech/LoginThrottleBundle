# Usage

This document describes common usage patterns for `nowo-tech/login-throttle-bundle`.

## Table of contents

- [Basic usage](#basic-usage)
- [Typical workflow](#typical-workflow)
- [Multi-firewall usage](#multi-firewall-usage)
- [Storage selection](#storage-selection)

## Basic usage

1. Install and enable the bundle.
2. Configure `nowo_login_throttle` in `config/packages/nowo_login_throttle.yaml`.
3. Run:

```bash
php bin/console nowo:login-throttle:configure-security --force
```

4. Verify `login_throttling` was generated in the expected firewall(s).

## Typical workflow

- Start from conservative limits in development.
- Validate behavior with failed login attempts.
- Adjust limits by firewall according to your risk profile.

## Multi-firewall usage

Use the `firewalls` key to define independent policies for each firewall (for example `main`, `admin`, and `api`).

## Storage selection

- `cache`: lightweight and suitable for most single-node deployments.
- `database`: preferred when you need persistence/auditing or shared state.

## Translation overrides

UI strings use the `NowoLoginThrottleBundle` translation domain. To override them from the host application, add YAML files under `translations/NowoLoginThrottleBundle.<locale>.yaml`. Host translations take precedence over the catalogues shipped in this bundle.

For database details, see [DATABASE_STORAGE.md](DATABASE_STORAGE.md).
