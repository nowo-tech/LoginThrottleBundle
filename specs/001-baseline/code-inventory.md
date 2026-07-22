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
| `Resources/translations/NowoLoginThrottleBundle.en.yaml` | English messages | FR-I18N-001 |
| `Resources/translations/NowoLoginThrottleBundle.es.yaml` | Spanish messages | FR-I18N-001 |
| `Resources/translations/NowoLoginThrottleBundle.de.yaml` | German messages | FR-I18N-001 |
| `Resources/translations/NowoLoginThrottleBundle.fr.yaml` | French messages | FR-I18N-001 |
| `Resources/translations/NowoLoginThrottleBundle.it.yaml` | Italian messages | FR-I18N-001 |
| `Resources/translations/NowoLoginThrottleBundle.nl.yaml` | Dutch messages | FR-I18N-001 |
| `Resources/translations/NowoLoginThrottleBundle.pt.yaml` | Portuguese messages | FR-I18N-001 |
| `Resources/translations/NowoLoginThrottleBundle.pt_BR.yaml` | Brazilian Portuguese | FR-I18N-001 |
| `Resources/translations/NowoLoginThrottleBundle.ar.yaml` | Arabic messages | FR-I18N-001 |
| `Resources/translations/NowoLoginThrottleBundle.cs.yaml` | Czech messages | FR-I18N-001 |
| `Resources/translations/NowoLoginThrottleBundle.da.yaml` | Danish messages | FR-I18N-001 |
| `Resources/translations/NowoLoginThrottleBundle.el.yaml` | Greek messages | FR-I18N-001 |
| `Resources/translations/NowoLoginThrottleBundle.fi.yaml` | Finnish messages | FR-I18N-001 |
| `Resources/translations/NowoLoginThrottleBundle.hu.yaml` | Hungarian messages | FR-I18N-001 |
| `Resources/translations/NowoLoginThrottleBundle.ja.yaml` | Japanese messages | FR-I18N-001 |
| `Resources/translations/NowoLoginThrottleBundle.ko.yaml` | Korean messages | FR-I18N-001 |
| `Resources/translations/NowoLoginThrottleBundle.no.yaml` | Norwegian messages | FR-I18N-001 |
| `Resources/translations/NowoLoginThrottleBundle.pl.yaml` | Polish messages | FR-I18N-001 |
| `Resources/translations/NowoLoginThrottleBundle.ro.yaml` | Romanian messages | FR-I18N-001 |
| `Resources/translations/NowoLoginThrottleBundle.ru.yaml` | Russian messages | FR-I18N-001 |
| `Resources/translations/NowoLoginThrottleBundle.sv.yaml` | Swedish messages | FR-I18N-001 |
| `Resources/translations/NowoLoginThrottleBundle.tr.yaml` | Turkish messages | FR-I18N-001 |
| `Resources/translations/NowoLoginThrottleBundle.zh_CN.yaml` | Chinese (Simplified) | FR-I18N-001 |
| `Resources/translations/NowoLoginThrottleBundle.zh_TW.yaml` | Chinese (Traditional) | FR-I18N-001 |

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
