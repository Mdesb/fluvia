<?php

declare(strict_types=1);

namespace App\Tests\Securite\Api;

use App\Audit\Entity\EntreeAudit;
use App\Crm\DataFixtures\CrmFixtures;
use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Espace;
use App\Organisation\Entity\Etablissement;
use App\Organisation\Entity\Region;
use App\Caisse\Entity\PointDeVente;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Permission;
use App\Securite\Entity\Utilisateur;
use App\Vente\Entity\Vente;
use App\Reservation\Entity\Recurrence;
use App\Tests\Marketing\MarketingApiTestCase;
use App\Vente\Entity\CardRejection;
use App\Vente\Nf525\Entity\OperationScellee;

/**
 * CE QUE LE GARDE-FOU N°4 CACHAIT — vérifié une entité à la fois.
 *
 * Le garde-fou tenait « porte un champ `etablissement` » pour « est cloisonné ». Onze entités
 * passaient par là. Les corriger consiste à les nommer dans une extension — mais **nommer une
 * classe avec un mauvais chemin satisfait le garde-fou sans fermer la fuite**. C'est exactement le
 * piège qu'on vient de fermer, en plus petit.
 *
 * Ces tests couvrent les trois axes de rattachement ajoutés, plus les deux listes blanches
 * complétées. Chacun a été vu ÉCHOUER avant le correctif.
 *
 * > **Une fuite de cloisonnement ne produit pas d'erreur, elle produit des lignes en trop.**
 */
final class CloisonnementResiduelTest extends MarketingApiTestCase
{
    /**
     * **Le journal d'audit** — axe « référence libre » (colonne `uuid` sans clé étrangère).
     *
     * Le module Audit n'avait aucune extension. Un journal lisible d'un établissement à l'autre
     * raconte qui fait quoi chez le voisin, et à quelle heure.
     */
    public function testLeJournalDAuditNeTraversePasLesEtablissements(): void
    {
        $em = $this->em();
        $em->persist(
            (new EntreeAudit())
                ->setAuteur('agent.a@example.test')
                ->setAction('suppression_massive')
                ->setCibleType('Client')
                ->setEtablissement($this->etablissementA()->getId())
        );
        $em->flush();

        // Sans ce droit, l'appel repondrait 403 et le test passerait pour une raison qui n'a rien
        // a voir : on mesurerait l'absence de permission, pas le cloisonnement.
        $this->accorderALAgentB('securite', 'lire');

        $this->refuse('/api/entree_audits', 'suppression_massive');
    }

    /**
     * **Un espace** — axe « relation directe », le cas courant des cinq modules sans extension.
     */
    public function testUnEspaceNeSeLitPasDUnEtablissementALAutre(): void
    {
        $em = $this->em();
        $em->persist(
            (new Espace())
                ->setNom('Bassin nordique du groupe A')
                ->setType('bassin')
                ->setEtablissement($this->etablissementA())
        );
        $em->flush();

        $this->refuse('/api/espaces', 'Bassin nordique du groupe A');
    }

    /**
     * **Une région** — axe « groupe », le seul des trois qui ne passe pas par l'établissement.
     *
     * Une région n'appartient pas à un établissement : elle en contient. Son nom, et le nombre de
     * sites qu'elle porte, disent la structure d'un groupe concurrent.
     */
    public function testUneRegionNeSeLitPasDUnGroupeALAutre(): void
    {
        $em = $this->em();
        /** @var Etablissement $etabA */
        $etabA = $this->etablissementA();
        $em->persist(
            (new Region())
                ->setNom('Grand Ouest confidentiel')
                ->setGroupe($etabA->getRegion()?->getGroupe())
        );
        $em->flush();

        $this->refuse('/api/regions', 'Grand Ouest confidentiel');
    }

    /**
     * **Un rejet de carte** — liste blanche `PerimetreVenteExtension`, complétée.
     *
     * Sept classes y figuraient, celle-ci pas. Une liste blanche ne protège que ce qu'on a pensé à
     * y écrire, et son oubli ne se voit pas.
     */
    public function testUnRejetDeCarteNeSeLitPasDUnEtablissementALAutre(): void
    {
        $em = $this->em();
        $em->persist(
            (new CardRejection())
                ->setVente($this->venteSurA())
                ->setMoyenCode('cb_temoin_groupe_a')
                ->setMontantCentimes(4200)
                ->setEtablissement($this->etablissementA())
        );
        $em->flush();

        $this->accorderALAgentB('vente', 'lire');

        $this->refuse('/api/refus_cartes', 'cb_temoin_groupe_a');
    }

    /**
     * **Une récurrence** — liste blanche `PerimetreReservationExtension`, complétée.
     */
    public function testUneRecurrenceNeSeLitPasDUnEtablissementALAutre(): void
    {
        $em = $this->em();
        $em->persist(
            (new Recurrence())
                ->setEtablissement($this->etablissementA())
                ->setFinRecurrence(new \DateTimeImmutable('+1 year'))
                ->setJoursSemaine([1, 3, 5])
        );
        $em->flush();

        $this->accorderALAgentB('reservation', 'lire');

        $this->refuse('/api/reservation_recurrences', (string) $this->etablissementA()->getId());
    }

