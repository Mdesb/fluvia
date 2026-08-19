<?php

declare(strict_types=1);

namespace App\Tests\Acces\Api;

use App\Acces\DataFixtures\AccesFixtures;
use App\Acces\Entity\Controleur;
use App\Acces\Entity\DroitAcces;
use App\Acces\Entity\Equipement;
use App\Acces\Entity\EspaceAcces;
use App\Acces\Enum\SensEquipement;
use App\Acces\Enum\TypeEquipement;
use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Tests\Acces\AccesApiTestCase;
use App\Vente\Service\GenerateurCodeSupport;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Validation en ligne d'un passage côté borne (POST /terminal/passages, US-TERM-02, CA-2/3/4, §4.1/4.2
 * spec — portée avant moteur).
 */
final class TerminalPassageTest extends AccesApiTestCase
{
    public function testCa2PassageValideMoinsDUneSecondeAvecMessageEtAffichage(): void
    {
        $entete = $this->terminalEntete();
        $client = static::createClient();

        $debut = microtime(true);
        $reponse = $client->request('POST', '/api/terminal/passages', $entete + [
            'json' => [
                'equipementId' => $this->idEquipement(),
                'identifiantSupport' => AccesFixtures::SUPPORT_IDENTIFIANT,
                'cleIdempotence' => (string) Uuid::v4(),
            ],
        ]);
        $duree = microtime(true) - $debut;

        self::assertSame(200, $reponse->getStatusCode(), (string) $reponse->getContent(false));
        $corps = $reponse->toArray();
        self::assertSame('valide', $corps['resultat']);
        self::assertNull($corps['codeMotif']);
        self::assertSame('BONNE_SEANCE', $corps['message']['codeMessage']);
        self::assertNotEmpty($corps['message']['libelle']);
        self::assertNotEmpty($corps['horodatageServeur']);
        self::assertIsArray($corps['affichage']);
        self::assertSame(11, $corps['affichage']['compostagesRestants']);
        self::assertLessThan(1.0, $duree, 'Réponse attendue en moins de 1 s (RG-ACC-01).');
    }

    public function testCa3SignatureInvalideMessageGeneriqueSansAffichage(): void
    {
        [$equipement, $identifiant] = $this->creerSupportAppaireAvecCodeSigne();
        $dernier = substr($identifiant, -1);
        $identifiantForge = substr($identifiant, 0, -1) . ($dernier === 'A' ? 'B' : 'A');

        $reponse = static::createClient()->request('POST', '/api/terminal/passages', $this->terminalEntete() + [
            'json' => [
                'equipementId' => (string) $equipement->getId(),
                'identifiantSupport' => $identifiantForge,
                'cleIdempotence' => (string) Uuid::v4(),
            ],
        ]);

        self::assertSame(200, $reponse->getStatusCode(), (string) $reponse->getContent(false));
        $corps = $reponse->toArray();
        self::assertSame('refuse', $corps['resultat']);
        self::assertSame('signature_invalide', $corps['codeMotif']);
        self::assertSame('CODE_INVALIDE', $corps['message']['codeMessage']);
        self::assertNull($corps['affichage'], 'Pas de fuite d\'info sur un refus signature invalide (§4.2 spec).');
    }

    public function testCa4CarteEpuiseeCodeMessageCarteEpuiseeSansDecompte(): void
    {
        $entete = $this->terminalEntete();
        $client = static::createClient();
        $equipementId = $this->idEquipement();
        $base = new \DateTimeImmutable('2026-06-01T08:00:00+00:00');

        for ($i = 0; $i < 12; ++$i) {
            $reponseBoucle = $client->request('POST', '/api/terminal/passages', $entete + [
                'json' => [
                    'equipementId' => $equipementId,
                    'identifiantSupport' => AccesFixtures::SUPPORT_IDENTIFIANT,
                    'horodatageBorne' => $base->modify(sprintf('+%d seconds', $i * 301))->format(DATE_ATOM),
                    'cleIdempotence' => (string) Uuid::v4(),
                ],
            ]);
            self::assertSame('valide', $reponseBoucle->toArray()['resultat'], sprintf('Passage %d attendu valide.', $i));
        }

        $droit = $this->entite(DroitAcces::class, []);
        self::assertSame(0, $droit->getCreditRestant());

        $reponse = $client->request('POST', '/api/terminal/passages', $entete + [
            'json' => [
                'equipementId' => $equipementId,
                'identifiantSupport' => AccesFixtures::SUPPORT_IDENTIFIANT,
                'horodatageBorne' => $base->modify(sprintf('+%d seconds', 12 * 301))->format(DATE_ATOM),
                'cleIdempotence' => (string) Uuid::v4(),
            ],
        ]);
        $corps = $reponse->toArray();
        self::assertSame('refuse', $corps['resultat']);
        self::assertSame('credit_epuise', $corps['codeMotif']);
        self::assertSame('CARTE_EPUISEE', $corps['message']['codeMessage']);

        $droitApres = $this->entite(DroitAcces::class, []);
        self::assertSame(0, $droitApres->getCreditRestant(), 'Aucun décompte supplémentaire (CA-4).');
    }

