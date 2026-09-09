<?php

declare(strict_types=1);

namespace App\Tests\Website;

use App\Tests\SocleApiTestCase;
use App\Website\Entity\ContentBlock;
use App\Website\Service\SiteBlocks;
use Doctrine\ORM\EntityManagerInterface;

/**
 * « Nous écrire » — la seule page par laquelle on peut joindre Fluvia sans vouloir s'inscrire.
 *
 * ⚠ **ELLE EXISTE PARCE QU'IL N'Y EN AVAIT AUCUNE.** Mesuré le 09/09 sur le site servi : pas une
 * adresse, pas un `mailto:`. Le seul chemin était le tunnel d'inscription — qui suppose qu'on veuille
 * déjà s'inscrire, et qui ne mène nulle part tant qu'aucun courriel ne sort de la plateforme.
 *
 * ⚠ **PAS DE FORMULAIRE, ET AUCUN TEST N'EN CHERCHE UN.** Un formulaire afficherait « message
 * envoyé » sans que rien ne parte. Ce qui est vérifié ici, c'est qu'on publie un moyen qui marche
 * VRAIMENT aujourd'hui — et que la page refuse d'en simuler un quand elle n'en a pas.
 */
final class ContactPageTest extends SocleApiTestCase
{
    public function testLaPageDonneUneAdresseSurLaquelleOnPeutCliquer(): void
    {
        $client = static::createClient();
        $this->semerLesBlocs();

        $client->request('GET', '/contact');
        self::assertResponseIsSuccessful();

        $html = (string) $client->getResponse()->getContent();

        // ⚠ L'ADRESSE ET SON LIEN, PAS SEULEMENT L'ADRESSE. Un texte non cliquable oblige à le
        //   recopier à la main, et une adresse recopiée à la main se recopie mal.
        self::assertStringContainsString('mailto:maxime@onefitness-services.com', $html);
        self::assertStringContainsString('maxime@onefitness-services.com', $html);
    }

    /**
     * ⚠ **UNE ADRESSE VIDE NE REND PAS UN `mailto:` VIDE.**
     *
     * C'est le cœur de cette page. Un `mailto:` sans destinataire ouvre le logiciel de courrier du
     * visiteur, il écrit, il envoie — et le message ne part nulle part. Il ne réessaiera pas, et
     * personne ne saura qu'il a essayé. La page dit qu'aucune adresse n'est publiée plutôt que de
     * simuler un moyen de contact.
     */
    public function testUneAdresseViderNeProduitPasDeLienMort(): void
    {
        $client = static::createClient();
        $this->semerLesBlocs();
        $this->viderLeBloc('contact.email');

        $client->request('GET', '/contact');
        self::assertResponseIsSuccessful('Une adresse absente ne doit pas casser la page.');

        $html = (string) $client->getResponse()->getContent();

        self::assertStringNotContainsString('mailto:', $html, 'Aucun lien de courriel sans adresse.');
        self::assertStringContainsString('Aucune adresse', $html);
    }

    /**
     * On y arrive depuis n'importe quelle page, et les moteurs la connaissent.
     *
     * ⚠ Une page de contact qu'aucun lien ne désigne est une page que personne ne trouve. Le pied de
     * page est le seul endroit présent sur toutes les pages du site.
     */
    public function testOnLaTrouveDepuisLePiedDePageEtLePlanDuSite(): void
    {
        $client = static::createClient();

        $client->request('GET', '/');
        self::assertStringContainsString('/contact', (string) $client->getResponse()->getContent());

        $client->request('GET', '/metiers');
        self::assertStringContainsString('/contact', (string) $client->getResponse()->getContent());

        $client->request('GET', '/sitemap.xml');
        self::assertStringContainsString(
            '/contact</loc>',
            (string) $client->getResponse()->getContent(),
            'Une page absente du plan du site est une page que les moteurs ignorent.',
        );
    }

    /**
     * Les trois blocs sont administrables, et rangés à part.
     *
     * ⚠ Sans groupe distinct, ils tomberaient dans l'onglet « Page d'accueil » de l'administration,
     * au milieu de quinze autres champs — c'est-à-dire nulle part.
     */
    public function testLesBlocsDeContactSontDeclaresDansLeurPropreGroupe(): void
    {
        static::createClient();

        $groupes = [];

        foreach (SiteBlocks::all() as $bloc) {
            if (str_starts_with($bloc['key'], 'contact.')) {
                $groupes[$bloc['key']] = $bloc['groupe'];
            }
        }

        self::assertSame(
            ['contact.lead' => 'contact', 'contact.email' => 'contact', 'contact.details' => 'contact'],
            $groupes,
        );

        // Témoin : les blocs d'accueil n'ont pas bougé de groupe au passage. Le paramètre ajouté à
        // `bloc()` a un défaut, et un défaut mal posé aurait déplacé TOUTE la page d'accueil.
        foreach (SiteBlocks::all() as $bloc) {
            if (str_starts_with($bloc['key'], 'home.')) {
                self::assertSame('accueil', $bloc['groupe'], $bloc['key']);
            }
        }
    }

    // ---------------------------------------------------------------- montage

    /**
     * Sème les blocs déclarés avec leur valeur d'origine — ce que fait `website:blocks:seed` au
     * déploiement. Sans ça, la page servirait des blocs vides et le premier test mesurerait le repli.
     */
    private function semerLesBlocs(): void
    {
        $em = $this->em();

        foreach (SiteBlocks::all() as $declare) {
            if (!str_starts_with($declare['key'], 'contact.')) {
                continue;
            }

            $bloc = (new ContentBlock($declare['key']))->setValue($declare['initialValue'], new \DateTimeImmutable());
            $em->persist($bloc);
        }

        $em->flush();
    }

    private function viderLeBloc(string $cle): void
    {
        $em = $this->em();
        $bloc = $em->getRepository(ContentBlock::class)->find($cle);

        self::assertInstanceOf(ContentBlock::class, $bloc, 'Le témoin doit partir d’un bloc qui existe.');

        $bloc->setValue(['text' => ''], new \DateTimeImmutable());
        $em->flush();
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }
}
