<?php

declare(strict_types=1);

namespace App\Tests\I18n\Unit;

use App\I18n\Locales;
use App\I18n\RequestLocale;
use App\Organisation\Entity\Etablissement;
use App\Securite\Service\ContexteEtablissement;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

final class RequestLocaleTest extends TestCase
{
    public function testAcceptLanguageKeepsTheFirstSpokenLanguageInPreferenceOrder(): void
    {
        self::assertSame('es', Locales::fromAcceptLanguage(['es_ES', 'fr']));
        self::assertSame('fr', Locales::fromAcceptLanguage(['en_GB', 'de', 'fr_CH']));
        self::assertNull(Locales::fromAcceptLanguage(['en', 'de']), 'aucune langue parlée : null, pas « fr »');
        self::assertNull(Locales::fromAcceptLanguage([]));
    }

    public function testHeaderWinsOverEstablishment(): void
    {
        $locale = $this->requestLocale(['Accept-Language' => 'es-ES,es;q=0.9'], 'fr');

        self::assertSame('es', $locale->current());
    }

    public function testEstablishmentWhenHeaderNamesNoSpokenLanguage(): void
    {
        self::assertSame('es', $this->requestLocale(['Accept-Language' => 'en-US,de;q=0.5'], 'es')->current());
        self::assertSame('es', $this->requestLocale([], 'es')->current());
    }

    public function testFrenchWithoutHeaderNorEstablishment(): void
    {
        self::assertSame('fr', $this->requestLocale([], null)->current());
        self::assertSame('fr', (new RequestLocale(new RequestStack(), $this->context(new RequestStack(), null)))->current());
    }

    /** Un document suit son établissement ; sans établissement, la langue source. */
    public function testDocumentFollowsItsEstablishment(): void
    {
        self::assertSame('es', Locales::ofEstablishment((new Etablissement())->setLocale('ES')));
        self::assertSame('fr', Locales::ofEstablishment(null));
    }

    /** @param array<string, string> $headers */
    private function requestLocale(array $headers, ?string $establishmentLocale): RequestLocale
    {
        $request = new Request();
        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }
        $establishment = null;
        if ($establishmentLocale !== null) {
            $establishment = (new Etablissement())->setLocale($establishmentLocale);
            $request->headers->set(ContexteEtablissement::HEADER, (string) Uuid::v4());
        }
        $stack = new RequestStack();
        $stack->push($request);

        return new RequestLocale($stack, $this->context($stack, $establishment));
    }

    private function context(RequestStack $stack, ?Etablissement $establishment): ContexteEtablissement
    {
        $repository = $this->createStub(EntityRepository::class);
        $repository->method('find')->willReturn($establishment);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repository);

        return new ContexteEtablissement($stack, $em);
    }
}
