<?php

declare(strict_types=1);

namespace App\Tests\Personnel\Api;

use App\Organisation\Entity\Etablissement;
use App\Organisation\Entity\Region;
use App\Personnel\DataFixtures\PersonnelFixtures;
use App\Personnel\Entity\Employe;
use App\Personnel\Entity\RattachementEmploye;
use App\Personnel\Enum\TypeContrat;
use App\Tests\Personnel\PersonnelApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Ce qui protège l'écriture d'un `RattachementEmploye` — et ce qui ne la protège pas.
 *
 * ── CE FICHIER EST NÉ D'UN CORRECTIF QUI NE CORRIGEAIT RIEN ─────────────────────────────────────
 *
 * `RattachementEmploye` expose `employe` ET `etablissement` en écriture et n'a aucun processeur. Un
 * audit en a conclu que « rien ne vérifie que l'employé désigné est dans le périmètre de l'appelant »,
 * et un `StaffAssignmentScopeProcessor` a été écrit pour le recouper avec
 * `PerimetreEmployeVerificateur`.
 *
 * **Mesuré le 08/09 : ce processeur ne changeait rien.** Suite lancée avec et sans lui : 5 tests,
 * 18 assertions, résultats identiques. Il a été supprimé plutôt que livré.
 *
 * La raison est structurelle, pas accidentelle. Le refus arrive **avant** tout processeur, à la
 * dénormalisation : API Platform résout `/api/employes/<uuid>` par le provider d'item, donc **à
 * travers l'extension de cloisonnement**. Un employé hors périmètre n'est pas résolvable, et la
 * requête meurt en **400 « Item not found »** sans jamais atteindre la couche d'écriture.
 *
 * Et le recoupement proposé était **strictement plus large** que ce filtre :
 *
 *   extension de lecture  : visible si AUCUN rattachement, ou rattachement sur l'établissement ACTIF
 *   PerimetreEmployeVerificateur : vrai   si AUCUN rattachement, ou rattachement sur un établissement
 *                                         où l'agent a UNE AFFECTATION QUELCONQUE
 *
 * La seconde condition contient la première. Tout employé résolvable passait donc le contrôle : il
 * n'avait aucun cas où mordre.
 *
 *   > Un contrôle placé après un filtre plus strict que lui ne refuse jamais rien — et ses tests de
 *   > refus passent tous, ce qui le fait paraître utile.
 *
 * Ce fichier fige donc ce qui protège **réellement**, pour qu'un changement de la résolution d'IRI
 * (un provider sur mesure, un `denormalizationContext` élargi) fasse tomber quelque chose.
 */
final class RattachementScopeTest extends PersonnelApiTestCase
{
    /**
     * L'employé est rattaché à un établissement tiers où le compte RH n'a aucune affectation : il
     * n'est donc pas résolvable, et la requête est refusée à la dénormalisation.
     *
     * ⚠ 400, PAS 404. C'est le code d'API Platform pour une IRI non résolvable, et il précède la
     * convention 404 du dépôt (audit du 06/09) parce qu'aucun code du projet n'est encore atteint. Il
     * reste indiscernable — « Item not found » ne distingue pas « n'existe pas » de « hors
     * périmètre » — donc il ne confirme rien à qui sonde. Le figer ici documente la divergence plutôt
     * que de la laisser surprendre.
     *
     * ⚠ On vérifie l'ABSENCE D'ÉCRITURE, pas seulement le code : un refus rendu après un `flush()`
     * réussi serait un test vert posé sur un défaut vivant.
     */
    public function testRattacherUnEmployeHorsPerimetreEstRefuseEtNEcritRien(): void
    {
        $idEmployeEtranger = $this->employeRattacheAUnEtablissementTiers();

        [$clientRh, $enteteRh] = $this->rhSurA();
        $clientRh->request('POST', '/api/rattachement_employes', $enteteRh + [
            'json' => [
                'employe' => '/api/employes/' . $idEmployeEtranger,
                'etablissement' => '/api/etablissements/' . $this->idEtablissementA(),
                'debut' => '2026-01-01',
            ],
        ]);

        self::assertResponseStatusCodeSame(
            400,
            'Un employé hors périmètre n\'est pas résolvable : le refus vient de la dénormalisation, '
            . 'à travers l\'extension de cloisonnement, avant toute couche d\'écriture.'
        );

        self::assertSame(
            1,
            $this->nombreDeRattachements($idEmployeEtranger),
            'Le refus doit précéder l\'écriture : l\'employé ne garde que son rattachement d\'origine.'
        );
    }

