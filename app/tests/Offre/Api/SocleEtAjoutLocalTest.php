<?php

declare(strict_types=1);

namespace App\Tests\Offre\Api;

use App\DataFixtures\SocleFixtures;
use App\Offre\DataFixtures\OffreFixtures;
use App\Offre\Entity\TypeTarif;
use App\Organisation\Entity\Etablissement;
use App\Platform\Scoping\ReferenceScope;
use App\Tests\Offre\OffreApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * D51 — **le socle partagé plus l'ajout local**, et le filtre naïf qui vide le catalogue.
 *
 * Maxime a demandé deux choses qui n'en font qu'une : *« un seul produit de créé, et derrière que ce
 * soit juste la tarification qui change »*, et *« le paramétrage entièrement modifiable par
 * l'utilisateur »*. Un exploitant doit pouvoir ajouter son propre type de tarif **sans qu'on lui livre
 * une version**, et sans que son ajout apparaisse chez le voisin.
 *
 * **Le premier test de ce fichier n'exerce pas la fonctionnalité : il montre ce qu'elle coûte si on
 * l'écrit naïvement.** `etablissement = :courant` ne rend pas « un peu moins de lignes » — il fait
 * disparaître **tout le socle**, donc le tarif sur lequel les prix sont posés, donc les prix. Le
 * caissier n'a plus rien à vendre. Ce n'est pas une liste incomplète, c'est un catalogue vide au
 * guichet.
 */
final class SocleEtAjoutLocalTest extends OffreApiTestCase
{
    private const LOCAL_A = 'Tarif municipal (A)';
    private const LOCAL_B = 'Tarif comité d\'entreprise (B)';

    /**
     * **Le test qui justifie tout le reste.** Le filtre naïf ne laisse **aucun** tarif du socle.
     *
     * Il ne se contente pas de compter : il vérifie que `TARIF_PLEIN` — celui sur lequel les grilles
     * tarifaires des fixtures posent les prix — a disparu. Un test qui constaterait « il manque des
     * lignes » se lirait comme un réglage à ajuster ; celui-ci dit que la vente s'arrête.
     */
    public function testLeFiltreNaifFaitDisparaitreToutLeSocle(): void
    {
        $this->poserLesDeuxAjoutsLocaux();
        $em = $this->em();
        $etabA = $this->entite(Etablissement::class, ['nom' => SocleFixtures::ETAB_A_NOM]);

        /** @var list<TypeTarif> $naif */
        $naif = $em->getRepository(TypeTarif::class)->createQueryBuilder('t')
            // ⚠ LE FILTRE QU IL NE FAUT PAS ECRIRE. Il est ici pour etre execute, pas pour etre imite.
            ->andWhere('IDENTITY(t.etablissement) = :courant')
            ->setParameter('courant', $etabA->getId(), 'uuid')
            ->getQuery()
            ->getResult();

        $noms = array_map(static fn (TypeTarif $t): string => $t->getNom(), $naif);

        self::assertNotContains(
            OffreFixtures::TARIF_PLEIN,
            $noms,
            'Le tarif de base a disparu : les grilles tarifaires ne resolvent plus aucun prix, et la caisse ne peut plus rien vendre.',
        );
        self::assertSame([self::LOCAL_A], $noms, 'Le filtre naif ne laisse que l ajout local — le socle entier est perdu.');

        // Et le compte du socle est bien non nul : la disparition vient du filtre, pas d une base vide.
        $socle = $em->getRepository(TypeTarif::class)->count(['portee' => ReferenceScope::Base]);
        self::assertGreaterThan(0, $socle);
    }

    /** La lecture réelle : le socle **plus** ses ajouts, jamais ceux d'un autre. */
    public function testUneLectureVoitLeSocleEtSesAjoutsSeulement(): void
    {
        $this->poserLesDeuxAjoutsLocaux();
        [$client, $entete] = $this->connecteSurA();

        $noms = array_column($client->request('GET', '/api/type_tarifs', $entete)->toArray()['member'], 'nom');
        self::assertResponseIsSuccessful();

        self::assertContains(OffreFixtures::TARIF_PLEIN, $noms, 'Le socle reste lisible : c est tout l interet d une plateforme.');
        self::assertContains(self::LOCAL_A, $noms);
        self::assertNotContains(self::LOCAL_B, $noms, 'L ajout d un autre etablissement ne doit jamais apparaitre.');
    }

    /** Un ajout naît **local**, rattaché à l'établissement actif — sans que l'appelant ait à le dire. */
    public function testUnAjoutNaitLocalEtRattache(): void
    {
        [$client, $entete] = $this->connecteSurA();

        $cree = $client->request('POST', '/api/type_tarifs', $entete + [
            'json' => ['nom' => 'Tarif scolaire (ajout local)'],
        ])->toArray();
        self::assertResponseStatusCodeSame(201);

        self::assertSame(ReferenceScope::Local->value, $cree['portee'] ?? null);
        self::assertNotNull($cree['etablissement'] ?? null, 'Un ajout local sans etablissement ne serait visible de personne.');
    }

    /**
     * **Modifier une ligne du socle est refusé**, et en 404.
     *
     * Une table partagée que n'importe quel établissement peut écrire est un trou transfrontière : A
     * renomme un type de tarif, **et le tarif de B change** — silencieusement, sans qu'aucune requête
     * de A ne mentionne B (D41).
     */
    public function testModifierLeSocleDepuisUnEtablissementEstRefuse(): void
    {
        [$client, $entete] = $this->connecteSurA();
        $socle = $this->entite(TypeTarif::class, ['nom' => OffreFixtures::TARIF_PLEIN]);

        $client->request('PATCH', '/api/type_tarifs/' . $socle->getId(), $this->fusion($entete, [
            'json' => ['nom' => 'Plein tarif (renomme par A)'],
        ]));
        self::assertResponseStatusCodeSame(404);

        $this->em()->clear();
        self::assertSame(
            OffreFixtures::TARIF_PLEIN,
            $this->entite(TypeTarif::class, ['nom' => OffreFixtures::TARIF_PLEIN])->getNom(),
            'Le socle n a pas bouge.',
        );
    }

