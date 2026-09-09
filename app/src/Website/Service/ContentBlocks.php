<?php

declare(strict_types=1);

namespace App\Website\Service;

use App\Website\Entity\ContentBlock;
use App\Website\Enum\BlockType;
use App\Website\Exception\UnknownBlockException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Lire et écrire les blocs de contenu de la page d'accueil (ED-10).
 *
 * ⚠ **UNE CLÉ NON DÉCLARÉE EST REFUSÉE.** {@see SiteBlocks} dit de quoi la page est faite ; accepter
 * une clé inconnue rangerait en base un contenu que **rien ne rend** — écrit, enregistré, invisible.
 * Le rédacteur croirait avoir publié. Le refus est bruyant, l'oubli silencieux ne l'est pas.
 *
 * ⚠ **ET LA FORME EST IMPOSÉE PAR LE TYPE DÉCLARÉ.** Un bloc « quatre cartes » qui recevrait une
 * chaîne casserait le gabarit à la première visite — c'est-à-dire après l'enregistrement, sur la
 * page publique, et pas devant celui qui a écrit. On valide donc à l'écriture, là où quelqu'un
 * regarde encore.
 */
final readonly class ContentBlocks
{
    public function __construct(
        private EntityManagerInterface $em,
        private BodySanitizer $assainisseur,
        private TradeReference $trades,
    ) {
    }

    /**
     * Les metiers a declarer : ceux du referentiel s'il en porte, sinon ceux du repli.
     *
     * ⚠ **MEME REGLE TOUT-OU-RIEN QUE `MetierCatalog`**, et pour la meme raison : une liste
     * melangee rendrait une base a moitie semee indiscernable d'une base saine.
     *
     * @return list<array{code: string, nom: string}>
     */
    private function metiers(): array
    {
        $metiers = [];

        foreach ($this->trades->published() as $ligne) {
            $metiers[] = ['code' => $ligne->getCode(), 'nom' => $ligne->getName()];
        }

        return $metiers;
    }

    /**
     * Les valeurs rangées en base, par clé.
     *
     * ⚠ **Aucun repli sur les valeurs de `SiteBlocks::all()`.** Elles ne servent qu'au peuplement
     * initial. Si le gabarit s'y repliait, la page afficherait un texte que l'écran d'administration
     * montre vide : deux vérités, et personne pour dire laquelle est servie.
     *
     * @return array<string, array<int|string, mixed>>
     */
    public function valeurs(): array
    {
        $valeurs = [];

        foreach ($this->em->getRepository(ContentBlock::class)->findAll() as $bloc) {
            $valeurs[$bloc->getKey()] = $bloc->getValue();
        }

        return $valeurs;
    }

    /**
     * Ce que l'écran d'administration affiche : la liste déclarée, chacune avec sa valeur ou `null`.
     *
     * C'est ici que se voit un bloc **jamais rempli** — il est dans la liste, sa valeur est nulle.
     * Une lecture qui partirait de la table ne l'aurait pas montré du tout.
     *
     * @return list<array{key: string, type: string, label: string, help: string, groupe: string, value: array<int|string, mixed>|null}>
     */
    public function pourLAdministration(): array
    {
        $valeurs = $this->valeurs();
        $lignes = [];

        foreach (SiteBlocks::all($this->metiers()) as $declare) {
            $lignes[] = [
                'key' => $declare['key'],
                'type' => $declare['type']->value,
                'label' => $declare['label'],
                'help' => $declare['help'],
                'groupe' => $declare['groupe'],
                'value' => $valeurs[$declare['key']] ?? null,
            ];
        }

        return $lignes;
    }

    /**
     * @param array<int|string, mixed> $valeur
     *
     * @throws UnknownBlockException si la clé n'est déclarée nulle part
     */
    public function enregistrer(string $cle, array $valeur, \DateTimeImmutable $instant): ContentBlock
    {
        $type = SiteBlocks::typeOf($cle, $this->metiers());

        if (null === $type) {
            throw new UnknownBlockException(sprintf(
                'Le bloc « %s » n\'est déclaré par aucun gabarit : ce qu\'on y écrirait ne serait rendu nulle part.',
                $cle,
            ));
        }

        $bloc = $this->em->getRepository(ContentBlock::class)->find($cle) ?? new ContentBlock($cle);
        $bloc->setValue($this->normaliser($type, $valeur), $instant);

        $this->em->persist($bloc);
        $this->em->flush();

        return $bloc;
    }

    /**
     * Ramène une valeur reçue à la forme exacte qu'attend le gabarit.
     *
     * On **normalise** plutôt qu'on ne refuse, sur ce qui se rattrape sans ambiguïté : une carte sans
     * texte est une carte au texte vide, pas une erreur à corriger avant d'enregistrer. Ce qui ne se
     * rattrape pas — une liste là où le gabarit attend une phrase — donne une valeur vide, visible
     * comme telle dans l'écran, plutôt qu'une page cassée.
     *
     * @param array<int|string, mixed> $valeur
     *
     * @return array<int|string, mixed>
     */
    private function normaliser(BlockType $type, array $valeur): array
    {
        return match ($type) {
            BlockType::Line, BlockType::Paragraph => ['text' => \is_string($valeur['text'] ?? null) ? trim($valeur['text']) : ''],

            // Assaini À L'ÉCRITURE, comme le corps d'un article : ce que porte la colonne est déjà ce
            // qui peut être servi, et le gabarit n'a rien à se rappeler.
            BlockType::Rich => ['html' => $this->assainisseur->sanitize(\is_string($valeur['html'] ?? null) ? $valeur['html'] : '')],

            BlockType::Items => ['items' => array_values(array_filter(
                array_map(
                    static fn (mixed $item): string => \is_string($item) ? trim($item) : '',
                    \is_array($valeur['items'] ?? null) ? $valeur['items'] : [],
                ),
                static fn (string $item): bool => '' !== $item,
            ))],

            BlockType::Cards => ['items' => array_values(array_filter(
                array_map(
                    static function (mixed $carte): array {
                        $carte = \is_array($carte) ? $carte : [];

                        return [
                            'title' => \is_string($carte['title'] ?? null) ? trim($carte['title']) : '',
                            'text' => \is_string($carte['text'] ?? null) ? trim($carte['text']) : '',
                        ];
                    },
                    \is_array($valeur['items'] ?? null) ? $valeur['items'] : [],
                ),
                // Une carte entièrement vide n'est pas une carte : la garder afficherait un cadre
                // vide sur la page d'accueil, ce que personne ne remarque en la relisant à l'écran.
                static fn (array $carte): bool => '' !== $carte['title'] || '' !== $carte['text'],
            ))],
        };
    }
}