    /**
     * Même mécanisme sur l'autre champ écrivable : un établissement hors du périmètre de l'agent
     * n'est pas résolvable non plus.
     *
     * ⚠ Ce cas n'atteint donc PAS le décorateur global `EstablishmentScopeWriteGuard` (D41), qui rend
     * 404 : il est court-circuité en amont. Le décorateur reste utile aux entités dont la lecture est
     * plus large que l'écriture ; il n'est simplement pas ce qui protège ici.
     */
    public function testCiblerUnEtablissementHorsPerimetreEstRefuse(): void
    {
        [$clientRh, $enteteRh] = $this->rhSurA();

        $idEmploye = $clientRh->request('POST', '/api/employes', $enteteRh + [
            'json' => [
                'nom' => 'Cible', 'prenom' => 'Etrangere', 'poste' => 'Agent',
                'typeContrat' => 'cdi', 'dateEntree' => '2026-01-05',
            ],
        ])->toArray()['id'];

        $clientRh->request('POST', '/api/rattachement_employes', $enteteRh + [
            'json' => [
                'employe' => '/api/employes/' . $idEmploye,
                'etablissement' => '/api/etablissements/' . $this->idEtablissementTiers(),
                'debut' => '2026-01-05',
            ],
        ]);

        self::assertResponseStatusCodeSame(
            400,
            'Un établissement hors périmètre n\'est pas résolvable depuis le corps de la requête.'
        );
    }

    /**
     * ⚠ TÉMOIN — LE CAS QUE LE PRODUIT DOIT AUTORISER.
     *
     * Poser le PREMIER rattachement d'un employé orphelin. C'est le geste central de la fiche employé :
     * tout employé créé par l'écran naît sans rattachement, et sans ce geste il ne peut recevoir aucun
     * badge (RG-PERSO-09).
     *
     * Il est ici parce que c'est le seul cas qu'un durcissement casserait **sans qu'aucun test de
     * refus ne bronche**. Si quelqu'un aligne un jour l'écriture sur l'établissement actif, c'est ce
     * test qui doit tomber, pas les utilisateurs.
     */
    public function testPremierRattachementDUnOrphelinResteAutorise(): void
    {
        [$clientRh, $enteteRh] = $this->rhSurA();

        $idOrphelin = $clientRh->request('POST', '/api/employes', $enteteRh + [
            'json' => [
                'nom' => 'Sansite', 'prenom' => 'Test', 'poste' => 'Agent',
                'typeContrat' => 'cdi', 'dateEntree' => '2026-01-05',
            ],
        ])->toArray()['id'];

        $clientRh->request('POST', '/api/rattachement_employes', $enteteRh + [
            'json' => [
                'employe' => '/api/employes/' . $idOrphelin,
                'etablissement' => '/api/etablissements/' . $this->idEtablissementA(),
                'debut' => '2026-01-05',
            ],
        ]);

        self::assertResponseStatusCodeSame(
            201,
            'Le premier rattachement d\'un orphelin est le geste que la fiche employé existe pour '
            . 'livrer : le refuser fermerait le produit, pas une faille.'
        );
    }

    /**
     * ⚠ TÉMOIN — la seconde branche du filtre, celle qu'un employé déjà rattaché emprunte.
     *
     * Le premier rattachement passe par « aucun rattachement » ; le second par « rattaché sur
     * l'établissement actif ». Deux chemins distincts dans `restreindreViaEmploye`, et un seul est
     * couvert si l'on n'écrit que le premier.
     */
    public function testRattacherUnEmployeDeSonPerimetreResteAutorise(): void
    {
        [$clientRh, $enteteRh] = $this->rhSurA();

        $idEmploye = $clientRh->request('POST', '/api/employes', $enteteRh + [
            'json' => [
                'nom' => 'Deja', 'prenom' => 'Rattache', 'poste' => 'Agent',
                'typeContrat' => 'cdi', 'dateEntree' => '2026-01-05',
            ],
        ])->toArray()['id'];

        $clientRh->request('POST', '/api/rattachement_employes', $enteteRh + [
            'json' => [
                'employe' => '/api/employes/' . $idEmploye,
                'etablissement' => '/api/etablissements/' . $this->idEtablissementA(),
                'debut' => '2026-01-05',
            ],
        ]);
        self::assertResponseStatusCodeSame(201);

        $clientRh->request('POST', '/api/rattachement_employes', $enteteRh + [
            'json' => [
                'employe' => '/api/employes/' . $idEmploye,
                'etablissement' => '/api/etablissements/' . $this->idEtablissementB(),
                'debut' => '2026-02-01',
            ],
        ]);

        self::assertResponseStatusCodeSame(
            201,
            'Un employé déjà rattaché à un établissement du périmètre de l\'agent reste rattachable.'
        );
    }

