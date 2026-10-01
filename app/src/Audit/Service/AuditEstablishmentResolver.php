<?php

declare(strict_types=1);

namespace App\Audit\Service;

use App\Organisation\Entity\Etablissement;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\ContexteEtablissement;
use App\Securite\Service\EstablishmentReachability;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Uid\Uuid;

/**
 * L'ÉTABLISSEMENT D'UNE ENTRÉE D'AUDIT, POUR CHAQUE CLASSE AUDITÉE — ET LE REFUS DE LE DEVINER.
 *
 * Une entrée d'audit porte l'établissement de ce qu'elle décrit ; c'est lui que lit le cloisonnement
 * (`ResidualScopeExtension`). Jusqu'au 01/10/2026, seules les entités exposant `getEtablissement()` ou
 * `getEstablishment()` en recevaient un. Les autres — clients, familles, paiements, clôtures Z,
 * opérations scellées… — étaient écrites SANS établissement, ce qui se lisait « global » : tout client
 * muni du droit de lire l'audit lisait les fiches des autres (nom, e-mail, téléphone, date de
 * naissance, adresse), leurs paiements et leurs connexions. Mesuré sur la préprod : 32 classes, dont
 * 76 912 entrées `Utilisateur`.
 *
 * Trois cas, et chaque classe auditée doit relever d'UN des trois (`AuditTenantIsolationTest` échoue
 * sinon — une classe ajoutée à l'audit sans décision redeviendrait une fuite silencieuse) :
 *
 *   1. accesseur direct `getEtablissement()` / `getEstablishment()` (ou l'établissement lui-même) ;
 *   2. un CHEMIN de relations déclaré dans `PATHS`, ou une référence libre dans `FREE_REFERENCES` ;
 *   3. aucun établissement par nature (`WITHOUT_ESTABLISHMENT`) : l'entrée prend l'établissement ACTIF de
 *      son auteur (`actorEstablishment()`) — un administrateur garde l'historique de ce que son équipe a
 *      fait sur ses comptes et ses rôles, un autre client n'en voit rien. Sans auteur authentifié qui
 *      atteint son établissement actif (connexion, tâche planifiée, route publique), elle reste sans
 *      établissement, et depuis ce correctif seule la session de l'éditeur la lit.
 *
 * Les clients appartiennent au GROUPE, pas à un site : on les rattache à leur établissement de
 * création. C'est le sens qui restreint — un autre site du même groupe ne lit plus cet historique.
 */
