<?php

declare(strict_types=1);

namespace App\Boutique\Service;

use App\Boutique\Entity\CompteClient;
use App\Boutique\Entity\SuiviCommandeEnLigne;
use App\Crm\Entity\Client;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Rendre a un compte les commandes qu'il a passees en invite, une fois son adresse prouvee.
 *
 * ⚠ CE QUE L'ABSENCE DE CE GESTE COUTAIT. Un achat en invite enregistre bien une `Vente` et un
 * `SuiviCommandeEnLigne` -- mais avec `compteClient = null`, parce que le panier n'avait pas de
 * compte. Or `MesCommandesProvider` filtre sur `compteClient`. Ces commandes n'etaient donc
 * visibles d'AUCUN compte, definitivement, meme apres inscription avec la meme adresse.
 *
 * ⚠ ON NE FUSIONNE PAS LES FICHES CLIENT, ET C'EST LA LETTRE DE RG-M3-10 (CA-5) : « aucune
 * recherche/fusion automatique [...] une nouvelle fiche Client est systematiquement creee ». Ce
 * handler ne touche a aucune fiche `Client` : il repointe des SUIVIS DE COMMANDE vers un compte.
 * Les deux fiches survivent, l'historique redevient lisible. La regle interdit de confondre deux
 * personnes ; elle n'oblige pas a cacher ses achats a quelqu'un qui a prouve son adresse.
 *
 * ⚠ ET LE RATTACHEMENT S'ARRETE A L'ETABLISSEMENT DU COMPTE. Un `CompteClient` porte un
 * etablissement, un `SuiviCommandeEnLigne` aussi. Rattacher au-dela ferait entrer dans un compte
 * une commande passee chez un autre exploitant du groupe -- c'est-a-dire percer le cloisonnement
 * par une porte que personne ne surveille. Si un jour un compte doit couvrir plusieurs sites, ce
 * sera une decision explicite, pas un effet de bord de ce fichier.
 *
 * ⚠ AUCUNE LISTE `IN` SUR `Vente::$client`, ET CE N'EST PAS UNE PREFERENCE DE STYLE. C'est une
 * COLONNE UUID NUE, pas une relation : `setParameter` ne convertit pas les elements d'un tableau,
 * et `v.client IN (:ids)` ne leve pas -- elle rend une liste VIDE. Le rattachement aurait donc
 * silencieusement ne rien rattacher, indiscernable de « cette personne n'avait rien achete ».
 * Attrape par le garde-fou des references libres (D58) avant la premiere execution. On compare
 * donc UNE fiche a la fois, avec le type explicite -- et une adresse n'en porte qu'une ou deux.
 */
final class GuestOrderClaimHandler
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * @return int nombre de commandes rendues au compte
     */
    public function rattacher(CompteClient $compte, string $adresse): int
    {
        $etablissement = $compte->getEtablissement();
        $normalisee = mb_strtolower(trim($adresse));
        if ($etablissement === null || $normalisee === '') {
            return 0;
        }

        // ⚠ COMPARAISON INSENSIBLE A LA CASSE DES DEUX COTES. L'invite a saisi son adresse dans le
        // tunnel d'achat, le titulaire la ressaisit a l'inscription : « Jean@x.fr » et « jean@x.fr »
        // sont la meme boite aux lettres pour tout fournisseur reel. Comparer brut ferait echouer
        // le rattachement au hasard d'une majuscule, et personne ne saurait pourquoi.
        $identifiants = $this->em->createQuery(
            'SELECT c.id FROM '.Client::class.' c WHERE LOWER(c.email) = :adresse'
        )->setParameter('adresse', $normalisee)->getSingleColumnResult();

        $rendues = 0;
        foreach ($identifiants as $identifiant) {
            $suivis = $this->em->createQuery(
                'SELECT s FROM '.SuiviCommandeEnLigne::class.' s
                 JOIN s.vente v
                 WHERE s.compteClient IS NULL
                   AND s.etablissement = :etablissement
                   AND v.client = :client'
            )
                ->setParameter('etablissement', $etablissement)
                // Le troisieme argument est ce qui manquait : sans lui, la comparaison ne leve pas,
                // elle ne trouve simplement jamais rien.
                ->setParameter('client', $identifiant, 'uuid')
                ->getResult();

            foreach ($suivis as $suivi) {
                $suivi->setCompteClient($compte);
                ++$rendues;
            }
        }

        if ($rendues > 0) {
            $this->em->flush();
        }

        return $rendues;
    }
}
