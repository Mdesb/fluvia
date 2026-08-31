<?php

declare(strict_types=1);

namespace App\Tests\Compta\Api;

use App\Compta\Entity\BordereauPayFiP;
use App\Compta\Entity\FactureB2G;
use App\Compta\Entity\ProfilExploitant;
use App\Compta\Entity\VenteImpayeeRegie;
use App\Caisse\Entity\PointDeVente;
use App\DataFixtures\SocleFixtures;
use App\Facturation\Entity\DestinataireFacturation;
use App\Facturation\Entity\Facture;
use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Utilisateur;
use App\Tests\Compta\ComptaApiTestCase;
use App\Vente\Entity\Vente;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * UN COMPTABLE VOIT-IL LES IMPAYES ET LES ENCAISSEMENTS D'UN AUTRE ETABLISSEMENT ?
 *
 * `VenteImpayeeRegie` et `BordereauPayFiP` n'etaient cloisonnees par aucun des quatre mecanismes du
 * depot. La seule barriere etait la permission `compta.lire` — que tous les comptables de tous les
 * etablissements portent.
 *
 * ⚠ AUCUNE FUITE N'AVAIT EU LIEU : les deux tables etaient vides. Ce qui a rendu la correction
 * urgente, c'est que les ecrans qui les REMPLISSENT venaient d'etre livres. Le defaut naissait avec
 * la premiere ligne ecrite, et un `motif` d'impaye est du texte libre — donc le nom d'un client, un
 * cheque sans provision, une contestation.
 *
 * ── ⚠ POURQUOI LE TEMOIN POSITIF PASSE EN PREMIER, ET CE QU'IL SEUL PEUT ATTRAPER ──────────────
 *
 * Un cloisonnement TROP LARGE est invisible a ses tests de refus : s'il ne rendait jamais rien —
 * sous-requete malformee, alias errone, parametre mal type — les deux assertions « je ne vois pas
 * l'autre » passeraient, et mieux qu'avant. Seul le cas qu'il doit AUTORISER le demasque.
 *
 * On etablit donc D'ABORD que l'etablissement voit SA propre ligne, et seulement ensuite qu'il ne
 * voit pas celle du voisin.
 */
final class CloisonnementImpayesEtPayFipTest extends ComptaApiTestCase
{
    public function testUnImpayeDeRegieNestVisibleQueDansSonEtablissement(): void
    {
        [$client, $entete] = $this->adminSurA();
        $vente = $this->creerVenteValidee($client, $entete);

        $client->request('POST', '/api/compta/ventes/' . $vente['id'] . '/marquer-impayee-regie', $entete + [
            'json' => ['motif' => 'Chèque rejeté — contrôle positif'],
        ]);
        self::assertResponseStatusCodeSame(201);
        $idPropre = $client->getResponse()->toArray()['id'];

        $idEtranger = $this->impayeSurEtablissementB();

        // ⚠ LA LIGNE ETRANGERE EXISTE-T-ELLE VRAIMENT ? Sans ce controle, « je ne la vois pas » se
        // confondrait avec « je n'ai pas reussi a la fabriquer » — et les deux donnent le meme vert.
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();
        self::assertNotNull(
            $em->getRepository(VenteImpayeeRegie::class)->find($idEtranger),
            'témoin : l’impayé de l’établissement B doit EXISTER en base, sinon son invisibilité ne prouve rien',
        );

        $client->request('GET', '/api/vente_impayee_regies', $entete);
        self::assertResponseIsSuccessful();
        $ids = $this->identifiants($client->getResponse()->toArray());

        // ── LE TEMOIN POSITIF, QUI SEUL ATTRAPE UN FILTRE TROP LARGE ───────────────────────────
        self::assertContains(
            $idPropre,
            $ids,
            'témoin : l’établissement doit voir SON propre impayé — sans quoi un filtre qui ne rend '
            . 'jamais rien ferait passer le refus mesuré juste après',
        );

        self::assertNotContains(
            $idEtranger,
            $ids,
            'l’impayé d’un autre établissement ne doit pas être lisible : son motif est du texte libre',
        );
    }

    public function testUnBordereauPayFipNestVisibleQueDansSonEtablissement(): void
    {
        [$client, $entete] = $this->adminSurA();
        $vente = $this->creerVenteValidee($client, $entete);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        $propre = new BordereauPayFiP();
        $propre->setVenteOrigine(Uuid::fromString($vente['id']));
        $propre->setReferenceTransaction('CTRL-POSITIF-A');
        $em->persist($propre);

        $etranger = new BordereauPayFiP();
        $etranger->setVenteOrigine($this->venteSurEtablissementB()->getId());
        $etranger->setReferenceTransaction('ETRANGER-B');
        $em->persist($etranger);
        $em->flush();

        $idPropre = (string) $propre->getId();
        $idEtranger = (string) $etranger->getId();

        $client->request('GET', '/api/bordereau_pay_fi_ps', $entete);
        self::assertResponseIsSuccessful();
        $ids = $this->identifiants($client->getResponse()->toArray());

        self::assertContains(
            $idPropre,
            $ids,
            'témoin : l’établissement doit voir SON propre encaissement PayFiP',
        );

        self::assertNotContains(
            $idEtranger,
            $ids,
            'l’encaissement d’un autre établissement ne doit pas être lisible',
        );
    }