    /**
     * Le geste commun : l'agent du groupe B interroge, et ne doit pas trouver la trace posée sur A.
     *
     * On cherche un TÉMOIN choisi pour n'exister nulle part ailleurs. Compter les lignes ne
     * prouverait rien — une collection vide passe aussi bien quand le filtre marche que quand la
     * table est vide.
     */
    /**
     * **Le scellement NF525** — troisième oubli de la même liste blanche, et le plus grave.
     *
     * `PerimetreVenteExtension` a reçu `CardRejection` et `DailyClosure` le 28/08, avec la leçon
     * écrite juste au-dessus : *« une liste blanche ne protège que ce qu'on a pensé à y écrire, et
     * son oubli ne se voit pas — la collection rend simplement des lignes de plus »*.
     *
     * ⚠ `OperationScellee` porte `pointDeVente`, n'était nommée par aucune extension, et sort en
     * `GetCollection` derrière la seule permission `caisse.lire`. Ce qui fuyait n'est pas un
     * identifiant : `payloadCanonique` est **le contenu canonique de chaque transaction**, figé au
     * scellement.
     *
     * Le témoin est placé dans `empreinte` ET dans `payloadCanonique` : si un seul des deux
     * champs sortait du groupe de sérialisation un jour, le test continuerait de mesurer l'autre.
     */
    public function testUneOperationScelleeNeSeLitPasDUnEtablissementALAutre(): void
    {
        $em = $this->em();
        $vente = $this->venteSurA();

        $operation = (new OperationScellee())
            ->setPointDeVente($vente->getPointDeVente())
            ->setCibleType('vente')
            ->setCibleId($vente->getId())
            ->setNumeroSequence(999001)
            ->setEmpreinte('temoin_nf525_groupe_a')
            ->setSignature('signature-temoin')
            ->setPayloadCanonique(['temoin' => 'temoin_nf525_groupe_a']);
        $em->persist($operation);
        $em->flush();

        // Sans ce droit, le test mesurerait l'absence de permission, pas le cloisonnement.
        $this->accorderALAgentB('caisse', 'lire');

        $this->refuse('/api/operation_scellees', 'temoin_nf525_groupe_a');
    }

    private function refuse(string $url, string $temoin): void
    {
        [$clientB, $enteteB] = $this->agentSurGroupeB();
        $reponse = $clientB->request('GET', $url, $enteteB);

        // Le garde qui compte : une route inexistante rend 404, et « ne contient pas le témoin »
        // passerait pour une raison qui n'a rien à voir. C'est arrivé ici même le 28/08 sur
        // `/api/recurrences`, qui n'existe pas — le test était vert et ne mesurait rien.
        self::assertLessThan(
            400,
            $reponse->getStatusCode(),
            'L’appel doit aboutir : un 404 ou un 403 rendrait ce test vert sans rien prouver.',
        );
        self::assertStringNotContainsString($temoin, $reponse->getContent(false));
    }

    /**
     * Accorde une permission au rôle de l'agent du groupe B.
     *
     * Un test de cloisonnement qui passe faute de droit ne mesure pas le cloisonnement : il mesure
     * l'absence de droit. On donne donc le droit, et on exige que le périmètre tienne quand même.
     */
    private function accorderALAgentB(string $module, string $action): void
    {
        $em = $this->em();
        $permission = $em->getRepository(Permission::class)->findOneBy(['module' => $module, 'action' => $action])
            ?? (new Permission())->setModule($module)->setAction($action);
        $em->persist($permission);

        $agent = $em->getRepository(Utilisateur::class)->findOneBy(['email' => CrmFixtures::AGENT_B_EMAIL]);
        self::assertInstanceOf(Utilisateur::class, $agent);

        // La relation est portée par l'affectation, pas par l'utilisateur : on interroge donc dans
        // ce sens-là.
        $affectations = $em->getRepository(Affectation::class)->findBy(['utilisateur' => $agent]);
        self::assertNotEmpty($affectations, 'L’agent du groupe B doit avoir au moins une affectation.');

        foreach ($affectations as $affectation) {
            $affectation->getRole()?->addPermission($permission);
        }
        $em->flush();
    }

    /** Une vente minimale sur l'établissement A : le rejet de carte ne peut pas exister sans elle. */
    private function venteSurA(): Vente
    {
        $em = $this->em();
        $pdv = $em->getRepository(PointDeVente::class)->findOneBy(['libelle' => 'Caisse témoin'])
            ?? (new PointDeVente())
                ->setLibelle('Caisse témoin')
                ->setEtablissement($this->etablissementA())
                ->setMoyensAutorises(['especes']);
        $em->persist($pdv);

        $vente = (new Vente())
            ->setNumero('V-' . bin2hex(random_bytes(6)))
            ->setPointDeVente($pdv)
            ->setEtablissement($this->etablissementA());
        $em->persist($vente);
        $em->flush();

        return $vente;
    }

    /** Garde-fou du test lui-même : les fixtures doivent bien poser deux groupes distincts. */
    public function testLesDeuxGroupesExistentBien(): void
    {
        $em = $this->em();
        $etabA = $em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        $etabC = $em->getRepository(Etablissement::class)->findOneBy(['nom' => CrmFixtures::ETAB_C_NOM]);

        self::assertNotNull($etabA);
        self::assertNotNull($etabC);
        self::assertNotSame(
            $etabA?->getRegion()?->getGroupe()?->getId(),
            $etabC?->getRegion()?->getGroupe()?->getId(),
            'Sans deux groupes distincts, ces tests ne mesureraient rien.',
        );
    }
}
