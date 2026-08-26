<?php

declare(strict_types=1);

namespace App\Tests\Vente\Unit;

use App\Vente\Entity\Vente;
use App\Vente\Nf525\InalterabiliteListener;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * **Aucun champ ne peut être ajouté à `Vente` sans qu'on ait dit s'il est figé par le scellement.**
 *
 * `InalterabiliteListener::CHAMPS_VENTE_FIGES` est une liste écrite à la main, sur une entité que huit
 * sessions modifient. Elle a exactement la faiblesse qu'on corrige partout ailleurs : **elle dépend de
 * la vigilance de qui ajoute une colonne**, et son oubli ne casse rien — il ouvre juste un champ de
 * plus à la modification d'une vente validée.
 *
 * Ce n'est pas une hypothèse. **C'est arrivé le 26/08, et c'est moi qui l'ai fait.** En déplaçant
 * l'ancre fiscale de la vente de `session` vers `pointDeVente` (D44-bis), j'ai coupé une protection qui
 * jouait **par déduction** : `session` était figée, donc le point de vente qui s'en déduisait l'était
 * aussi. Une fois porté, il ne l'était plus, et rien ne s'est rompu pour le dire — déplacer une vente
 * scellée d'une chaîne d'empreintes à l'autre l'aurait fait disparaître d'un arrêté de totaux et
 * apparaître dans un autre, en silence.
 *
 * **La règle qu'on en tire :** quand une propriété passe de « déduite » à « portée », tout ce qui la
 * protégeait par déduction cesse de la protéger, et aucun contrôle ne se rompt pour l'annoncer.
 * Vérifier les usages n'est pas vérifier les garanties.
 *
 * Ce test est le mécanisme qui remplace la vigilance : il confronte les champs réellement mappés à la
 * liste, et **pose la question** au lieu de donner un ordre — parce que la bonne réponse dépend du
 * champ, et que celui qui l'ajoute est le seul à la connaître.
 */
final class ChampsFigesTest extends KernelTestCase
{
    /**
     * Champs délibérément **non** figés, chacun avec sa raison. Ce ne sont pas des oublis tolérés :
     * ce sont des décisions, et elles doivent rester lisibles.
     *
     * @var array<string, string>
     */
    private const EXCEPTIONS = [
        'id' => 'La clé primaire ne change pas ; Doctrine ne la propose même pas à la modification.',
        'statut' => 'Les transitions d\'état sont le seul mouvement permis après validation (annulation, avoir émis).',
        'imprime' => 'Réimprimer un ticket est licite et ne touche à aucun montant.',
        'resteAPayer' => 'Recalculé, jamais saisi ; il découle des paiements, eux-mêmes append-only.',
        'lignes' => 'Collection : figée par ailleurs, ligne par ligne, via `venteScellee()` dans le même listener.',
        'paiements' => 'Collection : `Paiement` est append-only en propre, déjà couvert par `estAppendOnly()`.',
        'supports' => 'Collection : les supports émis ont leur propre cycle (remise, compostage) après la vente.',
    ];

    /** Tout champ mappé est soit figé, soit exempté avec sa raison. */
    public function testTousLesChampsSontFigesOuExemptesAvecRaison(): void
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $metadata = $em->getClassMetadata(Vente::class);

        $champs = array_merge($metadata->getFieldNames(), $metadata->getAssociationNames());
        $figes = $this->champsFiges();

        foreach ($champs as $champ) {
            self::assertTrue(
                \in_array($champ, $figes, true) || isset(self::EXCEPTIONS[$champ]),
                sprintf(
                    "Champ « %s » ajouté à Vente : est-il figé par le scellement NF525 ?\n"
                    . "  — Si oui : ajoute-le à InalterabiliteListener::CHAMPS_VENTE_FIGES.\n"
                    . "  — Si non : ajoute-le aux exceptions de ce test, AVEC la raison.\n"
                    . 'Un champ modifiable sur une vente validée n\'est pas un détail : c\'est une vente '
                    . 'qu\'on peut réécrire après coup, et alors plus aucune vente n\'est probante.',
                    $champ,
                ),
            );
        }
    }

    /**
     * L'inverse compte aussi : un champ figé qui n'existe plus est une protection qui ne protège rien,
     * et qui donnera l'impression du contraire à qui lira la liste.
     */
    public function testAucunChampFigeNAdisparuDuMapping(): void
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $metadata = $em->getClassMetadata(Vente::class);
        $champs = array_merge($metadata->getFieldNames(), $metadata->getAssociationNames());

        foreach ($this->champsFiges() as $fige) {
            self::assertContains($fige, $champs, sprintf(
                'Champ figé « %s » absent du mapping : la protection ne porte plus sur rien.',
                $fige,
            ));
        }
    }

    /** Le point de vente est figé — la régression du 26/08, transformée en contrôle permanent. */
    public function testLePointDeVenteEstFige(): void
    {
        self::assertContains('pointDeVente', $this->champsFiges(), 'La chaîne NF525 est chaînée par point de vente : le déplacer sur une vente scellée la ferait changer de chaîne.');
        self::assertContains('session', $this->champsFiges());
    }

    /** @return list<string> */
    private function champsFiges(): array
    {
        $constante = (new \ReflectionClass(InalterabiliteListener::class))->getConstant('CHAMPS_VENTE_FIGES');
        self::assertIsArray($constante);

        /** @var list<string> $constante */
        return $constante;
    }
}
