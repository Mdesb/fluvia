<?php

declare(strict_types=1);

namespace App\Tests\Crm\Api;

use App\Caisse\Entity\PointDeVente;
use App\Crm\DataFixtures\CrmFixtures;
use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Tests\Crm\CrmApiTestCase;
use App\Vente\Enum\TypeOperationScellee;
use App\Vente\Nf525\OperationAScellerDto;
use App\Vente\Nf525\ScellementHandler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * LA CHAINE NF525 EST-ELLE LISIBLE D'UN AUTRE CLIENT ?
 *
 * ⚠ CE QUE `nf525:read` EXPOSE, ET POURQUOI C'EST PIRE QUE LES TROIS FUITES DE CE MATIN.
 *
 * Le groupe de lecture rend `payloadCanonique` : l'instantane COMPLET de chaque operation scellee,
 * pas une metadonnee. Montants, moyens de paiement, reference de la cible. La collection est gardee
 * par `caisse.lire` — la permission la plus repandue du produit.
 *
 * `OperationScellee` manque a l'enumeration de `PerimetreVenteExtension`, qui cloisonne pourtant ses
 * neuf voisines : `Vente`, `ClotureZ`, `Caisse`, `MouvementCaisse`, `SessionCaisse`, `Avoir`,
 * `PointDeVente`, `CardRejection`, `DailyClosure`. Aucune autre extension de collection ne la nomme.
 *
 * ⚠ ET LE MEME RISQUE A DEJA ETE FERME SUR L'AUTRE PORTE DE CETTE RESSOURCE.
 * `VerifierChaineProcessor:53` porte ceci, en toutes lettres :
 *
 *     « sans quoi un agent `caisse.lire` sur A pouvait sonder l'integrite de la chaine NF525
 *       (signal de conformite fiscale) d'un point de vente d'un autre etablissement »
 *
 * La porte fermee ne rendait qu'un SIGNAL. Celle restee ouverte rend le CONTENU.
 *
 * ── ⚠ L'ORDRE DES MESURES EST DELIBERE, ET IL COMPTE PLUS QUE D'HABITUDE ────────────────────────
 *
 * Le premier test etablit que le verificateur d'integrite fonctionne DANS LES DEUX SENS — il dit oui
 * sur une chaine intacte et non sur une chaine cassee — AVANT que quoi que ce soit ne touche au
 * cloisonnement. Sans cette mesure prealable, un verificateur devenu aveugle apres correctif serait
 * indiscernable d'un verificateur qui l'etait deja.
 *
 * C'est le pire defaut possible ici : un controle d'integrite qu'on montre a un expert-comptable et
 * qui rendrait « chaine intacte » sans plus rien mesurer. Le second pire est son symetrique — un
 * cloisonnement qui ne montrerait qu'une partie de la chaine ferait crier « trou de sequence » sur
 * une chaine parfaitement saine, et accuserait le produit a tort.
 *
 * ── ⚠ CE QUE CE TEST RECOUVRE, ET CE QU'IL AJOUTE ───────────────────────────────────────────────
 *
 * `CloisonnementResiduelTest::testUneOperationScelleeNeSeLitPasDUnEtablissementALAutre` (claude-A,
 * db20a77) mesure la MEME fuite, et le correctif qui la ferme est le sien : j'avais ecrit le mien
 * en parallele, son alias de jointure est mieux choisi, et j'ai retire le mien plutot que d'en
 * garder deux. Nos deux mesures sont tombees d'accord — ce test, ecrit contre mon implementation,
 * passe sans modification contre la sienne.
 *
 * Ce que celui-ci ajoute et que l'autre n'a pas :
 *
 *   — LE VERIFICATEUR D'INTEGRITE, MESURE DANS LES DEUX SENS, AVANT ET APRES LE CLOISONNEMENT.
 *     Cloisonner une chaine fiscale peut aveugler son verificateur, et personne ne le verrait :
 *     un verificateur aveugle rend « chaine intacte » exactement comme un verificateur qui marche ;
 *   — UNE CHAINE REELLEMENT SCELLEE, construite par `ScellementHandler`, chaque maillon chaine sur
 *     l'empreinte du precedent. L'autre pose un temoin dans une ligne fabriquee — plus economique,
 *     suffisant pour ce qu'il mesure, mais incapable de faire dire quoi que ce soit au verificateur ;
 *   — LE TEMOIN NEGATIF SUR `caisse.lire`, qui empeche la mesure de passer un jour parce que la
 *     ressource se serait fermee a tout le monde.
 *
 * Deux tests sur une meme fuite coutent ; le sien seul laisserait le verificateur sans preuve.
 */