    /**
     * ⚠⚠ CE TEST DOCUMENTE UNE LIMITE OUVERTE, ET IL EST VERT PARCE QU'ELLE EXISTE.
     *
     * Un employé **orphelin** est visible de tout détenteur de `personnel.gerer_employe` sur son
     * établissement actif (`PerimetrePersonnelExtension::orphelinReserveAuxGestionnaires`) — y compris
     * d'un exploitant qui n'a rien à voir avec celui qui l'a créé. `Employe` ne porte ni créateur, ni
     * horodatage, ni ancrage d'établissement : **aucune donnée ne sépare « mon orphelin » de « celui
     * d'un autre client ».**
     *
     * Ce test mesure la portée réelle du trou : l'orphelin étranger apparaît-il dans la COLLECTION,
     * ou faut-il déjà connaître son UUID ? La réponse change la gravité — une fuite qui se parcourt
     * n'est pas une fuite qui se devine.
     *
     * ⚠ Il n'affirme donc PAS que ce comportement est souhaitable. Il fige ce qui est, pour que la
     * décision de le fermer soit prise sciemment — et pour qu'elle fasse tomber ce test quand elle
     * viendra.
     */
    public function testPorteeReelleDeLaVisibiliteDesOrphelins(): void
    {
        $idOrphelinEtranger = $this->orphelinNonRattache();

        [$clientRh, $enteteRh] = $this->rhSurA();
        $reponse = $clientRh->request('GET', '/api/employes', $enteteRh)->toArray();
        $membres = $reponse['member'] ?? $reponse['hydra:member'];
        $ids = array_map(static fn (array $e): string => $e['id'], $membres);

        self::assertContains(
            $idOrphelinEtranger,
            $ids,
            'LIMITE CONNUE : un employé orphelin est listé par tout détenteur de '
            . 'personnel.gerer_employe, sans lien avec son créateur. Si cette assertion tombe, c\'est '
            . 'que la visibilité des orphelins a été restreinte — mettre à jour la spec, c\'est une '
            . 'bonne nouvelle.'
        );
    }

    /**
     * Un employé rattaché à un établissement où le compte RH n'a aucune affectation. Construit par
     * l'`EntityManager` : aucun compte du jeu de données n'a le droit de créer un établissement, et le
     * faire par l'API mesurerait ce droit-là plutôt que celui qu'on éprouve.
     */
    private function employeRattacheAUnEtablissementTiers(): string
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        $employe = $this->nouvelEmploye($em, 'Etranger');

        $em->persist(
            (new RattachementEmploye())
                ->setEmploye($employe)
                ->setEtablissement($this->etablissementTiers($em))
                ->setDebut(new \DateTimeImmutable('2026-01-01'))
        );

        $em->flush();

        return (string) $employe->getId();
    }

    private function orphelinNonRattache(): string
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $employe = $this->nouvelEmploye($em, 'OrphelinEtranger');
        $em->flush();

        return (string) $employe->getId();
    }

    private function nouvelEmploye(EntityManagerInterface $em, string $nom): Employe
    {
        $employe = (new Employe())
            ->setNom($nom)
            ->setPrenom('Test')
            ->setPoste('Agent')
            ->setTypeContrat(TypeContrat::Cdi)
            ->setDateEntree(new \DateTimeImmutable('2026-01-01'));
        $em->persist($employe);

        return $employe;
    }

    private function idEtablissementTiers(): string
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $etablissement = $this->etablissementTiers($em);
        $em->flush();

        return (string) $etablissement->getId();
    }

    private function etablissementTiers(EntityManagerInterface $em): Etablissement
    {
        $existant = $em->getRepository(Etablissement::class)->findOneBy(['nom' => 'Site Tiers Personnel']);
        if ($existant instanceof Etablissement) {
            return $existant;
        }

        $region = $em->getRepository(Region::class)->findOneBy(['nom' => PersonnelFixtures::REGION_NOM]);
        self::assertNotNull($region, 'La région du jeu de données Personnel est introuvable.');

        $etablissement = (new Etablissement())->setNom('Site Tiers Personnel');
        $etablissement->setRegion($region);
        $em->persist($etablissement);

        return $etablissement;
    }

    private function nombreDeRattachements(string $idEmploye): int
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $employe = $em->getRepository(Employe::class)->find($idEmploye);
        self::assertNotNull($employe, 'L\'employé du test est introuvable.');

        return \count($em->getRepository(RattachementEmploye::class)->findBy(['employe' => $employe]));
    }
}
