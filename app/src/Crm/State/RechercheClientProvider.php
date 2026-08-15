<?php

declare(strict_types=1);

namespace App\Crm\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Crm\Entity\Client;
use App\Crm\Entity\PorteMonnaieVirtuel;
use App\Crm\Enum\StatutClient;
use App\Organisation\Entity\Etablissement;
use App\Organisation\Entity\Region;
use App\Securite\Entity\Affectation;
use App\Securite\Entity\Utilisateur;
use App\Vente\Port\RechercheSupportInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * GET /crm/clients/recherche (US-L5-01, CA-1/CA-2) : recherche tolérante à la casse (nom, prénom,
 * raison sociale, e-mail, téléphone) ou par n° de carte (`carte`, via `RechercheSupportInterface`),
 * combinée à des filtres `statut`, `avecPmv`, `mineur`. La liste est paginée ; par défaut, les fiches
 * `fusionne` sont exclues, sauf demande explicite via `statut=fusionne` (CA-2 : « aucune fiche
 * candidate à fusion n'est masquée »). Réponse minimale (pas d'`adresse`/`dateNaissance` en clair,
 * §5 plan-crm.md « données perso protégées »).
 *
 * @implements ProviderInterface<JsonResponse>
 */
final class RechercheClientProvider implements ProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RequestStack $requestStack,
        private readonly RechercheSupportInterface $rechercheSupport,
        private readonly Security $security,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        $request = $this->requestStack->getCurrentRequest();
        $q = trim((string) $request?->query->get('q', ''));
        $carte = trim((string) $request?->query->get('carte', ''));
        $statut = (string) $request?->query->get('statut', '');
        $avecPmv = $request?->query->get('avecPmv');
        $mineur = $request?->query->get('mineur');
        $page = max(1, (int) $request?->query->get('page', 1));
        $itemsPerPage = max(1, min(100, (int) ($request?->query->get('itemsPerPage', 20) ?: 20)));

        $qb = $this->em->createQueryBuilder();
        $qb->select('c')->from(Client::class, 'c');

        // Cloisonnement Groupe (RG-SOCLE-05, §6 plan-crm.md) : ce provider personnalisé contourne le
        // pipeline standard d'API Platform (donc `PerimetreCrmExtension`) — la restriction est donc
        // reproduite explicitement ici, même logique (EXISTS auto-contenu).
        $utilisateur = $this->security->getUser();
        if ($utilisateur instanceof Utilisateur) {
            $sousRequetePerimetre = 'SELECT aff_rcp.id FROM ' . Affectation::class . ' aff_rcp '
                . 'INNER JOIN ' . Etablissement::class . ' etb_rcp WITH etb_rcp = aff_rcp.etablissement '
                . 'INNER JOIN ' . Region::class . ' reg_rcp WITH reg_rcp = etb_rcp.region '
                . 'WHERE IDENTITY(aff_rcp.utilisateur) = :perimetre_rcp_utilisateur '
                . 'AND IDENTITY(reg_rcp.groupe) = IDENTITY(c.groupe)';
            $qb->andWhere('EXISTS (' . $sousRequetePerimetre . ')')
                ->setParameter('perimetre_rcp_utilisateur', $utilisateur->getId(), 'uuid');
        }

        if ($statut !== '') {
            $qb->andWhere('c.statut = :statut')->setParameter('statut', $statut);
        } else {
            $qb->andWhere('c.statut != :fusionne')->setParameter('fusionne', StatutClient::Fusionne->value);
        }

        if ($carte !== '') {
            $clientId = $this->rechercheSupport->clientPourSupport($carte);
            $qb->andWhere('c.id = :idCarte')->setParameter('idCarte', $clientId, 'uuid');
        } elseif ($q !== '') {
            // Tolérant à la casse (LIKE sur LOWER()) ; l'accent-insensibilité s'appuie sur la
            // collation par défaut de la base (utf8mb4_unicode_ci) — ⚠ HYPOTHÈSE non testée finement.
            $qb->andWhere($qb->expr()->orX(
                'LOWER(c.nom) LIKE :q',
                'LOWER(c.prenom) LIKE :q',
                'LOWER(c.raisonSociale) LIKE :q',
                'LOWER(c.email) LIKE :q',
                'LOWER(c.telephone) LIKE :q',
            ))->setParameter('q', '%' . mb_strtolower($q) . '%');
        }

        if ($avecPmv !== null) {
            $sub = $this->em->createQueryBuilder()->select('1')->from(PorteMonnaieVirtuel::class, 'p')->where('p.client = c');
            if ($this->versBool($avecPmv)) {
                $qb->andWhere($qb->expr()->exists($sub->getDQL()));
            } else {
                $qb->andWhere($qb->expr()->not($qb->expr()->exists($sub->getDQL())));
            }
        }

        if ($mineur !== null) {
            $seuil = new \DateTimeImmutable('-18 years');
            if ($this->versBool($mineur)) {
                $qb->andWhere('c.dateNaissance > :seuil')->setParameter('seuil', $seuil, 'date_immutable');
            } else {
                $qb->andWhere($qb->expr()->orX('c.dateNaissance <= :seuil', 'c.dateNaissance IS NULL'))->setParameter('seuil', $seuil, 'date_immutable');
            }
        }

        $total = (int) (clone $qb)->select('COUNT(c.id)')->getQuery()->getSingleScalarResult();

        $qb->orderBy('c.dateCreation', 'DESC')
            ->setFirstResult(($page - 1) * $itemsPerPage)
            ->setMaxResults($itemsPerPage);

        /** @var list<Client> $clients */
        $clients = $qb->getQuery()->getResult();

        $items = array_map(function (Client $c): array {
            $avecPmv = $this->em->getRepository(PorteMonnaieVirtuel::class)->findOneBy(['client' => $c]) !== null;

            return [
                'id' => (string) $c->getId(),
                'type' => $c->getType()->value,
                'nom' => $c->getNom(),
                'prenom' => $c->getPrenom(),
                'raisonSociale' => $c->getRaisonSociale(),
                'statut' => $c->getStatut()->value,
                'estMineur' => $c->estMineur(),
                'avecPmv' => $avecPmv,
                'dateDerniereVisite' => $c->getDateDerniereVisite()?->format(DATE_ATOM),
            ];
        }, $clients);

        return new JsonResponse(['items' => $items, 'total' => $total, 'page' => $page, 'itemsPerPage' => $itemsPerPage]);
    }

    private function versBool(mixed $valeur): bool
    {
        return \in_array($valeur, ['1', 'true', 1, true], true);
    }
}
