<?php

declare(strict_types=1);

namespace App\Tests\Acces\Api;

use App\Acces\Entity\Appairage;
use App\Acces\Entity\Controleur;
use App\Acces\Entity\DroitAcces;
use App\Acces\Entity\Equipement;
use App\Acces\Entity\EspaceAcces;
use App\Acces\Entity\Support;
use App\Acces\Enum\ModeAppairage;
use App\Acces\Enum\SensEquipement;
use App\Acces\Enum\StatutProjectionDroit;
use App\Acces\Enum\TypeDroitAcces;
use App\Acces\Enum\TypeEquipement;
use App\Acces\Enum\TypeSupport;
use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Espace;
use App\Organisation\Entity\Etablissement;
use App\Tests\Acces\AccesApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Sous-réseau / accès fédéré (US-L3-12, CA-13) : fédération activée + droit éligible → franchissement
 * fédéré ; fédération désactivée → franchissement inter-entités refusé.
 */
final class SousReseauTest extends AccesApiTestCase
{
    public function testCa13FederationActiveAutoriseLeFranchissementFedere(): void
    {
        [$client, $entete] = $this->adminSurA();

        [$equipementB, $sousReseauIri, $identifiantSupport] = $this->creerTopologieFedereeEtDroit(true);

        $client->request('POST', '/api/acces/passages', $entete + [
            'json' => ['equipement' => '/api/equipements/' . $equipementB, 'identifiantSupport' => $identifiantSupport],
        ]);
        self::assertSame('valide', $client->getResponse()->toArray()['resultat']);
    }

    public function testCa13FederationDesactiveeRefuseLeFranchissementInterEntites(): void
    {
        [$client, $entete] = $this->adminSurA();

        [$equipementB, $sousReseauIri, $identifiantSupport] = $this->creerTopologieFedereeEtDroit(false);

        $client->request('POST', '/api/acces/passages', $entete + [
            'json' => ['equipement' => '/api/equipements/' . $equipementB, 'identifiantSupport' => $identifiantSupport],
        ]);
        $reponse = $client->getResponse()->toArray();
        self::assertSame('refuse', $reponse['resultat']);
        self::assertSame('federation_inactive', $reponse['codeMotif']);
    }

    /**
     * @return array{0: string, 1: string, 2: string} idEquipementEspaceB, iriSousReseau, identifiantSupport
     */
    private function creerTopologieFedereeEtDroit(bool $federationActive): array
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $etab = $this->entite(Etablissement::class, ['nom' => SocleFixtures::ETAB_A_NOM]);

        $sousReseau = new \App\Acces\Entity\SousReseau();
        $sousReseau->setLibelle('Fédération piscines A')->setActif($federationActive);
        $em->persist($sousReseau);

        $espaceSocle = (new Espace())->setNom('Espace fédéré B')->setEtablissement($etab)->setType('bassin');
        $em->persist($espaceSocle);

        $espaceB = (new EspaceAcces())->setLibelle('Espace fédéré B')->setEspaceSocle($espaceSocle)->setSeuilFmi(20)->setSousReseau($sousReseau);
        $em->persist($espaceB);
        $sousReseau->addEspace($espaceB);

        $controleur = (new Controleur())->setLibelle('Contrôleur fédéré B')->setEspace($espaceB)->setItboxRef('ITBOX-FED-B');
        $em->persist($controleur);

        $equipement = (new Equipement())->setLibelle('Entrée fédérée B')->setControleur($controleur)->setType(TypeEquipement::Tourniquet)->setSens(SensEquipement::Entree);
        $em->persist($equipement);

        $droit = (new DroitAcces())
            ->setSourceType(TypeDroitAcces::Billet)
            ->setStatutProjection(StatutProjectionDroit::Valide)
            ->setSousReseau($sousReseau)
            ->setEtablissement($etab);

        // D87 : sans zone déclarée, ce droit n'ouvrirait aucune porte, et le test de FÉDÉRATION
        // échouerait pour une raison qui n'a rien à voir avec le sous-réseau. On déclare l'espace
        // fédéré B, celui que l'équipement franchi dessert.
        $droit->addAuthorisedSpace($espaceB);

        $em->persist($droit);

        $identifiant = 'FED-' . substr((string) Uuid::v4(), 0, 8);
        $support = (new Support())->setIdentifiant($identifiant)->setType(TypeSupport::Qr)->setEtablissement($etab);
        $em->persist($support);
        $em->persist((new Appairage())->setSupport($support)->setDroit($droit)->setMode(ModeAppairage::Caisse)->setActif(true)->setEtablissement($etab));

        $em->flush();

        return [(string) $equipement->getId(), '/api/sous_reseaus/' . $sousReseau->getId(), $identifiant];
    }
}
