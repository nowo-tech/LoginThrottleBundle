<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Fields of the API login test page; the page script posts them as JSON to the json_login check path
 * (email / password), so the form itself is never submitted and needs no CSRF token.
 */
final class ApiLoginFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, [
                'label'    => 'Email',
                'row_attr' => ['class' => 'form-group'],
                'attr'     => ['class' => 'form-control', 'autocomplete' => 'email', 'autofocus' => true],
            ])
            ->add('password', PasswordType::class, [
                'label'    => 'Password',
                'row_attr' => ['class' => 'form-group'],
                'attr'     => ['class' => 'form-control', 'autocomplete' => 'current-password'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => false,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return '';
    }
}
