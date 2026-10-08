<?php

declare(strict_types=1);

namespace App\Tests\Boutique\Api;

use App\Boutique\DataFixtures\BoutiqueFixtures;
use App\Boutique\Security\PanierProprietaireGuard;
use App\DataFixtures\SocleFixtures;
use App\Offre\Entity\Produit;
use App\Organisation\Entity\Etablissement;
use App\Organisation\Entity\Groupe;
use App\Organisation\Entity\Region;
use App\Reservation\Entity\Activite;
use App\Reservation\Entity\Creneau;
use App\Reservation\Entity\Ressource;
use App\Reservation\Enum\StatutCreneau;
use App\Tests\Boutique\BoutiqueApiTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Le panier en ligne ne prend un créneau que s'il est celui du produit, sur le site de la vitrine (D8).
 *
 * L'ajout d'une ligne faisait un simple `find()` du créneau reçu : un client de la vitrine A pouvait
 * poser sur sa ligne le créneau d'un site d'un autre groupe, ou celui d'un autre produit. À la
 * confirmation, la réservation est prise sur ce créneau-là : la jauge d'un autre exploitant baisse,
 * et le billet porte ses horaires.
 *
 * Les refus répondent 404, comme un créneau qui n'existe pas (D3 : on ne révèle pas l'existence).
 */
final class CartSlotScopeTest extends BoutiqueApiTestCase
{
    public function testSlotOfASiteInAnotherGroupIsNotFound(): void
    {
        $timed = $this->product(BoutiqueFixtures::PRODUIT_TIMED_ENTRY_CODE);
        $siteB = $this->entite(Etablissement::class, ['nom' => SocleFixtures::ETAB_B_NOM]);
        $groupe = (new Groupe())->setNom('Groupe étranger (créneau)');
        $siteB->setRegion((new Region())->setNom('Région étrangère (créneau)')->setGroupe($groupe));
        $this->em()->persist($groupe);
        $this->em()->persist($siteB->getRegion());

        // Le même produit, programmé chez B : seul le site diffère.
        $slot = $this->slot($siteB, $timed);

        self::assertSame(404, $this->addLine($timed, (string) $slot->getId()), 'Le créneau d\'un site d\'un autre groupe ne doit pas entrer dans le panier de la vitrine A.');
    }

    public function testSlotOfAnotherProductOnTheSameSiteIsNotFound(): void
    {
        $timed = $this->product(BoutiqueFixtures::PRODUIT_TIMED_ENTRY_CODE);
        $siteA = $this->entite(Etablissement::class, ['nom' => SocleFixtures::ETAB_A_NOM]);

        // Même site, autre produit : seul le produit diffère.
        $slot = $this->slot($siteA, $this->product(BoutiqueFixtures::PRODUIT_SIMPLE_CODE));

        self::assertSame(404, $this->addLine($timed, (string) $slot->getId()), 'Le créneau d\'un autre produit ne doit pas entrer sur cette ligne.');
    }

    /** Un produit sans horaire gardait lui aussi le créneau reçu, et la confirmation le réservait. */
    public function testProductWithoutTimedEntryDoesNotTakeAForeignSlot(): void
    {
        $siteB = $this->entite(Etablissement::class, ['nom' => SocleFixtures::ETAB_B_NOM]);
        $simple = $this->product(BoutiqueFixtures::PRODUIT_SIMPLE_CODE);

        self::assertSame(404, $this->addLine($simple, (string) $this->slot($siteB, $simple)->getId()));
    }

    /** D3 : un créneau étranger et un créneau inexistant répondent pareil. */
    public function testUnknownSlotAnswersLikeAForeignOne(): void
    {
        self::assertSame(404, $this->addLine($this->product(BoutiqueFixtures::PRODUIT_TIMED_ENTRY_CODE), (string) Uuid::v4()));
    }

    /** Témoin : le créneau que la vitrine propose pour ce produit entre toujours. */
    public function testSlotOfferedByTheStorefrontIsAccepted(): void
    {
        $timed = $this->product(BoutiqueFixtures::PRODUIT_TIMED_ENTRY_CODE);
        $siteA = $this->entite(Etablissement::class, ['nom' => SocleFixtures::ETAB_A_NOM]);

        self::assertSame(201, $this->addLine($timed, (string) $this->slot($siteA, $timed)->getId()));
    }

    private function addLine(Produit $product, string $slotId): int
    {
        [$client, $panierId, $jeton] = $this->ouvrirPanierInviteA();

        return $client->request('POST', '/api/boutique/paniers/' . $panierId . '/lignes', [
            'headers' => [PanierProprietaireGuard::HEADER => $jeton],
            'json' => ['produit' => (string) $product->getId(), 'creneau' => $slotId],
        ])->getStatusCode();
    }

    private function product(string $code): Produit
    {
        return $this->entite(Produit::class, ['code' => $code]);
    }

    /** Un créneau à venir, planifié, sur une activité du site qui vend ce produit. */
    private function slot(Etablissement $site, Produit $product): Creneau
    {
        $em = $this->em();
        $ressource = (new Ressource())->setEtablissement($site)->setCodeType('salle')
            ->setLibelle('Salle ' . uniqid())->setCapacitePropre(20);
        $activite = (new Activite())->setEtablissement($site)->setLibelle('Activité ' . uniqid())
            ->setTypeActivite('culture')->setDureeMinutes(60)->setTarifReferenceMontant('15.00')
            ->setProduitTarifReference($product);
        $debut = (new \DateTimeImmutable('next monday'))->setTime(14, 0);
        $creneau = (new Creneau())->setRessource($ressource)->setActivite($activite)
            ->setDebut($debut)->setFin($debut->modify('+1 hour'))
            ->setCapacite(20)->setEtablissement($site)->setStatut(StatutCreneau::Planifie);
        $em->persist($ressource);
        $em->persist($activite);
        $em->persist($creneau);
        $em->flush();

        return $creneau;
    }
}
