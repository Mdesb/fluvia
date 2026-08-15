<?php

declare(strict_types=1);

namespace App\Tests\Piscine\Api;

use App\Acces\Entity\Appairage;
use App\Acces\Entity\Controleur;
use App\Acces\Entity\DroitAcces;
use App\Acces\Entity\Equipement;
use App\Acces\Entity\EspaceAcces;
use App\Acces\Entity\Support;
use App\Acces\Enum\ModeAppairage;
use App\Acces\Enum\ModeSeuil;
use App\Acces\Enum\SensEquipement;
use App\Acces\Enum\StatutProjectionDroit;
use App\Acces\Enum\TypeDroitAcces;
use App\Acces\Enum\TypeEquipement;
use App\Acces\Enum\TypeSupport;
use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Espace;
use App\Organisation\Entity\Etablissement;
use App\Piscine\Entity\Poss;
use App\Piscine\Enum\PerimetrePoss;
use App\Tests\Piscine\PiscineApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * POSS = spécialisation piscine de la jauge FMI L3 (RG-PISC-01, CA-2, CA-3). « Une sortie = une
 * entrée » et la journalisation du seuil sont déjà couvertes par L3 (plan §0) : ces tests vérifient
 * l'intégration (branchement sur un `EspaceAcces` référencé par une `Poss`), pas un nouveau moteur.
 */
final class PossTest extends PiscineApiTestCase
{
    public function testCa2SeuilBlocageRefuseAuSeuilEtSortieLibereExactementUnePlace(): void
    {
        [$client, $entete] = $this->adminSurA();

        [$equipementEntree, $equipementSortie, $supportA, $supportB] = $this->creerBassinPossSeuilUn();

        $client->request('POST', '/api/acces/passages', $entete + [
            'json' => ['equipement' => '/api/equipements/' . $equipementEntree, 'identifiantSupport' => $supportA],
        ]);
        self::assertSame('valide', $client->getResponse()->toArray()['resultat']);

        // Seuil POSS atteint : la deuxième entrée est refusée par le tripode (RG-PISC-01).
        $client->request('POST', '/api/acces/passages', $entete + [
            'json' => ['equipement' => '/api/equipements/' . $equipementEntree, 'identifiantSupport' => $supportB],
        ]);
        $reponse = $client->getResponse()->toArray();
        self::assertSame('refuse', $reponse['resultat']);
        self::assertSame('seuil_fmi', $reponse['codeMotif']);

        // Une sortie validée libère EXACTEMENT une place (décision actée « une sortie = une entrée »).
        $client->request('POST', '/api/acces/passages/non-nominatif', $entete + [
            'json' => ['equipement' => '/api/equipements/' . $equipementSortie, 'sens' => 'sortie', 'motif' => 'sortie test'],
        ]);
        self::assertResponseIsSuccessful();

        $client->request('POST', '/api/acces/passages', $entete + [
            'json' => ['equipement' => '/api/equipements/' . $equipementEntree, 'identifiantSupport' => $supportB],
        ]);
        self::assertSame('valide', $client->getResponse()->toArray()['resultat'], 'Nouvelle entrée acceptée après la sortie.');
    }

    public function testCa2SeuilEspaceAccesModifiableEtJournalise(): void
    {
        [$client, $entete] = $this->adminSurA();

        $espace = $this->entite(EspaceAcces::class, ['libelle' => \App\Acces\DataFixtures\AccesFixtures::ESPACE_LIBELLE]);

        $client->request('PATCH', '/api/espace_acces/' . $espace->getId(), $this->entetePatch($entete) + [
            'json' => ['seuilFmi' => 75],
        ]);
        self::assertResponseIsSuccessful();
        self::assertSame(75, $client->getResponse()->toArray()['seuilFmi']);

        // Vérifié directement en base (pagination API non fiable ici : `itemsPerPage` client est
        // désactivé par défaut côté API Platform, la liste n'est pas forcément exhaustive en page 1).
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $entree = $em->getRepository(\App\Audit\Entity\EntreeAudit::class)->createQueryBuilder('a')
            ->andWhere('a.cibleType = :type')
            ->andWhere('a.cibleId = :id')
            ->andWhere('a.action = :action')
            ->setParameter('type', EspaceAcces::class)
            ->setParameter('id', (string) $espace->getId())
            ->setParameter('action', 'modification')
            ->getQuery()->getOneOrNullResult();
        self::assertNotNull($entree, 'La modification du seuil POSS (EspaceAcces.seuilFmi) doit être journalisée (CA-2).');
    }

    public function testGuardRefusePossSurEspaceEnModeAlerte(): void
    {
        [$client, $entete] = $this->adminSurA();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $etab = $this->entite(Etablissement::class, ['nom' => SocleFixtures::ETAB_A_NOM]);

        $espaceSocle = (new Espace())->setNom('Espace alerte test')->setEtablissement($etab)->setType('salle');
        $em->persist($espaceSocle);
        $espaceAlerte = (new EspaceAcces())->setLibelle('Espace POSS alerte')->setEspaceSocle($espaceSocle)->setSeuilFmi(10)->setModeSeuil(ModeSeuil::Alerte);
        $em->persist($espaceAlerte);
        $em->flush();

        $client->request('POST', '/api/posses', $entete + [
            'json' => [
                'espaceAcces' => '/api/espace_acces/' . $espaceAlerte->getId(),
                'perimetre' => 'etablissement',
            ],
        ]);
        self::assertResponseStatusCodeSame(422, 'RG-PISC-01 : une POSS doit être en blocage strict.');
    }

