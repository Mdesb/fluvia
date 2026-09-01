<?php

declare(strict_types=1);

namespace App\Tests\Compta\Api;

use App\Compta\Entity\QualificationEquipement;
use App\Compta\Enum\Qualification;
use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Espace;
use App\Organisation\Entity\Etablissement;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\Compta\ComptaApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * LES QUALIFICATIONS D'EQUIPEMENT D'UN AUTRE CLIENT SONT-ELLES LISIBLES ?
 *
 * ⚠ MEME FAMILLE QUE `SousReseau`, PAR UNE RELATION SIMPLE — CE QUE LE BALAYAGE AVAIT EXCLU.
 *
 * Le balayage qui a trouve `SousReseau` cherchait les ressources non cloisonnees portant une
 * relation de COLLECTION vers une classe qui l'est. Il concluait a un membre unique dans tout le
 * depot. Mais une relation SIMPLE se serialise en IRI tout autant qu'une collection : le critere
 * etait trop etroit d'une dimension.
 *
 * `QualificationEquipement` porte un `OneToOne` vers `Espace`, dans son groupe de lecture. `Espace`
 * est cloisonne ; `QualificationEquipement` ne l'est par aucune extension. `AccountingScopeExtension`
 * cloisonne par `profilExploitant` ou par une relation qui en porte un — celle-ci n'a ni l'un ni
 * l'autre, son chemin est `espace.etablissement`.
 *
 * ── CE QUE LA COLLECTION REND ───────────────────────────────────────────────────────────────────
 *
 * `qualif:read` expose l'IRI de l'espace et la qualification SPIC/SPA. C'est-a-dire, pour chaque
 * equipement de chaque client : son existence, et son regime fiscal. Garde : `compta.lire`.
 *
 * Moins grave que les sept precedentes — pas de donnee personnelle, pas de montant, pas de capacite
 * d'agir. Mais c'est la structure d'exploitation d'un concurrent et sa qualification fiscale.
 */
final class CloisonnementQualificationTest extends ComptaApiTestCase
{
    public function testLesQualificationsDunAutreEtablissementNeSontPasListees(): void
    {
        [$client, $entete] = $this->adminSurA();

        $idSien = $this->qualificationSur(SocleFixtures::ETAB_A_NOM, 'Bassin qualifie A');
        $idEtranger = $this->qualificationSur(SocleFixtures::ETAB_B_NOM, 'Piste qualifiee B');

        $client->request('GET', '/api/qualification_equipements', $entete + ['query' => ['itemsPerPage' => 100]]);
        self::assertResponseIsSuccessful('temoin : la route doit exister et repondre');

        $corps = $client->getResponse()->getContent(false);

        // ⚠ CONTROLE POSITIF. Une collection vide passerait l'assertion suivante en prouvant le
        // contraire de ce qu'on veut mesurer.
        self::assertStringContainsString(
            $idSien,
            $corps,
            'temoin : la qualification de MON etablissement doit etre lisible',
        );

        self::assertStringNotContainsString(
            $idEtranger,
            $corps,
            'la collection expose l\'IRI de l\'espace et la qualification SPIC/SPA : celles d\'un '
            . 'autre etablissement ne doivent pas y figurer',
        );
    }

    /**
     * Une qualification sur un espace de l'etablissement donne ; rend l'id de la qualification.
     *
     * ⚠ L'ADMINISTRATEUR DU SOCLE EST AFFECTE A A ET A B. Le cloisonnement de ce depot porte sur
     * l'etablissement ACTIF, pas sur le perimetre du lecteur — c'est une bascule assumee du 28/08,
     * documentee dans les extensions. La mesure est donc juste : l'en-tete `X-Etablissement` designe
     * A, et ce qui appartient a B ne doit pas remonter, meme si le lecteur y a des droits ailleurs.
     */
    private function qualificationSur(string $etablissementNom, string $nomEspace): string
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        $etab = $em->getRepository(Etablissement::class)->findOneBy(['nom' => $etablissementNom]);
        self::assertInstanceOf(Etablissement::class, $etab, 'temoin : ' . $etablissementNom . ' doit exister');

        $espace = (new Espace())->setNom($nomEspace)->setEtablissement($etab)->setType('bassin');
        $em->persist($espace);

        $qualif = new QualificationEquipement();
        $qualif->setEspace($espace)->setQualification(Qualification::Spic);
        $em->persist($qualif);
        $em->flush();

        return (string) $qualif->getId();
    }
}
