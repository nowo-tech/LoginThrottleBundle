# Code inventory — 100% traceability

**Baseline spec**: [`spec.md`](spec.md)  
**Package**: `nowo-tech/login-throttle-bundle`  
**Last audited**: 2026-11-22 (v3.16.3)

## Symfony config

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Resources/config/services.yaml` | Service wiring | FR-DI-001 |

## Bundle & DI

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `NowoLoginThrottleBundle.php` | Bundle entry | FR-BUNDLE-001 |
| `DependencyInjection/Compiler/AttemptStorePass.php` | Store alias resolution | FR-ORM-006 |
| `DependencyInjection/Configuration.php` | Config tree | FR-CFG-001 |
| `DependencyInjection/NowoLoginThrottleExtension.php` | DI extension | FR-CFG-002 |

## CLI

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Command/ConfigureSecurityCommand.php` | Security YAML scaffold | FR-CLI-001 |
| `Command/CleanupLoginAttemptsCommand.php` | `nowo:login-throttle:cleanup` — prunes `LoginAttempt` rows older than the watch period (database storage) | FR-CLI-002, FR-ORM-002 |

## Persistence

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Entity/LoginAttempt.php` | Login attempt entity | FR-ORM-001 |
| `Repository/LoginAttemptRepository.php` | Attempt queries/cleanup | FR-ORM-002 |
| `Repository/LoginAttemptRepositoryInterface.php` | Repository contract for DI / tests | FR-ORM-002 |
| `Doctrine/TablePrefixListener.php` | `doctrine.table_prefix` metadata listener | FR-ORM-004 |
| `Doctrine/ExactCollationListener.php` | Byte-exact NO PAD collation (REQ-SEC-006) | FR-ORM-005 |
| `Repository/LoginAttemptStoreInterface.php` | Entity-free store port | FR-ORM-006 |
| `Repository/LoginAttemptRecorderInterface.php` | Entity-free write port | FR-ORM-006 |
| `Repository/LoginAttemptViewRepositoryInterface.php` | Entity-free read port | FR-ORM-006 |
| `Repository/LoginAttemptWithoutUsernameReaderInterface.php` | Reads without a username | FR-ORM-006 |
| `Repository/LoginAttemptView.php` | Read model of one attempt | FR-ORM-006 |
| `Repository/LoginAttemptLockInterface.php` | Per-IP serialisation port | FR-ORM-008 |
| `Transaction/AttemptLockRunner.php` | Per-IP lock + own transaction | FR-ORM-008 |
| `Testing/LoginAttemptStoreContractTestCase.php` | Store conformance tests for hosts | FR-ORM-007 |

## Rate limiting

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `RateLimiter/DatabaseRateLimiter.php` | DB-backed limiter | FR-LIMIT-001 |
| `RateLimiter/DatabaseRateLimiterFactory.php` | Limiter factory | FR-LIMIT-001 |
| `Throttle/ThrottleRules.php` | Limiter rules | FR-LIMIT-003 |
| `Throttle/ThrottleStatus.php` | Read-only bucket state | FR-LIMIT-003, FR-SVC-002 |
| `Throttle/UsernameNormalizer.php` | Username extraction / normalisation | FR-LIMIT-003 |
| `Event/LoginThrottledEvent.php` | Refused attempt event | FR-LIMIT-004 |
| `Event/LoginAttemptsClearedEvent.php` | Bucket cleared event | FR-LIMIT-004 |

## Services

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `DependencyInjection/Compiler/ConflictResponsePass.php` | 3.16: wires the conflict answer by `conflict_response.mode` (`host` removes the subscriber; `bundle` uses transaction-doctrine's policy/responder or 409 + `Retry-After: 1`) | FR-CONC-001 |
| `EventSubscriber/StorageFailureSubscriber.php` | 3.16: answers a transient attempt-store failure of a guarded login with 409 (or 503) + `Retry-After` + `CONFLICT_001`; fails closed, not an account oracle | FR-CONC-001, FR-CONC-002 |
| `EventSubscriber/LoginConflictListener.php` | 3.16: above every renderer, ends the login when the failure came from the post-password reset and removes the commit mark so the code is always `CONFLICT_001` | FR-CONC-002 |
| `Transaction/LoginThrottleStorageFailure.php` | 3.16: `TransientStorageFailure` raised by the bundle only, generic message, always `ConflictCode::RetrySafe` | FR-CONC-001 |
| `Service/LoginThrottleInfoService.php` | Throttle status info | FR-SVC-001 |
| `Service/LoginThrottleStatusProviderInterface.php` | Status port | FR-SVC-002 |
| `Service/LoginThrottleStatusProvider.php` | Default status provider | FR-SVC-002 |

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
| Bundle & DI | 4 | 4 |
| CLI | 2 | 2 |
| Persistence | 13 | 13 |
| Rate limiting | 7 | 7 |
| Services | 7 | 7 |
| Translations | 24 | 24 |
| **Total production sources** | **58** | **58** |

Audit command (production count): `git ls-files src | wc -l` → **58** (2026-11-22).
