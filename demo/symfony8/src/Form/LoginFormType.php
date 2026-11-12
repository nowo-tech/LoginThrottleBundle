<?php

declare(strict_types=1);

namespace App\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * REQ-DEMO-015: a FormKit form (profile `demo`). form_login fields (_username / _password) plus the CSRF token
 * form_login checks (enable_csrf: _csrf_token, token id "authenticate"). Unprefixed names (block prefix '') so the
 * security listener reads them as-is.
 */
#[FormKitConfig('demo')]
final class LoginFormType extends AbstractType
{
    use FormOptionsTrait;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->addEmail($builder, '_username', [
            'label' => 'Email',
            'translation_domain' => false,
            'placeholder' => false,
            'help' => false,
            'attr' => ['autocomplete' => 'email', 'autofocus' => true],
        ]);
        $this->addPassword($builder, '_password', [
            'label' => 'Password',
            'translation_domain' => false,
            'placeholder' => false,
            'help' => false,
            'attr' => ['autocomplete' => 'current-password'],
        ]);
        $this->addWithDefaults($builder, 'submit', SubmitType::class, [
            'label' => $options['submit_label'],
            'translation_domain' => false,
            'attr' => ['class' => 'nowo-ui-btn nowo-ui-btn--primary'],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'method' => 'POST',
            'csrf_protection' => true,
            'csrf_field_name' => '_csrf_token',
            'csrf_token_id' => 'authenticate',
            'submit_label' => 'Sign in',
        ]);
        $resolver->setAllowedTypes('submit_label', 'string');
    }

    public function getBlockPrefix(): string
    {
        return '';
    }
}