    public function testEquipementHorsPorteeRefus403AvantMoteurEtAucunPassageCree(): void
    {
        [$equipementAutreItbox] = $this->creerEquipementAutreItboxRef();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $avant = (int) $em->getRepository(\App\Acces\Entity\Passage::class)->createQueryBuilder('p')->select('COUNT(p.id)')->getQuery()->getSingleScalarResult();

        $reponse = static::createClient()->request('POST', '/api/terminal/passages', $this->terminalEntete() + [
            'json' => [
                'equipementId' => (string) $equipementAutreItbox->getId(),
                'identifiantSupport' => AccesFixtures::SUPPORT_IDENTIFIANT,
                'cleIdempotence' => (string) Uuid::v4(),
            ],
        ]);
        self::assertSame(403, $reponse->getStatusCode(), (string) $reponse->getContent(false));

        $em->clear();
        $apres = (int) $em->getRepository(\App\Acces\Entity\Passage::class)->createQueryBuilder('p')->select('COUNT(p.id)')->getQuery()->getSingleScalarResult();
        self::assertSame($avant, $apres, 'Aucune trace de passage créée pour un équipement hors portée.');
    }

    public function testCleIdempotenceAbsenteRefuse422(): void
    {
        $reponse = static::createClient()->request('POST', '/api/terminal/passages', $this->terminalEntete() + [
            'json' => [
                'equipementId' => $this->idEquipement(),
                'identifiantSupport' => AccesFixtures::SUPPORT_IDENTIFIANT,
            ],
        ]);

        self::assertSame(422, $reponse->getStatusCode(), (string) $reponse->getContent(false));
    }

    public function testMemeCleIdempotenceUnSeulPassageEnregistre(): void
    {
        $entete = $this->terminalEntete();
        $client = static::createClient();
        $cle = (string) Uuid::v4();
        $corps = [
            'equipementId' => $this->idEquipement(),
            'identifiantSupport' => AccesFixtures::SUPPORT_IDENTIFIANT,
            'cleIdempotence' => $cle,
        ];

        $premiere = $client->request('POST', '/api/terminal/passages', $entete + ['json' => $corps]);
        self::assertSame(200, $premiere->getStatusCode(), (string) $premiere->getContent(false));
        $corpsPremiere = $premiere->toArray();
        self::assertSame('valide', $corpsPremiere['resultat']);

        // Retransmission réseau (même cleIdempotence) : rejoue la même réponse, aucun second décompte.
        $seconde = $client->request('POST', '/api/terminal/passages', $entete + ['json' => $corps]);
        self::assertSame(200, $seconde->getStatusCode(), (string) $seconde->getContent(false));
        self::assertSame($corpsPremiere['resultat'], $seconde->toArray()['resultat']);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $nb = (int) $em->getRepository(\App\Acces\Entity\Passage::class)->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->andWhere('p.cleIdempotence = :cle')->setParameter('cle', $cle, 'uuid')
            ->getQuery()->getSingleScalarResult();
        self::assertSame(1, $nb, 'Un seul Passage enregistré malgré la retransmission (idempotence).');

        $droit = $this->entite(DroitAcces::class, []);
        self::assertSame(11, $droit->getCreditRestant(), 'Un seul décompte de crédit malgré la retransmission.');
    }

    /** @return array{0: Equipement, 1: string} */
    private function creerSupportAppaireAvecCodeSigne(): array
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        /** @var GenerateurCodeSupport $generateur */
        $generateur = static::getContainer()->get(GenerateurCodeSupport::class);

        $etab = $this->entite(Etablissement::class, ['nom' => SocleFixtures::ETAB_A_NOM]);
        $equipement = $this->entite(Equipement::class, ['libelle' => AccesFixtures::EQUIPEMENT_LIBELLE]);

        $droit = new DroitAcces();
        $droit->setSourceType(\App\Acces\Enum\TypeDroitAcces::Billet)
            ->setStatutProjection(\App\Acces\Enum\StatutProjectionDroit::Valide)
            ->setEtablissement($etab);
        $em->persist($droit);

        $identifiant = $generateur->genererPourType(\App\Vente\Enum\TypeSupport::Qr);

        $support = new \App\Acces\Entity\Support();
        $support->setIdentifiant($identifiant)->setType(\App\Acces\Enum\TypeSupport::Qr)->setEtablissement($etab);
        $em->persist($support);

        $appairage = new \App\Acces\Entity\Appairage();
        $appairage->setSupport($support)->setDroit($droit)->setMode(\App\Acces\Enum\ModeAppairage::Caisse)->setActif(true)->setEtablissement($etab);
        $em->persist($appairage);

        $em->flush();

        return [$equipement, $identifiant];
    }

    /** @return array{0: Equipement} un équipement d'un contrôleur avec un itboxRef distinct de celui du Terminal démo */
    private function creerEquipementAutreItboxRef(): array
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $espace = $this->entite(EspaceAcces::class, ['libelle' => AccesFixtures::ESPACE_LIBELLE]);
        $etab = $this->entite(Etablissement::class, ['nom' => SocleFixtures::ETAB_A_NOM]);

        $controleur = new Controleur();
        $controleur->setLibelle('Contrôleur hors portée')->setEspace($espace)->setItboxRef('ITBOX-AUTRE');
        $em->persist($controleur);

        $equipement = new Equipement();
        $equipement->setLibelle('Tourniquet hors portée')->setControleur($controleur)->setType(TypeEquipement::Tourniquet)->setSens(SensEquipement::Entree);
        $em->persist($equipement);

        $em->flush();

        return [$equipement];
    }
}
