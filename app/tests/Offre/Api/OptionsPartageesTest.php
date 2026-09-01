<?php

declare(strict_types=1);

namespace App\Tests\Offre\Api;

use App\DataFixtures\SocleFixtures;
use App\OptionProduit\Entity\GroupeOption;
use App\OptionProduit\Entity\ValeurOption;
use App\OptionProduit\Enum\ImpactOptionType;
use App\OptionProduit\Enum\ModeSelectionOption;
use App\Organisation\Entity\Etablissement;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\Offre\OffreApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * LES GROUPES D'OPTIONS N'APPARTIENNENT QU'A LEUR EXPLOITANT.
 *
 * ⚠ CE TEST A ETE ECRIT DANS L'AUTRE SENS, ET C'EST SON HISTOIRE QUI LE JUSTIFIE.
 *
 * Le 31/08 il CONSTATAIT le partage : un groupe cree sur un etablissement etait visible depuis
 * n'importe quel autre. `Catalogue.jsx` les liste et `ProduitOptionsModal.jsx` les attache aux
 * produits — les options d'un exploitant apparaissaient donc dans le catalogue d'un concurrent, et
 * `ValeurOption` expose `impactType` et `impactValeur`, c'est-a-dire leur effet sur le prix.
 *
 * ── TROISIEME NATURE DE DEFAUT, ET LA SEULE QU'AUCUN CRITERE N'A TROUVEE ────────────────────────
 *
 *     oubli de liste          une ligne manque a une enumeration     OperationScellee
 *     chemin trop long        la carte ne decrit qu'un saut          LettrageEcriture
 *     rattachement INVERSE    l'entite est atteinte, elle n'atteint  GroupeOption
 *                             rien elle-meme
 *
 * `GroupeOption` ne portait AUCUNE relation sortante. Une extension cloisonne en joignant ce que
 * l'entite designe ; elle ne peut rien pour ce qui ne designe rien. D'ou une colonne, une migration
 * et un rattachement des lignes existantes — pas une entree de carte.
 *
 * ⚠ POURQUOI CE TEST NE MESURE PAS DEUX CHOSES MAIS TROIS. Le cloisonnement seul ne suffit pas :
 * si le POST n'estampillait pas l'etablissement, chaque groupe cree serait NUL, donc invisible de
 * tous — un cloisonnement parfait qui casserait la creation. Le troisieme cas mesure donc que
 * l'ecran peut encore creer, et que ce qu'il cree lui revient.
 */
final class OptionsPartageesTest extends OffreApiTestCase
{
    public function testUnGroupeDOptionsNestVisibleQueDeSonEtablissement(): void
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        $etabA = $em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        self::assertInstanceOf(Etablissement::class, $etabA);

        $groupe = (new GroupeOption())
            ->setLibelle('Supplement casque de A')
            ->setModeSelection(ModeSelectionOption::Unique);
        $groupe->setEtablissement($etabA);
        $em->persist($groupe);
        $em->flush();
        $id = (string) $groupe->getId();

        $client = static::createClient();
        $token = $this->jeton($client, SocleFixtures::ADMIN_EMAIL, SocleFixtures::ADMIN_MDP);

        // ⚠ CONTROLE POSITIF D'ABORD : visible chez lui. Sans lui, l'assertion suivante passerait
        // aussi si le cloisonnement cachait TOUT — et un cloisonnement qui cache tout n'est pas un
        // cloisonnement, c'est une panne.
        self::assertStringContainsString(
            $id,
            $this->collection($client, $token, SocleFixtures::ETAB_A_NOM),
            'temoin : le groupe doit rester visible depuis SON etablissement',
        );

