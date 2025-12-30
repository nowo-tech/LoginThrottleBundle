# Translations Guide

This guide explains how to handle translations for the Login Throttle Bundle.

## Overview

The bundle includes built-in translation files for Spanish and English, but you can override them or add support for additional languages in your application.

## Installing the Translation Component

The bundle requires the Symfony Translation component for i18n support. Install it if it's not already available:

```bash
composer require symfony/translation
```

> **Note**: The translation component is optional but recommended. The bundle will work without it, but messages will not be translated and will display as translation keys.

## Configuring the Translator

Enable the translator in your `config/packages/framework.yaml`:

```yaml
framework:
    translator:
        default_path: '%kernel.project_dir%/translations'
        fallbacks:
            - en
        enabled: true
```

The `default_path` specifies where your application's translation files are located. The `fallbacks` array defines which language to use when a translation is missing for the current locale.

## Bundle Translation Files

The bundle includes translation files in two languages:

- **English**: `src/Resources/translations/nowo_login_throttle.en.yaml`
- **Spanish**: `src/Resources/translations/nowo_login_throttle.es.yaml`

These files are automatically loaded by Symfony and contain all the translation keys used by the bundle.

### Translation Domain

All bundle translations use the `nowo_login_throttle` domain. This ensures they don't conflict with your application's translations.

## Available Translation Keys

The bundle provides the following translation keys:

### Error Messages

| Key | Parameters | Description |
|-----|-----------|-------------|
| `nowo_login_throttle.error.authentication_failed` | - | Message displayed when credentials are invalid |
| `nowo_login_throttle.error.account_blocked` | `%max_attempts%` | Message when account is blocked due to too many attempts |
| `nowo_login_throttle.error.retry_after` | `%retry_after%` | Message showing when the user can try again (time format) |

### Information Messages

| Key | Parameters | Description |
|-----|-----------|-------------|
| `nowo_login_throttle.info.attempts_count_by_ip` | `%current%`, `%max%` | Shows current attempts from this IP address |
| `nowo_login_throttle.info.attempts_count_by_email` | `%current%`, `%max%` | Shows current attempts for this email/username |
| `nowo_login_throttle.info.remaining_attempts` | `%remaining%` | Shows remaining attempts before blocking |
| `nowo_login_throttle.info.last_attempt_warning` | - | Warning message when only one attempt remains |

## Default Translations

### English

```yaml
nowo_login_throttle:
    error:
        authentication_failed: "Invalid credentials."
        account_blocked: "Account temporarily blocked. You have exceeded the maximum number of attempts (%max_attempts%)."
        retry_after: "You can try again after: %retry_after%"
    info:
        attempts_count: "Login attempts: %current% of %max%"
        attempts_count_by_ip: "Login attempts from this IP: %current% of %max%"
        attempts_count_by_email: "Login attempts for this email: %current% of %max%"
        remaining_attempts: "Remaining attempts before blocking: %remaining%"
        last_attempt_warning: "Last attempt available. The next failure will block your account."
```

### Spanish

```yaml
nowo_login_throttle:
    error:
        authentication_failed: "Credenciales inválidas."
        account_blocked: "Cuenta bloqueada temporalmente. Has excedido el número máximo de intentos (%max_attempts%)."
        retry_after: "Podrás intentar nuevamente después de: %retry_after%"
    info:
        attempts_count: "Intentos de login: %current% de %max%"
        attempts_count_by_ip: "Intentos de login desde esta IP: %current% de %max%"
        attempts_count_by_email: "Intentos de login para este email: %current% de %max%"
        remaining_attempts: "Intentos restantes antes del bloqueo: %remaining%"
        last_attempt_warning: "Último intento disponible. El siguiente fallo bloqueará tu cuenta."
```

## Overriding Translations

You can override the bundle's translations by creating your own translation files in your application's `translations/` directory.

### File Naming Convention

Translation files must follow this naming pattern:

```
translations/nowo_login_throttle.{locale}.yaml
```

For example:
- `translations/nowo_login_throttle.en.yaml` (English)
- `translations/nowo_login_throttle.es.yaml` (Spanish)
- `translations/nowo_login_throttle.fr.yaml` (French)
- `translations/nowo_login_throttle.de.yaml` (German)

### File Structure

Your custom translation files must use the same structure as the bundle's files:

```yaml
# translations/nowo_login_throttle.en.yaml
nowo_login_throttle:
    error:
        authentication_failed: "Your custom error message"
        account_blocked: "Your custom blocked message (%max_attempts%)"
        retry_after: "Your custom retry message (%retry_after%)"
    info:
        attempts_count_by_ip: "Your custom IP message (%current% of %max%)"
        attempts_count_by_email: "Your custom email message (%current% of %max%)"
        remaining_attempts: "Your custom remaining message (%remaining%)"
        last_attempt_warning: "Your custom warning message"
```

> **Important**: The root key must be `nowo_login_throttle:` (matching the domain name) when the file is named `nowo_login_throttle.{locale}.yaml`.

### Partial Overrides

You can override only specific keys. Symfony will use your translations where they exist and fall back to the bundle's translations for keys you don't override:

```yaml
# translations/nowo_login_throttle.en.yaml
nowo_login_throttle:
    error:
        account_blocked: "Custom blocked message (%max_attempts%)"
    # Other keys will use bundle defaults
```

## Using Translations in Templates

### Basic Usage

Use the `trans` filter with the translation key and domain:

```twig
{{ 'nowo_login_throttle.error.account_blocked'|trans({'%max_attempts%': 3}, 'nowo_login_throttle') }}
```

### With LoginThrottleInfoService

When using `LoginThrottleInfoService` to display attempt information, you'll typically use translations like this:

```twig
{% if error and attempt_info %}
    {% if attempt_info.is_blocked %}
        <div class="alert alert-danger">
            ⚠️ {{ 'nowo_login_throttle.error.account_blocked'|trans({'%max_attempts%': attempt_info.max_attempts}, 'nowo_login_throttle') }}
            {% if attempt_info.retry_after %}
                <br>
                {{ 'nowo_login_throttle.error.retry_after'|trans({'%retry_after%': attempt_info.retry_after|date('H:i:s')}, 'nowo_login_throttle') }}
            {% endif %}
        </div>
    {% else %}
        <div class="alert alert-info">
            📊 {% if attempt_info.tracking_type == 'username' %}
                {{ 'nowo_login_throttle.info.attempts_count_by_email'|trans({
                    '%current%': attempt_info.current_attempts|default(0),
                    '%max%': attempt_info.max_attempts|default(3)
                }, 'nowo_login_throttle') }}
            {% else %}
                {{ 'nowo_login_throttle.info.attempts_count_by_ip'|trans({
                    '%current%': attempt_info.current_attempts|default(0),
                    '%max%': attempt_info.max_attempts|default(3)
                }, 'nowo_login_throttle') }}
            {% endif %}
            
            {% if attempt_info.remaining_attempts > 0 %}
                <br>
                {{ 'nowo_login_throttle.info.remaining_attempts'|trans({
                    '%remaining%': attempt_info.remaining_attempts|default(0)
                }, 'nowo_login_throttle') }}
            {% else %}
                <br>
                ⚠️ {{ 'nowo_login_throttle.info.last_attempt_warning'|trans({}, 'nowo_login_throttle') }}
            {% endif %}
        </div>
    {% endif %}
{% endif %}
```

### Parameter Handling

Always use default values for parameters to prevent display issues:

```twig
{{ 'nowo_login_throttle.info.attempts_count_by_email'|trans({
    '%current%': attempt_info.current_attempts|default(0),
    '%max%': attempt_info.max_attempts|default(3)
}, 'nowo_login_throttle') }}
```

## Adding New Languages

To add support for a new language:

1. Create a new translation file in `translations/`:

```bash
# Example: Adding French support
touch translations/nowo_login_throttle.fr.yaml
```

2. Add translations for all keys:

```yaml
# translations/nowo_login_throttle.fr.yaml
nowo_login_throttle:
    error:
        authentication_failed: "Identifiants invalides."
        account_blocked: "Compte temporairement bloqué. Vous avez dépassé le nombre maximum de tentatives (%max_attempts%)."
        retry_after: "Vous pourrez réessayer après : %retry_after%"
    info:
        attempts_count_by_ip: "Tentatives de connexion depuis cette IP : %current% sur %max%"
        attempts_count_by_email: "Tentatives de connexion pour cet email : %current% sur %max%"
        remaining_attempts: "Tentatives restantes avant blocage : %remaining%"
        last_attempt_warning: "Dernière tentative disponible. L'échec suivant bloquera votre compte."
```

3. Clear the cache:

```bash
php bin/console cache:clear
```

4. Configure your application to use the new locale when appropriate.

## Debugging Translations

### Check Available Translations

Use the Symfony console to debug translations:

```bash
# List all translations in the domain
php bin/console debug:translation nowo_login_throttle --domain=nowo_login_throttle

# Check a specific translation key
php bin/console debug:translation nowo_login_throttle.info.attempts_count_by_email --domain=nowo_login_throttle
```