final class CloisonnementChaineScelleeTest extends CrmApiTestCase
{
    /**
     * LE VERIFICATEUR SAIT DIRE OUI, ET IL SAIT DIRE NON.
     *
     * ⚠ CE TEST NE MESURE AUCUN CLOISONNEMENT, ET C'EST SA RAISON D'ETRE. Il etablit la ligne de
     * base : sur ce depot, aujourd'hui, une chaine intacte rend 200 et une chaine cassee rend 409.
     * Tout ce qui suit s'interprete contre ca.
     */
    public function testLeVerificateurDitOuiSurUneChaineIntacteEtNonSurUneChaineCassee(): void
    {
        [$client, $entete] = $this->adminSurA();

        $pdv = $this->pointDeVenteDeA();
        $this->scelle($pdv, 'V-001');
        $this->scelle($pdv, 'V-002');

        $client->request('POST', '/api/nf525/verifier-chaine', [
            'auth_bearer' => $entete['auth_bearer'],
            'headers' => $entete['headers'] + ['Content-Type' => 'application/ld+json'],
            'json' => ['pointDeVente' => (string) $pdv->getId()],
        ]);

        self::assertResponseStatusCodeSame(200, 'une chaine intacte doit etre declaree intacte');

        // ⚠ ON CASSE PAR SQL, PAS PAR L'ORM. L'entite est immuable : un `setEmpreinte()` suivi d'un
        // flush leve `OperationInalterableException` — c'est le garde-fou applicatif, et il n'a rien
        // a voir avec ce qu'on mesure ici. Une falsification reelle passe sous l'ORM ; la sonde doit
        // faire pareil, sinon elle mesure la protection de l'ORM et non celle du verificateur.
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->getConnection()->executeStatement(
            "UPDATE nf525_operation_scellee SET empreinte = 'falsifiee' WHERE numero_sequence = 2"
        );
        $em->clear();

        $client->request('POST', '/api/nf525/verifier-chaine', [
            'auth_bearer' => $entete['auth_bearer'],
            'headers' => $entete['headers'] + ['Content-Type' => 'application/ld+json'],
            'json' => ['pointDeVente' => (string) $pdv->getId()],
        ]);

        self::assertResponseStatusCodeSame(
            409,
            'une empreinte falsifiee doit etre detectee : sans ce refus, le verificateur ne mesure '
            . 'rien et son « intacte » ne vaut rien',
        );
    }

    /**
     * LA MESURE : UNE OPERATION D'UN AUTRE CLIENT APPARAIT-ELLE DANS MA COLLECTION ?
     */
    public function testLesOperationsScelleesDunAutreClientNeSontPasListees(): void
    {
        [$client, $entete] = $this->adminSurA();

        $mienne = $this->scelle($this->pointDeVenteDeA(), 'V-MIENNE');
        $etrangere = $this->scelle($this->pointDeVenteDeC(), 'V-ETRANGERE');

        $client->request('GET', '/api/operation_scellees', $entete + ['query' => ['itemsPerPage' => 200]]);
        self::assertResponseIsSuccessful();

        $rendu = $client->getResponse()->toArray();
        $membres = $rendu['member'] ?? $rendu['hydra:member'] ?? [];
        $ids = array_map(static fn (array $o): string => (string) ($o['id'] ?? ''), $membres);

        // ⚠ CONTROLE POSITIF D'ABORD. Une collection vide passerait l'assertion suivante en prouvant
        // le contraire de ce qu'on veut : un cloisonnement qui cache tout n'est pas un cloisonnement,
        // c'est une panne — et sur une chaine fiscale, une panne qui se lit comme une conformite.
        self::assertContains(
            $mienne,
            $ids,
            'temoin : l\'operation de mon propre point de vente doit etre lisible',
        );

        self::assertNotContains(
            $etrangere,
            $ids,
            'la collection rend `payloadCanonique`, c\'est-a-dire le contenu scelle de chaque '
            . 'operation : celle d\'un autre client ne doit pas y figurer',
        );
    }