    /**
     * ⚠ CELLE-CI SE CLOISONNE A L'ENVERS, ET C'EST LE SEUL SENS QUI EXISTE.
     *
     * `FactureB2G` ne porte aucune relation vers la facture : c'est `Facture::$factureB2G` qui
     * pointe vers elle. Le docblock de l'extension cherchait un chemin depuis `clientRef` — champ
     * qui vient du destinataire, avec un `Uuid::v4()` en repli, donc un identifiant qui ne designe
     * rien — et concluait a l'absence de chemin. Il etait de l'autre cote.
     */
    public function testUneFactureB2GNestVisibleQueDansSonEtablissement(): void
    {
        [$client, $entete] = $this->adminSurA();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        $etabA = $em->getRepository(Etablissement::class)->find($this->idEtablissement(SocleFixtures::ETAB_A_NOM));
        $etabB = $em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_B_NOM]);
        self::assertInstanceOf(Etablissement::class, $etabA, 'témoin : l’établissement A doit exister');
        self::assertInstanceOf(Etablissement::class, $etabB, 'témoin : l’établissement B doit exister');

        $propre = $this->b2gRattacheeA($em, $etabA);
        $etranger = $this->b2gRattacheeA($em, $etabB);
        $em->flush();

        $idPropre = (string) $propre->getId();
        $idEtranger = (string) $etranger->getId();

        $client->request('GET', '/api/facture_b2_gs', $entete);
        self::assertResponseIsSuccessful();
        $ids = $this->identifiants($client->getResponse()->toArray());

        self::assertContains(
            $idPropre,
            $ids,
            'témoin : l’établissement doit voir SA propre facture B2G — sans quoi une sous-requête '
            . 'à l’envers malformée ferait passer le refus mesuré juste après',
        );

        self::assertNotContains(
            $idEtranger,
            $ids,
            'la facture B2G d’un autre établissement ne doit pas être lisible',
        );
    }

    private function b2gRattacheeA(EntityManagerInterface $em, Etablissement $etablissement): FactureB2G
    {
        $b2g = new FactureB2G();
        $b2g->setClientRef(Uuid::v4());
        $em->persist($b2g);

        // ⚠ SANS LA FACTURE, LE BORDEREAU EST ORPHELIN — donc invisible de partout, et le test
        // passerait sans rien prouver. C'est la facture qui porte l'etablissement.
        //
        // Le profil exploitant est NOT NULL sur la facture, et `etablissement_principal_id` porte
        // une contrainte UNIQUE : il y a AU PLUS UN profil par etablissement. On reprend donc celui
        // qui existe, et on n'en cree un que la ou il manque — en creer un second sur A faisait
        // echouer l'insertion, pas le cloisonnement.
        $profil = $em->getRepository(ProfilExploitant::class)
            ->findOneBy(['etablissementPrincipal' => $etablissement]);
        if (!$profil instanceof ProfilExploitant) {
            $profil = new ProfilExploitant();
            $profil->setSiren(substr((string) random_int(100000000, 999999999), 0, 9));
            $profil->setEtablissementPrincipal($etablissement);
            $em->persist($profil);
        }

        // Le destinataire est NOT NULL et se persiste en cascade depuis la facture. Aucun de ses
        // champs n'est requis : ce test ne mesure pas la facturation, seulement le chemin qui mene
        // de la facture B2G a un etablissement.
        $destinataire = new DestinataireFacturation();

        $facture = new Facture();
        $facture->setEtablissement($etablissement);
        $facture->setProfilExploitant($profil);
        $facture->setDestinataire($destinataire);
        // NOT NULL egalement. Peu importe QUI : le cloisonnement passe par l'etablissement de la
        // facture, pas par son auteur.
        $facture->setCreePar($em->getRepository(Utilisateur::class)->find($this->idAdmin()));
        $facture->setFactureB2G($b2g);
        $em->persist($facture);

        return $b2g;
    }

    /**
     * ⚠ UNE VENTE SANS ETABLISSEMENT SERAIT UN FAUX VERT. Le cloisonnement s'appuie sur
     * `Vente::$etablissement` : une vente qui n'en porte aucun serait invisible partout, y compris
     * de B — le test passerait sans rien prouver.
     */
    private function venteSurEtablissementB(): Vente
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        $etabB = $em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_B_NOM]);
        self::assertInstanceOf(Etablissement::class, $etabB, 'témoin : l’établissement B doit exister');

        // ⚠ `point_de_vente_id` est NOT NULL en base alors que la propriete est nullable en PHP.
        // On cree donc un point de vente SUR B plutot que d'y coller celui de A : avec le point de
        // vente de A, le test pourrait passer pour une mauvaise raison le jour ou le cloisonnement
        // emprunterait ce chemin-la.
        $pdv = new PointDeVente();
        $pdv->setLibelle('Guichet B — cloisonnement');
        $pdv->setEtablissement($etabB);
        $em->persist($pdv);

        $vente = new Vente();
        $vente->setEtablissement($etabB);
        $vente->setPointDeVente($pdv);
        $vente->setNumero('B-CLOISONNEMENT-' . substr((string) $vente->getId(), 0, 8));

        $em->persist($vente);
        $em->flush();

        self::assertNotNull($vente->getEtablissement(), 'témoin : la vente étrangère doit porter B');

        return $vente;
    }

    private function impayeSurEtablissementB(): string
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        $marquage = new VenteImpayeeRegie();
        $marquage->setVenteOrigine($this->venteSurEtablissementB()->getId());
        $marquage->setMotif('Impayé de la patinoire B — ne doit pas fuiter');

        $em->persist($marquage);
        $em->flush();

        return (string) $marquage->getId();
    }

    /**
     * @param array<string, mixed> $corps
     *
     * @return list<string>
     */
    private function identifiants(array $corps): array
    {
        /** @var list<array<string, mixed>> $membres */
        $membres = $corps['member'] ?? $corps['hydra:member'] ?? [];

        return array_map(static fn (array $item): string => (string) $item['id'], $membres);
    }
}
