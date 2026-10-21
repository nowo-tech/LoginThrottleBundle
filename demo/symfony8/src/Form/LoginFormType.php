<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * form_login fields (_username / _password) plus the CSRF token form_login checks (enable_csrf: _csrf_token, token id
 * "authenticate"). Unprefixed names (block prefix '') so the security listener reads them as-is (REQ-TWIG-005).
 */
final class LoginFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('_username', EmailType::class, [
                'label'    => 'Email',
                'row_attr' => ['class' => 'form-group'],
                'attr'     => ['class' => 'form-control', 'autocomplete' => 'email', 'autofocus' => true],
            ])
            ->add('_password', PasswordType::class, [
                'label'    => 'Password',
                'row_attr' => ['class' => 'form-group'],
                'attr'     => ['class' => 'form-control', 'autocomplete' => 'current-password'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'method'          => 'POST',
            'csrf_protection' => true,
            'csrf_field_name' => '_csrf_token',
            'csrf_token_id'   => 'authenticate',
        ]);
    }

    public function getBlockPrefix(): string
    {
        return '';
    }
}