    /**
     * ⚠ LE TEMOIN NEGATIF : SANS LUI, LA MESURE PASSERAIT POUR LA MAUVAISE RAISON.
     *
     * Si la ressource se fermait a tout le monde, « l'operation etrangere n'apparait pas » resterait
     * vrai et ne dirait plus rien du cloisonnement. Ce test fixe l'autre bord : `caisse.lire` reste
     * ce qui ouvre la porte, et son absence la ferme. L'agent CRM ne porte que des permissions
     * `crm.*`.
     */
    public function testUnCompteSansCaisseLireEstRefuse(): void
    {
        [$client, $entete] = $this->agentSurA();

        $client->request('GET', '/api/operation_scellees', $entete);

        self::assertResponseStatusCodeSame(
            403,
            'un compte depourvu de `caisse.lire` ne doit pas lister les operations scellees',
        );
    }

    private function pointDeVenteDeA(): PointDeVente
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        $pdv = $em->getRepository(PointDeVente::class)->findOneBy(['libelle' => 'Guichet principal Piscine A']);
        self::assertInstanceOf(PointDeVente::class, $pdv, 'temoin : le point de vente des fixtures doit exister');

        return $pdv;
    }

    /**
     * Un point de vente chez un AUTRE client.
     *
     * `ETAB_C` appartient au second groupe des fixtures CRM, et seul `agentB` y est affecte —
     * l'administrateur ne l'est pas. C'est ce qui en fait un voisin et non un second site a soi :
     * l'administrateur du socle est affecte a la fois a `Piscine A` ET a `Patinoire B`, donc B
     * n'aurait rien mesure du tout.
     */
    private function pointDeVenteDeC(): PointDeVente
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        $etabC = $em->getRepository(Etablissement::class)->findOneBy(['nom' => CrmFixtures::ETAB_C_NOM]);
        self::assertInstanceOf(Etablissement::class, $etabC, 'temoin : l\'etablissement du second groupe doit exister');

        $pdv = $em->getRepository(PointDeVente::class)->findOneBy(['etablissement' => $etabC]);
        if (!$pdv instanceof PointDeVente) {
            $pdv = (new PointDeVente())
                ->setLibelle('Guichet ' . CrmFixtures::ETAB_C_NOM)
                ->setEtablissement($etabC)
                ->setMoyensAutorises(['especes']);
            $em->persist($pdv);
            $em->flush();
        }

        return $pdv;
    }

    /** Scelle une operation et rend son identifiant. */
    private function scelle(PointDeVente $pdv, string $reference): string
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        /** @var ScellementHandler $scellement */
        $scellement = static::getContainer()->get(ScellementHandler::class);

        // On passe par le scelleur plutot que de fabriquer l'entite a la main : c'est lui qui chaine
        // l'empreinte sur la precedente. Une chaine fabriquee a la main serait rejetee par le
        // verificateur, et ce refus se lirait comme un cloisonnement.
        $operation = $scellement->sceller(new OperationAScellerDto(
            $pdv,
            TypeOperationScellee::Vente,
            'Vente',
            Uuid::v4(),
            ['reference' => $reference, 'montant' => '10.00'],
        ));

        $em->flush();

        return (string) $operation->getId();
    }
}
