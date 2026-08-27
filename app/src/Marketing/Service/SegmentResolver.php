<?php

declare(strict_types=1);

namespace App\Marketing\Service;

use App\Crm\Doctrine\CustomerScope;
use App\Crm\Entity\Client;
use App\Crm\Enum\StatutClient;
use App\Crm\Enum\TypeClient;
use App\Marketing\Entity\Segment;
use App\Marketing\Entity\SegmentCriteria;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Uid\Uuid;

/**
 * TRADUIT DES CRITÈRES EN CLIENTS — et ne rend jamais un client qu'on n'a pas le droit de voir.
 *
 * ── LE CLOISONNEMENT N'EST PAS UNE OPTION DE CETTE CLASSE ───────────────────────────────────────
 *
 * `CustomerScope::restreindreAuGroupe()` est appliqué **avant** tout critère, sur le même
 * `QueryBuilder`, et il n'existe aucun chemin qui l'omette : la construction de la requête commence
 * par lui. Un critère d'établissement fourni par l'appelant **restreint** ensuite ; il n'élargit
 * jamais.
 *
 * Viser un établissement hors périmètre ne rend donc pas d'erreur : ça rend zéro client. C'est
 * volontaire — un refus explicite confirmerait l'existence de cet établissement, et le nom d'un
 * client ne doit pas fuir dans un message d'erreur (RG-CMP-14, CA-8).
 *
 * ── LES CLIENTS QU'ON N'ADRESSE JAMAIS ──────────────────────────────────────────────────────────
 *
 * Deux exclusions sont posées ici, avant les critères, parce qu'aucun exploitant ne pense à les
 * écrire et que les oublier se paie cher :
 *
 * · **les fiches fusionnées** — écrire à une fiche absorbée, c'est écrire deux fois à la même
 *   personne, sous deux identités ;
 * · **les fiches inactives** — un client archivé ou opposé n'est pas une cible, c'est un dossier.
 *
 * ── L'ÂGE SE CALCULE, IL NE SE STOCKE PAS ───────────────────────────────────────────────────────
 *
 * Un `age` en base serait faux le lendemain de chaque anniversaire, et personne ne le verrait. On
 * compare donc des DATES DE NAISSANCE à des bornes calculées maintenant : « au moins 18 ans » devient
 * « né avant aujourd'hui moins 18 ans ».
 */
final readonly class SegmentResolver
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private Security $security,
    ) {
    }

    /**
     * Le nombre de clients que ce segment désigne aujourd'hui.
     */
    public function compter(Segment $segment): int
    {
        $qb = $this->requete($segment);
        if ($qb === null) {
            return 0;
        }

        return (int) $qb->select('COUNT(DISTINCT c.id)')->getQuery()->getSingleScalarResult();
    }

    /**
     * Un échantillon, pour que l'exploitant reconnaisse ses clients avant d'envoyer.
     *
     * @return list<Client>
     */
    public function echantillon(Segment $segment, int $limite = 10): array
    {
        $qb = $this->requete($segment);
        if ($qb === null) {
            return [];
        }

        /** @var list<Client> $clients */
        $clients = $qb->select('c')->orderBy('c.dateDerniereVisite', 'DESC')
            ->setMaxResults($limite)->getQuery()->getResult();

        return $clients;
    }

    /**
     * Tous les clients du segment — utilisé au moment de figer une liste de destinataires.
     *
     * @return list<Client>
     */
    public function resoudre(Segment $segment): array
    {
        $qb = $this->requete($segment);
        if ($qb === null) {
            return [];
        }

        /** @var list<Client> $clients */
        $clients = $qb->select('c')->getQuery()->getResult();

        return $clients;
    }

    private function requete(Segment $segment): ?QueryBuilder
    {
        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            // Fermeture par défaut : sans utilisateur identifié, aucun périmètre — donc personne.
            return null;
        }

        $qb = $this->entityManager->getRepository(Client::class)->createQueryBuilder('c');

        // LE PÉRIMÈTRE D'ABORD, LES CRITÈRES ENSUITE. L'ordre n'est pas cosmétique : il rend
        // impossible d'écrire un critère qui l'élargirait.
        CustomerScope::restreindreAuGroupe($qb, 'c', $utilisateur->getId());

        $qb->andWhere('c.fusionneDans IS NULL')
            ->andWhere('c.statut = :segment_statut_actif')
            ->setParameter('segment_statut_actif', StatutClient::Actif->value);

        $criteres = $segment->getCriteria();

        $jours = $criteres[SegmentCriteria::SANS_VISITE_DEPUIS_JOURS] ?? null;
        if (\is_int($jours) || (\is_string($jours) && ctype_digit($jours))) {
            // « Jamais venu » compte comme « pas venu depuis longtemps » : c'est ce que l'exploitant
            // veut dire, et l'exclure ferait manquer précisément les clients à reconquérir.
            $qb->andWhere('(c.dateDerniereVisite IS NULL OR c.dateDerniereVisite < :segment_avant)')
                ->setParameter('segment_avant', new \DateTimeImmutable(sprintf('-%d days', (int) $jours)));
        }

        $min = $criteres[SegmentCriteria::CA_CUMULE_MIN] ?? null;
        if (is_numeric($min)) {
            $qb->andWhere('c.caCumule >= :segment_ca_min')->setParameter('segment_ca_min', (string) $min);
        }

        $max = $criteres[SegmentCriteria::CA_CUMULE_MAX] ?? null;
        if (is_numeric($max)) {
            $qb->andWhere('c.caCumule <= :segment_ca_max')->setParameter('segment_ca_max', (string) $max);
        }

        $ageMin = $criteres[SegmentCriteria::AGE_MIN] ?? null;
        if (is_numeric($ageMin)) {
            // Au moins N ans : né avant la date d'aujourd'hui moins N ans.
            $qb->andWhere('c.dateNaissance IS NOT NULL AND c.dateNaissance <= :segment_ne_avant')
                ->setParameter('segment_ne_avant', new \DateTimeImmutable(sprintf('-%d years', (int) $ageMin)));
        }

        $ageMax = $criteres[SegmentCriteria::AGE_MAX] ?? null;
        if (is_numeric($ageMax)) {
            $qb->andWhere('c.dateNaissance IS NOT NULL AND c.dateNaissance > :segment_ne_apres')
                ->setParameter('segment_ne_apres', new \DateTimeImmutable(sprintf('-%d years', (int) $ageMax + 1)));
        }

        $type = $criteres[SegmentCriteria::TYPE] ?? null;
        if (\is_string($type) && TypeClient::tryFrom($type) !== null) {
            $qb->andWhere('c.type = :segment_type')->setParameter('segment_type', $type);
        }

        $etablissement = $criteres[SegmentCriteria::ETABLISSEMENT] ?? null;
        if (\is_string($etablissement) && Uuid::isValid($etablissement)) {
            $qb->andWhere('IDENTITY(c.etablissementCreation) = :segment_etablissement')
                // Type `uuid` explicite (D58) : sans lui la comparaison ne compte rien et ne lève
                // pas — le segment paraîtrait simplement vide.
                ->setParameter('segment_etablissement', Uuid::fromString($etablissement), 'uuid');
        }

        return $qb;
    }
}
