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
 * REQ-DEMO-015: a FormKit form (profile `demo`). Fields of the API login test page; the page script posts them as
 * JSON to the json_login check path (email / password), so the form itself is never submitted and the json_login
 * authenticator has no CSRF token to check: the form keeps csrf_protection off.
 */
#[FormKitConfig('demo')]
final class ApiLoginFormType extends AbstractType
{
    use FormOptionsTrait;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->addEmail($builder, 'email', [
            'label' => 'Email',
            'translation_domain' => false,
            'placeholder' => false,
            'help' => false,
            'attr' => ['autocomplete' => 'email', 'autofocus' => true],
        ]);
        $this->addPassword($builder, 'password', [
            'label' => 'Password',
            'translation_domain' => false,
            'placeholder' => false,
            'help' => false,
            'attr' => ['autocomplete' => 'current-password'],
        ]);
        $this->addWithDefaults($builder, 'submit', SubmitType::class, [
            'label' => 'API Login',
            'translation_domain' => false,
            'attr' => ['class' => 'nowo-ui-btn nowo-ui-btn--primary'],
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
