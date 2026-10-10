<?php

declare(strict_types=1);

namespace App\Infrastructure\Translation;

use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\HttpKernel\CacheWarmer\WarmableInterface;
use Symfony\Component\Translation\MessageCatalogueInterface;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Shows this install's own product name wherever the interface says "plMail".
 *
 * A decorator rather than an edited catalogue, on purpose. The translation
 * files carry the name in a few hundred strings, and every one of them is a
 * line upstream also edits; changing them would turn each upstream merge into
 * a pile of conflicts. Here the files stay exactly upstream's and the name is
 * swapped on the way out, in every locale at once.
 *
 * `app.name` — the short name in page titles and beside the logo — becomes the
 * brand as well. Only translated text is touched: internal identifiers (user
 * agents, iCalendar PRODIDs, backup format markers) keep saying plMail, which
 * is what they are.
 *
 * APP_BRAND_NAME sets the name (default "BearerMail"); set it to "plMail" to
 * get the upstream wording back.
 */
#[AsDecorator('translator')]
final class BrandingTranslator implements TranslatorInterface, TranslatorBagInterface, LocaleAwareInterface, WarmableInterface
{
    public function __construct(
        #[AutowireDecorated]
        private readonly TranslatorInterface&TranslatorBagInterface&LocaleAwareInterface $inner,
        #[Autowire('%env(default:app.brand_name_default:APP_BRAND_NAME)%')]
        private readonly string $brand,
    ) {
    }

    public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
    {
        if ('app.name' === $id && (null === $domain || 'messages' === $domain)) {
            return $this->brand;
        }

        $text = $this->inner->trans($id, $parameters, $domain, $locale);

        return 'plMail' === $this->brand || false === str_contains($text, 'plMail')
            ? $text
            : str_replace('plMail', $this->brand, $text);
    }

    public function getLocale(): string
    {
        return $this->inner->getLocale();
    }

    public function setLocale(string $locale): void
    {
        $this->inner->setLocale($locale);
    }

    public function getCatalogue(?string $locale = null): MessageCatalogueInterface
    {
        return $this->inner->getCatalogue($locale);
    }

    public function getCatalogues(): array
    {
        return $this->inner->getCatalogues();
    }

    public function warmUp(string $cacheDir, ?string $buildDir = null): array
    {
        return $this->inner instanceof WarmableInterface ? $this->inner->warmUp($cacheDir, $buildDir) : [];
    }
}