    /** Se déclarer local dans le corps de la requête ne donne pas le droit de modifier le socle. */
    public function testOnNePeutPasSeDeclarerLocalPourModifierLeSocle(): void
    {
        [$client, $entete] = $this->connecteSurA();
        $socle = $this->entite(TypeTarif::class, ['nom' => OffreFixtures::TARIF_PLEIN]);

        $client->request('PATCH', '/api/type_tarifs/' . $socle->getId(), $this->fusion($entete, [
            'json' => ['nom' => 'Detourne', 'portee' => ReferenceScope::Local->value],
        ]));
        self::assertResponseStatusCodeSame(404, 'Le controle porte sur l etat en base, pas sur le corps recu.');
    }

    /**
     * **Une saison n a pas de socle** : elle appartient a un etablissement, et a lui seul (D51).
     *
     * Ce n est pas le meme regime que les types de tarif. Une saison est decidee par l exploitant ; une
     * tranche de quotient familial est fixee par la commune ou la CAF, et un tarif calcule sur la
     * mauvaise grille est une erreur de facturation OPPOSABLE. Il n y a donc rien a partager.
     */
    public function testUneSaisonNAppartientQuASonEtablissement(): void
    {
        $em = $this->em();
        $etabB = $this->entite(Etablissement::class, ['nom' => SocleFixtures::ETAB_B_NOM]);
        $saisonB = (new \App\Offre\Entity\Saison())
            ->setNom('Saison 2026 (B)')
            ->setDateDebut(new \DateTimeImmutable('2026-01-01'))
            ->setDateFin(new \DateTimeImmutable('2026-12-31'));
        $saisonB->setEtablissement($etabB);
        $em->persist($saisonB);
        $em->flush();
        $em->clear();

        [$client, $entete] = $this->connecteSurA();
        $noms = array_column($client->request('GET', '/api/saisons', $entete)->toArray()['member'], 'nom');
        self::assertResponseIsSuccessful();

        self::assertContains(OffreFixtures::SAISON, $noms, 'La saison de A reste lisible depuis A.');
        self::assertNotContains('Saison 2026 (B)', $noms, 'Une saison de B ne doit jamais apparaitre chez A.');
    }

    /**
     * **Une ligne orpheline n est visible de personne** — c est ce que la migration produit
     * deliberement sur l existant.
     *
     * Une migration ne fabrique jamais de donnee metier : rattacher au hasard aurait produit des
     * tarifs calcules sur la saison d un autre etablissement. Ce qui manque reste visiblement
     * manquant, parce qu une donnee absente se remarque et qu une donnee fausse ne se voit pas.
     */
    public function testUneSaisonOrphelineNEstVisibleDePersonne(): void
    {
        $em = $this->em();
        $orpheline = (new \App\Offre\Entity\Saison())
            ->setNom('Saison heritee (sans proprietaire)')
            ->setDateDebut(new \DateTimeImmutable('2020-01-01'))
            ->setDateFin(new \DateTimeImmutable('2020-12-31'));
        $em->persist($orpheline);
        $em->flush();
        $em->clear();

        [$client, $entete] = $this->connecteSurA();
        $noms = array_column($client->request('GET', '/api/saisons', $entete)->toArray()['member'], 'nom');

        self::assertNotContains('Saison heritee (sans proprietaire)', $noms);
    }

    /**
     * Fusionne l entete et les options d un PATCH **sans perdre les en-tetes**.
     *
     * `$entete + $options` garde la valeur de gauche pour toute cle commune : le `Content-Type` du
     * merge-patch etait donc silencieusement ecrase par l en-tete d etablissement, et l API repondait
     * 415 au lieu du 404 attendu. Le test echouait pour une raison qui n avait rien a voir avec ce
     * qu il verifie — le pire genre de rouge.
     *
     * @param array<string, mixed> $entete
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function fusion(array $entete, array $options): array
    {
        $options['headers'] = ($entete['headers'] ?? []) + ['Content-Type' => 'application/merge-patch+json'];

        return $options + $entete;
    }

    /**
     * Le socle de test renvoie `[client, token, idEtablissement]` et non un entete tout fait : on le
     * compose ici plutot que trois fois dans le fichier.
     *
     * @return array{0: \ApiPlatform\Symfony\Bundle\Test\Client, 1: array<string, mixed>}
     */
    private function connecteSurA(): array
    {
        [$client, $token, $idA] = $this->adminSurA();

        return [$client, ['auth_bearer' => $token, 'headers' => [\App\Securite\Service\ContexteEtablissement::HEADER => $idA]]];
    }

    /** Pose un ajout local sur A et un sur B, en base : c'est le décor des deux premiers tests. */
    private function poserLesDeuxAjoutsLocaux(): void
    {
        $em = $this->em();
        foreach ([[SocleFixtures::ETAB_A_NOM, self::LOCAL_A], [SocleFixtures::ETAB_B_NOM, self::LOCAL_B]] as [$nomEtab, $nomTarif]) {
            $etablissement = $this->entite(Etablissement::class, ['nom' => $nomEtab]);
            $tarif = (new TypeTarif())->setNom($nomTarif);
            $tarif->rattacherA($etablissement);
            $em->persist($tarif);
        }
        $em->flush();
        $em->clear();
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }
}