    public function testGuardRefusePatchEspaceAccesVersAlerteQuandPossExiste(): void
    {
        [$client, $entete] = $this->adminSurA();

        $espace = $this->entite(EspaceAcces::class, ['libelle' => \App\Acces\DataFixtures\AccesFixtures::ESPACE_LIBELLE]);

        $client->request('PATCH', '/api/espace_acces/' . $espace->getId(), $this->entetePatch($entete) + [
            'json' => ['modeSeuil' => 'alerte'],
        ]);
        self::assertResponseStatusCodeSame(422, 'Un EspaceAcces référencé par une POSS ne peut pas passer en alerte simple.');
    }

    public function testCa3PreAlerteEtTableauDeBord(): void
    {
        [$client, $entete] = $this->adminSurA();

        $espace = $this->entite(EspaceAcces::class, ['libelle' => \App\Acces\DataFixtures\AccesFixtures::ESPACE_LIBELLE]);

        // Seuil FMI = 50 (fixtures), pré-alerte à 10 % : franchie dès 5 présents.
        $client->request('PATCH', '/api/espace_acces/' . $espace->getId(), $this->entetePatch($entete) + [
            'json' => ['preAlertePct' => 10, 'seuilFmi' => 10],
        ]);
        self::assertResponseIsSuccessful();

        $possId = $this->idPoss();

        $client->request('GET', '/api/piscine/poss/' . $possId . '/etat', $entete);
        self::assertResponseIsSuccessful();
        $etat = $client->getResponse()->toArray();
        self::assertSame(0, $etat['presents']);
        self::assertFalse($etat['preAlerteAtteinte']);
        self::assertArrayHasKey('seuilPoss', $etat);
        self::assertArrayHasKey('placesReserveesRestantes', $etat);

        // Une entrée (10 % de 10 = 1) suffit à franchir la pré-alerte.
        $client->request('POST', '/api/acces/passages', $entete + [
            'json' => ['equipement' => '/api/equipements/' . $this->entite(Equipement::class, ['libelle' => \App\Acces\DataFixtures\AccesFixtures::EQUIPEMENT_LIBELLE])->getId(), 'identifiantSupport' => \App\Acces\DataFixtures\AccesFixtures::SUPPORT_IDENTIFIANT],
        ]);
        self::assertSame('valide', $client->getResponse()->toArray()['resultat']);

        $client->request('GET', '/api/piscine/poss/' . $possId . '/etat', $entete);
        $etat = $client->getResponse()->toArray();
        self::assertSame(1, $etat['presents']);
        self::assertTrue($etat['preAlerteAtteinte'], 'US-L6-03 : la pré-alerte doit être signalée dès le seuil X % franchi.');
    }

    /**
     * Crée un espace d'accès dédié + Poss au seuil 1, avec entrée/sortie et deux supports prêts à
     * franchir (billets simples, sans crédit).
     *
     * @return array{0: string, 1: string, 2: string, 3: string} idEquipementEntree, idEquipementSortie, identifiantSupportA, identifiantSupportB
     */
    private function creerBassinPossSeuilUn(): array
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $etab = $this->entite(Etablissement::class, ['nom' => SocleFixtures::ETAB_A_NOM]);

        $espaceSocle = (new Espace())->setNom('Espace POSS test')->setEtablissement($etab)->setType('bassin');
        $em->persist($espaceSocle);

        $espaceAcces = (new EspaceAcces())->setLibelle('POSS test seuil 1')->setEspaceSocle($espaceSocle)->setSeuilFmi(1)->setModeSeuil(ModeSeuil::Blocage);
        $em->persist($espaceAcces);

        $controleur = (new Controleur())->setLibelle('Contrôleur POSS test')->setEspace($espaceAcces)->setItboxRef('ITBOX-POSS-TEST');
        $em->persist($controleur);

        $entree = (new Equipement())->setLibelle('Entrée POSS test')->setControleur($controleur)->setType(TypeEquipement::Tourniquet)->setSens(SensEquipement::Entree);
        $em->persist($entree);
        $sortie = (new Equipement())->setLibelle('Sortie POSS test')->setControleur($controleur)->setType(TypeEquipement::Tourniquet)->setSens(SensEquipement::Sortie);
        $em->persist($sortie);

        $poss = (new Poss())->setEspaceAcces($espaceAcces)->setPerimetre(PerimetrePoss::Etablissement)->setReservationsProtegees(true);
        $em->persist($poss);

        $identifiantA = 'POSS-A-' . substr((string) Uuid::v4(), 0, 8);
        $identifiantB = 'POSS-B-' . substr((string) Uuid::v4(), 0, 8);
        foreach ([$identifiantA, $identifiantB] as $identifiant) {
            $droit = (new DroitAcces())->setSourceType(TypeDroitAcces::Billet)->setStatutProjection(StatutProjectionDroit::Valide)->setEtablissement($etab);
            $em->persist($droit);
            $support = (new Support())->setIdentifiant($identifiant)->setType(TypeSupport::Qr)->setEtablissement($etab);
            $em->persist($support);
            $em->persist((new Appairage())->setSupport($support)->setDroit($droit)->setMode(ModeAppairage::Caisse)->setActif(true)->setEtablissement($etab));
        }

        $em->flush();

        return [(string) $entree->getId(), (string) $sortie->getId(), $identifiantA, $identifiantB];
    }
}
