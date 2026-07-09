# Code inventory — 100% traceability

**Baseline spec**: [`spec.md`](spec.md)  
**Package**: `nowo-tech/login-throttle-bundle`  
**Last audited**: 2026-07-07

## Symfony config

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Resources/config/services.yaml` | Service wiring | FR-DI-001 |

## Bundle & DI

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `NowoLoginThrottleBundle.php` | Bundle entry | FR-BUNDLE-001 |
| `DependencyInjection/Configuration.php` | Config tree | FR-CFG-001 |
| `DependencyInjection/NowoLoginThrottleExtension.php` | DI extension | FR-CFG-002 |

## CLI

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Command/ConfigureSecurityCommand.php` | Security YAML scaffold | FR-CLI-001 |

## Persistence

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Entity/LoginAttempt.php` | Login attempt entity | FR-ORM-001 |
| `Repository/LoginAttemptRepository.php` | Attempt queries/cleanup | FR-ORM-002 |

## Rate limiting

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `RateLimiter/DatabaseRateLimiter.php` | DB-backed limiter | FR-LIMIT-001 |
| `RateLimiter/DatabaseRateLimiterFactory.php` | Limiter factory | FR-LIMIT-001 |

## Services

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Service/LoginThrottleInfoService.php` | Throttle status info | FR-SVC-001 |

## Translations

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Resources/translations/nowo_login_throttle.en.yaml` | English messages | FR-I18N-001 |
| `Resources/translations/nowo_login_throttle.es.yaml` | Spanish messages | FR-I18N-001 |
| `Resources/translations/nowo_login_throttle.de.yaml` | German messages | FR-I18N-001 |
| `Resources/translations/nowo_login_throttle.fr.yaml` | French messages | FR-I18N-001 |
| `Resources/translations/nowo_login_throttle.it.yaml` | Italian messages | FR-I18N-001 |
| `Resources/translations/nowo_login_throttle.nl.yaml` | Dutch messages | FR-I18N-001 |
| `Resources/translations/nowo_login_throttle.pt.yaml` | Portuguese messages | FR-I18N-001 |
| `Resources/translations/nowo_login_throttle.pt_BR.yaml` | Brazilian Portuguese | FR-I18N-001 |
| `Resources/translations/nowo_login_throttle.ar.yaml` | Arabic messages | FR-I18N-001 |
| `Resources/translations/nowo_login_throttle.cs.yaml` | Czech messages | FR-I18N-001 |
| `Resources/translations/nowo_login_throttle.da.yaml` | Danish messages | FR-I18N-001 |
| `Resources/translations/nowo_login_throttle.el.yaml` | Greek messages | FR-I18N-001 |
| `Resources/translations/nowo_login_throttle.fi.yaml` | Finnish messages | FR-I18N-001 |
| `Resources/translations/nowo_login_throttle.hu.yaml` | Hungarian messages | FR-I18N-001 |
| `Resources/translations/nowo_login_throttle.ja.yaml` | Japanese messages | FR-I18N-001 |
| `Resources/translations/nowo_login_throttle.ko.yaml` | Korean messages | FR-I18N-001 |
| `Resources/translations/nowo_login_throttle.no.yaml` | Norwegian messages | FR-I18N-001 |
| `Resources/translations/nowo_login_throttle.pl.yaml` | Polish messages | FR-I18N-001 |
| `Resources/translations/nowo_login_throttle.ro.yaml` | Romanian messages | FR-I18N-001 |
| `Resources/translations/nowo_login_throttle.ru.yaml` | Russian messages | FR-I18N-001 |
| `Resources/translations/nowo_login_throttle.sv.yaml` | Swedish messages | FR-I18N-001 |
| `Resources/translations/nowo_login_throttle.tr.yaml` | Turkish messages | FR-I18N-001 |
| `Resources/translations/nowo_login_throttle.zh_CN.yaml` | Chinese (Simplified) | FR-I18N-001 |
| `Resources/translations/nowo_login_throttle.zh_TW.yaml` | Chinese (Traditional) | FR-I18N-001 |

## Coverage summary

| Category | Files | Mapped |
| --- | ---: | ---: |
| Symfony config | 1 | 1 |
| Bundle & DI | 3 | 3 |
| CLI | 1 | 1 |
| Persistence | 2 | 2 |
| Rate limiting | 2 | 2 |
| Services | 1 | 1 |
| Translations | 24 | 24 |
| **Total production sources** | **34** | **34** |
