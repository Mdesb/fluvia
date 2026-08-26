<?php

declare(strict_types=1);

namespace App\Vente\Service;

use App\Caisse\Entity\PointDeVente;
use App\Organisation\Entity\Etablissement;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Le point de vente dédié à la vente directe — un par établissement (D44-bis).
 *
 * **Pourquoi il en faut un, alors que la vente directe se définit justement par l'absence de caisse.**
 * La chaîne NF525 est chaînée **par point de vente** : `HashChainSignataire` numérote et enchaîne les
 * empreintes par `PointDeVente`, et `ValiderVenteService` refuse net de valider une vente qui n'en a
 * pas. Une vente directe rattachée à aucun point de vente ne serait donc pas « une vente sans caisse » :
 * ce serait **une vente non scellée** — exactement ce que ce module entier existe pour empêcher.
 *
 * **Pourquoi il est dédié, et pas celui du guichet.** Verser les ventes directes dans la chaîne du
 * guichet ferait compter au Z du guichet des ventes qui n'ont jamais touché son tiroir. Le Z cesserait
 * de dire ce qu'il a encaissé, et c'est la seule chose qu'on lui demande.
 *
 * **Pourquoi un par établissement.** Le point de vente porte l'établissement, et le cloisonnement en
 * dépend. Un point de vente partagé chaînerait ensemble les ventes de tous les établissements — donc
 * les rendrait lisibles ensemble le jour où quelqu'un relit la chaîne.
 *
 * Il est créé à la première vente directe et **jamais supprimé** : le supprimer romprait une chaîne.
 */
final class DirectSalePoint
{
    /** Libellé du point de vente dédié. Il sert de clé de résolution : l'entité ne porte pas de code. */
    public const LABEL = 'Vente directe';

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function forEstablishment(Etablissement $establishment): PointDeVente
    {
        $existing = $this->em->getRepository(PointDeVente::class)
            ->findOneBy(['etablissement' => $establishment, 'libelle' => self::LABEL]);
        if ($existing instanceof PointDeVente) {
            return $existing;
        }

        $point = (new PointDeVente())
            ->setLibelle(self::LABEL)
            ->setEtablissement($establishment);

        // `moyensAutorises` reste **vide, volontairement**. Le refus des espèces est une règle de code
        // (`PaiementHandler`), pas un réglage : un réglage se modifie depuis l'écran d'administration,
        // et la garantie « rien à compter, donc rien à clôturer » cesserait d'en être une. Le laisser
        // vide fait aussi que le test qui refuse les espèces prouve **la règle**, pas la configuration
        // — un test vert pour la mauvaise raison ne protège rien.
        $this->em->persist($point);
        $this->em->flush();

        return $point;
    }
}
