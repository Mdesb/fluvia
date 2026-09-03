<?php

declare(strict_types=1);

namespace App\Crm\State;

use App\Crm\Doctrine\CustomerScope;
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
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * GET /crm/clients/recherche (US-L5-01, CA-1/CA-2) : recherche tolérante à la casse (nom, prénom,
 * raison sociale, e-mail, téléphone) ou par n° de carte (`carte`, via `RechercheSupportInterface`),
 * combinée à des filtres `statut`, `avecPmv`, `mineur`. La liste est paginée. Réponse minimale
 * (pas d'`adresse`/`dateNaissance` en clair, §5 plan-crm.md « données perso protégées »).
 *
 * ⚠ PAR DÉFAUT, TROIS STATUTS SONT ÉCARTÉS : `archive`, `anonymise`, `fusionne` (R24, R25). Le
 * docblock disait « par défaut, les fiches `fusionne` sont exclues » — c'était vrai, et les deux
 * autres passaient. Une fiche anonymisée est vidée de ses données ; la proposer dans un sélecteur
 * de client, c'est proposer une coquille.
 *
 * `statut` accepte une valeur OU une liste (`statut[]=archive&statut[]=anonymise`), ce qui est ce
 * dont les cases à cocher de R26 ont besoin. Un statut inconnu est REFUSÉ, pas ignoré.
 *
 * `masquesParStatut` dit combien de fiches ce filtre a écartées, pour que l'écran écrive
 * « 12 masquées » au lieu de montrer une liste plus courte sans rien expliquer.
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
        // ⚠ `->get()` LEVE SUR UN PARAMETRE TABLEAU (« Input value "statut" contains a
        // non-scalar value »), donc `?statut[]=…` rendait 400 avant meme d'arriver ici. On lit le
        // sac brut et on normalise : une valeur seule ou une liste, indifferemment.
        $brut = $request?->query->all()['statut'] ?? null;
        $statuts = array_values(array_filter(
            array_map(static fn (mixed $v): string => trim((string) $v), (array) $brut),
            static fn (string $s): bool => $s !== '',
        ));

        // ⚠ UN STATUT INCONNU EST REFUSE, PAS IGNORE. `?statut=archivee` serait tombe dans le cas
        // « aucun filtre » et aurait rendu la liste par defaut : l'exploitant aurait cru filtrer et
        // lu une liste complete. C'est le defaut de `?abonnement=` du 02/09, a l'identique.
        $connus = array_map(static fn (StatutClient $s): string => $s->value, StatutClient::cases());
        $inconnus = array_diff($statuts, $connus);
        if ($inconnus !== []) {
            throw new BadRequestHttpException(sprintf(
                'Statut inconnu : %s. Valeurs acceptees : %s.',
                implode(', ', $inconnus),
                implode(', ', $connus),
            ));
        }
        $avecPmv = $request?->query->get('avecPmv');
        $mineur = $request?->query->get('mineur');
        $page = max(1, (int) $request?->query->get('page', 1));
        $itemsPerPage = max(1, min(100, (int) ($request?->query->get('itemsPerPage', 20) ?: 20)));

        $qb = $this->em->createQueryBuilder();
        $qb->select('c')->from(Client::class, 'c');

        // Cloisonnement Groupe (RG-SOCLE-05) : ce fournisseur sur mesure court-circuite le
        // pipeline d'API Platform, donc `PerimetreCrmExtension` ne s'applique pas. La clause était
        // RECOPIÉE ici — ce que le commentaire d'origine documentait honnêtement. Deux copies d'une
        // règle de sécurité divergent le jour où l'on n'en corrige qu'une, et c'est la périmée qui
        // décide alors qui voit quoi.
        $utilisateur = $this->security->getUser();
        if ($utilisateur instanceof Utilisateur) {
            CustomerScope::restreindreAuGroupe($qb, 'c', $utilisateur->getId());
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

        // ⚠ LE FILTRE DE STATUT S'APPLIQUE EN DERNIER, ET C'EST CE QUI PERMET DE COMPTER CE QU'IL
        // CACHE. On compte d'abord avec tous les autres criteres, puis avec celui-ci : la
        // difference est le nombre de fiches que le STATUT a ecartees, et lui seul. Compter avant
        // les autres filtres aurait annonce « 40 masquees » a quelqu'un qui a juste tape un nom.
        $totalAvantStatut = (int) (clone $qb)->select('COUNT(c.id)')->getQuery()->getSingleScalarResult();

        if ($statuts !== []) {
            $qb->andWhere('c.statut IN (:statuts)')->setParameter('statuts', $statuts);
        } else {
            // R24 et R25 : une fiche anonymisee est videe de ses donnees, une archivee n'est plus
            // courante, une fusionnee n'existe plus en tant que telle. Aucune des trois n'a sa
            // place dans une liste de travail — elles se redemandent en cochant.
            $qb->andWhere('c.statut NOT IN (:masques)')->setParameter('masques', [
                StatutClient::Archive->value,
                StatutClient::Anonymise->value,
                StatutClient::Fusionne->value,
            ]);
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

        // ⚠ « MONTRER MOINS » N'EST PAS « DIRE QU'ON CACHE ». Une liste plus courte se lit « il y
        // a moins de clients », pas « j'en ecarte trois ». L'ecran a besoin du nombre pour l'ecrire.
        return new JsonResponse([
            'items' => $items,
            'total' => $total,
            'masquesParStatut' => max(0, $totalAvantStatut - $total),
            'page' => $page,
            'itemsPerPage' => $itemsPerPage,
        ]);
    }

    private function versBool(mixed $valeur): bool
    {
        return \in_array($valeur, ['1', 'true', 1, true], true);
    }
}