### Verify File Loading

Ensure your translation files are in the correct location and have the correct structure. Symfony loads translations in this order:

1. Your application's `translations/` directory (highest priority)
2. Bundle's `Resources/translations/` directory (fallback)

### Common Issues

#### Translation keys appear instead of text

- Verify the translator is enabled in `framework.yaml`
- Check that `symfony/translation` is installed
- Ensure translation files are in the correct location
- Clear the cache: `php bin/console cache:clear`

#### Wrong language displayed

- Check your application's locale configuration
- Verify fallback languages in `framework.yaml`
- Ensure translation files exist for your locale

#### Parameters not replaced

- Verify parameter names match exactly (including `%` signs)
- Check that you're passing parameters in the `trans` filter
- Use default values to prevent null parameter issues

## Best Practices

1. **Always use the domain**: Always specify `'nowo_login_throttle'` as the domain when using translations to avoid conflicts.

2. **Provide default values**: Use `|default()` for parameters to prevent display issues when values are null.

3. **Keep structure consistent**: When overriding translations, maintain the same YAML structure as the bundle's files.

4. **Test all locales**: Test your application with all supported locales to ensure translations work correctly.

5. **Clear cache after changes**: Always clear the cache after adding or modifying translation files.

## Example: Complete Integration

Here's a complete example of integrating translations in a login controller:

```php
<?php

namespace App\Controller;

use Nowo\LoginThrottleBundle\Service\LoginThrottleInfoService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class SecurityController extends AbstractController
{
    #[Route('/login', name: 'app_login')]
    public function login(
        AuthenticationUtils $authenticationUtils,
        Request $request,
        LoginThrottleInfoService $throttleInfoService
    ): Response {
        $error = $authenticationUtils->getLastAuthenticationError();
        $lastUsername = $authenticationUtils->getLastUsername();
        
        // Get attempt information if there's an error
        $attemptInfo = null;
        if ($error) {
            $attemptInfo = $throttleInfoService->getAttemptInfo('main', $request, $lastUsername);
        }
        
        return $this->render('security/login.html.twig', [
            'last_username' => $lastUsername,
            'error' => $error,
            'attempt_info' => $attemptInfo,
        ]);
    }
}
```

And the corresponding Twig template:

```twig
{# templates/security/login.html.twig #}
{% if error %}
    <div class="alert alert-danger">
        {{ error.messageKey|trans(error.messageData, 'security') }}
    </div>
    
    {% if attempt_info %}
        <div class="alert alert-info">
            {% if attempt_info.is_blocked %}
                ⚠️ {{ 'nowo_login_throttle.error.account_blocked'|trans({
                    '%max_attempts%': attempt_info.max_attempts
                }, 'nowo_login_throttle') }}
                
                {% if attempt_info.retry_after %}
                    <br>
                    {{ 'nowo_login_throttle.error.retry_after'|trans({
                        '%retry_after%': attempt_info.retry_after|date('H:i:s')
                    }, 'nowo_login_throttle') }}
                {% endif %}
            {% else %}
                📊 {% if attempt_info.tracking_type == 'username' %}
                    {{ 'nowo_login_throttle.info.attempts_count_by_email'|trans({
                        '%current%': attempt_info.current_attempts|default(0),
                        '%max%': attempt_info.max_attempts|default(3)
                    }, 'nowo_login_throttle') }}
                {% else %}
                    {{ 'nowo_login_throttle.info.attempts_count_by_ip'|trans({
                        '%current%': attempt_info.current_attempts|default(0),
                        '%max%': attempt_info.max_attempts|default(3)
                    }, 'nowo_login_throttle') }}
                {% endif %}
                
                {% if attempt_info.remaining_attempts > 0 %}
                    <br>
                    {{ 'nowo_login_throttle.info.remaining_attempts'|trans({
                        '%remaining%': attempt_info.remaining_attempts|default(0)
                    }, 'nowo_login_throttle') }}
                {% else %}
                    <br>
                    ⚠️ {{ 'nowo_login_throttle.info.last_attempt_warning'|trans({}, 'nowo_login_throttle') }}
                {% endif %}
            {% endif %}
        </div>
    {% endif %}
{% endif %}
```

## Additional Resources

- [Symfony Translation Component Documentation](https://symfony.com/doc/current/translation.html)
- [Symfony Translation Best Practices](https://symfony.com/doc/current/translation.html#translation-parameters)
- [Bundle Configuration Guide](CONFIGURATION.md)
- [LoginThrottleInfoService Documentation](SERVICES.md)