        self::assertStringNotContainsString(
            $id,
            $this->collection($client, $token, SocleFixtures::ETAB_B_NOM),
            'un groupe d\'options ne doit pas etre visible depuis un autre etablissement : '
            . '`ValeurOption` expose l\'impact tarifaire de chaque option',
        );
    }

    /**
     * ⚠ LA CREATION DOIT ENCORE MARCHER, ET LE GROUPE CREE DOIT REVENIR A SON AUTEUR.
     *
     * Le champ est hors du groupe d'ecriture — le client ne peut pas le fournir, sinon il suffirait
     * d'en designer un autre pour rouvrir la fuite. C'est donc `TenantReferenceProcessor` qui le
     * pose depuis l'etablissement actif. S'il ne le faisait pas, chaque groupe cree serait nul, donc
     * invisible de tous : la creation reussirait et l'ecran resterait vide.
     */
    public function testUnGroupeCreeRevientASonAuteur(): void
    {
        $client = static::createClient();
        $token = $this->jeton($client, SocleFixtures::ADMIN_EMAIL, SocleFixtures::ADMIN_MDP);
        $idA = $this->idEtablissement(SocleFixtures::ETAB_A_NOM);

        $client->request('POST', '/api/groupe_options', [
            'auth_bearer' => $token,
            'headers' => [ContexteEtablissement::HEADER => $idA, 'Content-Type' => 'application/ld+json'],
            'json' => ['libelle' => 'Taille de casier', 'modeSelection' => 'unique'],
        ]);
        self::assertResponseStatusCodeSame(201, 'l\'ecran doit pouvoir creer un groupe d\'options');

        $cree = $client->getResponse()->toArray()['id'];

        self::assertStringContainsString(
            $cree,
            $this->collection($client, $token, SocleFixtures::ETAB_A_NOM),
            'un groupe cree doit revenir a son auteur : sinon le processeur ne l\'estampille pas, '
            . 'et la creation reussit en laissant l\'ecran vide',
        );

        self::assertStringNotContainsString(
            $cree,
            $this->collection($client, $token, SocleFixtures::ETAB_B_NOM),
            'et il ne doit pas apparaitre ailleurs',
        );
    }

    /**
     * ⚠ LA JOINTURE DE CLOISONNEMENT SURVIT-ELLE A UNE REQUETE FILTREE ?
     *
     * `FilterEagerLoadingExtension` reconstruit la requete quand des filtres entrent en jeu, et perd
     * SILENCIEUSEMENT les jointures libres ajoutees par une extension. Un `EXISTS` autonome y
     * survit ; une jointure, non. Sa disparition ne se verrait qu'aux lignes en trop — la fuite
     * qu'on croyait fermee.
     *
     * `ValeurOption` declare deux filtres et son cloisonnement passe par une JOINTURE sur son
     * groupe. Elle est donc exactement dans le cas decrit, et les autres tests de ce fichier ne
     * peuvent pas l'attraper : ils listent SANS filtre, donc le mecanisme dangereux n'entre jamais
     * en jeu.
     *
     * On passe `actif`, un filtre declare mais etranger au cloisonnement : il declenche la
     * reconstruction sans influer sur ce qu'on mesure.
     */
    public function testLeCloisonnementDesValeursSurvitAUneRequeteFiltree(): void
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        $ids = [];
        foreach ([SocleFixtures::ETAB_A_NOM => 'valeur-de-A', SocleFixtures::ETAB_B_NOM => 'valeur-de-B'] as $nom => $libelle) {
            $etab = $em->getRepository(Etablissement::class)->findOneBy(['nom' => $nom]);
            self::assertInstanceOf(Etablissement::class, $etab);

            $groupe = (new GroupeOption())->setLibelle('Groupe ' . $libelle)->setModeSelection(ModeSelectionOption::Unique);
            $groupe->setEtablissement($etab);
            $em->persist($groupe);

            $valeur = (new ValeurOption())->setLibelle($libelle)->setActif(true)
                ->setImpactType(ImpactOptionType::Montant)->setImpactValeur('5.00');
            $valeur->setGroupeOption($groupe);
            $em->persist($valeur);

            $ids[$libelle] = $valeur;
        }
        $em->flush();

        $client = static::createClient();
        $token = $this->jeton($client, SocleFixtures::ADMIN_EMAIL, SocleFixtures::ADMIN_MDP);

        $client->request('GET', '/api/valeur_options', [
            'auth_bearer' => $token,
            'headers' => [ContexteEtablissement::HEADER => $this->idEtablissement(SocleFixtures::ETAB_A_NOM)],
            'query' => ['actif' => '1', 'itemsPerPage' => 200],
        ]);
        self::assertResponseIsSuccessful('temoin : la collection filtree doit repondre');

        $corps = $client->getResponse()->getContent(false);

        self::assertStringContainsString(
            'valeur-de-A',
            $corps,
            'temoin : ma propre valeur doit passer le filtre, sinon la mesure suivante porte sur '
            . 'une liste vide',
        );

        self::assertStringNotContainsString(
            'valeur-de-B',
            $corps,
            'la valeur d\'un autre etablissement ne doit pas reapparaitre sous filtre : si elle le '
            . 'fait, `FilterEagerLoadingExtension` a perdu la jointure de cloisonnement, et il faut '
            . 'un `EXISTS` autonome',
        );
    }

    private function collection(object $client, string $token, string $etablissementNom): string
    {
        $client->request('GET', '/api/groupe_options', [
            'auth_bearer' => $token,
            'headers' => [ContexteEtablissement::HEADER => $this->idEtablissement($etablissementNom)],
            'query' => ['itemsPerPage' => 200],
        ]);
        self::assertResponseIsSuccessful('temoin : la collection doit repondre sur ' . $etablissementNom);

        return $client->getResponse()->getContent(false);
    }
}
