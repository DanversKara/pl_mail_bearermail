<?php

declare(strict_types=1);

namespace App\Form\Admin;

use App\Form\SecretType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The outbound SMTP relay (e.g. Mailjet) for the plMail admin UI.
 *
 * Three fields, no entity: the writer persists straight to
 * /etc/plmail-mail-config/relay.conf, which the mta container watches.
 * The password field is write-only — it never renders the stored value, so
 * an empty submission means "keep what is on file". Clearing the host field
 * removes the relay entirely (direct delivery).
 */
final class RelayConfigType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('host', TextType::class, [
                'required' => false,
                'label'    => 'admin.mail_server.relay.host_label',
                'help'     => 'admin.mail_server.relay.host_help',
                'attr'     => ['placeholder' => '[in-v3.mailjet.com]:587'],
            ])
            ->add('username', TextType::class, [
                'required' => false,
                'label'    => 'admin.mail_server.relay.username_label',
                'help'     => 'admin.mail_server.relay.username_help',
            ])
            // SecretType, not PasswordType: a password input here is one a
            // password manager fills with this site's login, and saving the
            // form would then make that the relay's password.
            ->add('password', SecretType::class, [
                'required' => false,
                'label'    => 'admin.mail_server.relay.password_label',
                'help'     => 'admin.mail_server.relay.password_help',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            // Bound to a plain array, not an entity.
            'data_class'       => null,
            'translation_domain' => 'messages',
        ]);
    }
}