final readonly class AuditEstablishmentResolver
{
    /**
     * Chemins de getters, essayés dans l'ordre ; le premier qui aboutit à un établissement l'emporte.
     *
     * @var array<class-string, list<list<string>>>
     */
    public const PATHS = [
        \App\Crm\Entity\Client::class => [['getEtablissementCreation']],
        \App\Crm\Entity\Famille::class => [['getPayeurPrincipal', 'getEtablissementCreation']],
        \App\Crm\Entity\Beneficiaire::class => [
            ['getClient', 'getEtablissementCreation'],
            ['getFamille', 'getPayeurPrincipal', 'getEtablissementCreation'],
        ],
        \App\Crm\Entity\PorteMonnaieVirtuel::class => [['getClient', 'getEtablissementCreation']],
        \App\Crm\Entity\Consentement::class => [['getClient', 'getEtablissementCreation']],
        \App\Crm\Entity\DemandeRGPD::class => [['getClient', 'getEtablissementCreation']],
        \App\Vente\Entity\Paiement::class => [['getVente', 'getEtablissement']],
        \App\Vente\Nf525\Entity\OperationScellee::class => [['getPointDeVente', 'getEtablissement']],
        \App\Caisse\Entity\ClotureZ::class => [['getSession', 'getEtablissement']],
        \App\Caisse\Entity\MouvementCaisse::class => [['getSession', 'getEtablissement']],
        \App\Compta\Entity\BordereauVersement::class => [['getRegie', 'getProfilExploitant', 'getEtablissement']],
        \App\Compta\Entity\QualificationEquipement::class => [['getEspace', 'getEtablissement']],
        \App\Membership\Entity\Resiliation::class => [['getAbonnement', 'getEtablissement']],
        \App\Offre\Entity\GrilleTarifaire::class => [['getSaison', 'getEtablissement']],
        \App\Acces\Entity\ListeRevocation::class => [['getControleur', 'getEtablissement']],
        \App\Piscine\Entity\ForcageCasier::class => [['getCasier', 'getEtablissement']],
        \App\Sport\Entity\EvenementSOS::class => [['getEspaceAcces', 'getEtablissement']],
        \App\Stock\Entity\TransfertStock::class => [['getArticleStockSource', 'getEtablissement']],
    ];

    /**
     * Références libres (colonne `uuid` sans clé étrangère) : getter de l'identifiant, entité désignée,
     * chemin depuis elle.
     *
     * @var array<class-string, array{0: string, 1: class-string, 2: list<string>}>
     */
    public const FREE_REFERENCES = [
        \App\Compta\Entity\VenteImpayeeRegie::class => ['getVenteOrigine', \App\Vente\Entity\Vente::class, ['getEtablissement']],
        // Le journal d'une fusion porte l'instantané des fiches fusionnées : il suit la fiche survivante.
        \App\Crm\Entity\JournalFusion::class => ['getFicheSurvivante', \App\Crm\Entity\Client::class, ['getEtablissementCreation']],
    ];

    /**
     * Sans établissement par nature : plateforme, organisation, référentiels partagés. Leurs entrées
     * ne sont lues que par l'éditeur.
     *
     * @var list<class-string>
     */
    public const WITHOUT_ESTABLISHMENT = [
        \App\Securite\Entity\Utilisateur::class,      // un compte vaut pour plusieurs établissements
        \App\Securite\Entity\Role::class,
        \App\Securite\Entity\Permission::class,
        \App\Organisation\Entity\Groupe::class,
        \App\Organisation\Entity\Region::class,
        \App\Crm\Entity\RegleConservation::class,     // portée par le groupe
        \App\Offre\Entity\Produit::class,             // vendu sur plusieurs établissements
        \App\Offre\Entity\Promotion::class,           // idem
        \App\Offre\Entity\Categorie::class,
        \App\Offre\Entity\TypeTarif::class,
        \App\Acces\Entity\SousReseau::class,
        \App\Autorisation\Entity\OperationSensible::class,
    ];

    public function __construct(
        private Security $security,
        private ContexteEtablissement $contexte,
        private EstablishmentReachability $reachability,
    ) {
    }

    /**
     * L'établissement actif de l'auteur de l'écriture — SEULEMENT s'il l'atteint.
     *
     * L'en-tête `X-Etablissement` n'est validé par `EstablishmentHeaderListener` que pour un utilisateur
     * authentifié, et pas sur `/me`. Le lire tel quel laisserait une requête anonyme, ou un `/me` portant
     * l'en-tête d'un autre client, écrire dans le journal de ce client. On refait donc la vérification.
     */
    public function actorEstablishment(): ?Uuid
    {
        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            return null;
        }
        $id = $this->contexte->idActif();
        if ($id === null || !$this->reachability->canReach($utilisateur, $id, new \DateTimeImmutable())) {
            return null;
        }

        return $id;
    }

    /** L'établissement d'une entrée d'audit portant sur `$entity`, auteur compris (cas 3). */
    public function forEntity(object $entity, EntityManagerInterface $em): ?Uuid
    {
        $etablissement = $this->resolve($entity, $em);
        if ($etablissement === null && \in_array($this->classeDe($entity, $em), self::WITHOUT_ESTABLISHMENT, true)) {
            return $this->actorEstablishment();
        }

        return $etablissement;
    }

    public function resolve(object $entity, EntityManagerInterface $em): ?Uuid
    {
        if ($entity instanceof Etablissement) {
            return $entity->getId();
        }
        // Deux orthographes cohabitent dans le dépôt : `etablissement` sur les entités d'avant D5,
        // `establishment` sur celles d'après.
        foreach (['getEtablissement', 'getEstablishment'] as $accesseur) {
            if (method_exists($entity, $accesseur)) {
                $etablissement = $entity->{$accesseur}();
                if ($etablissement instanceof Etablissement) {
                    return $etablissement->getId();
                }
            }
        }

        $classe = $this->classeDe($entity, $em);
        foreach (self::PATHS[$classe] ?? [] as $chemin) {
            $etablissement = $this->suivre($entity, $chemin);
            if ($etablissement !== null) {
                return $etablissement->getId();
            }
        }

        if (isset(self::FREE_REFERENCES[$classe])) {
            [$getter, $cible, $chemin] = self::FREE_REFERENCES[$classe];
            $id = $entity->{$getter}();
            $designee = $id === null ? null : $em->find($cible, $id);

            return $designee === null ? null : $this->suivre($designee, $chemin)?->getId();
        }

        return null;
    }

    /** @param list<string> $chemin */
    private function suivre(object $depart, array $chemin): ?Etablissement
    {
        $courant = $depart;
        foreach ($chemin as $getter) {
            $courant = $courant->{$getter}();
            if ($courant === null) {
                return null;
            }
        }

        return $courant instanceof Etablissement ? $courant : null;
    }

    /** La classe MAPPÉE, pas celle d'un proxy Doctrine. */
    private function classeDe(object $entity, EntityManagerInterface $em): string
    {
        return $em->getClassMetadata($entity::class)->getName();
    }
}
