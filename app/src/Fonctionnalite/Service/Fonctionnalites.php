<?php

declare(strict_types=1);

namespace App\Fonctionnalite\Service;

use App\Fonctionnalite\Config\PresetVerticale;
use App\Fonctionnalite\Entity\FonctionnaliteEtablissement;
use App\Fonctionnalite\Enum\Metier;
use App\Organisation\Entity\Etablissement;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Point d'accès unique aux capacités actives d'un établissement (`estActive`/`actives`) — à consommer
 * par les modules/gardes verticaux (spec « Profil de fonctionnalités par établissement »). Porte aussi
 * l'écriture (`definir`/`appliquerPreset`), utilisée par les Processors API et les fixtures.
 */
final class Fonctionnalites
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CatalogueCapacites $catalogue,
    ) {
    }

    public function estActive(Etablissement $etablissement, string $code): bool
    {
        $entite = $this->trouve($etablissement, $code);

        return $entite !== null && $entite->isActive();
    }

    /** @return list<string> codes des capacités actives, triés */
    public function actives(Etablissement $etablissement): array
    {
        /** @var list<FonctionnaliteEtablissement> $lignes */
        $lignes = $this->em->getRepository(FonctionnaliteEtablissement::class)->findBy([
            'etablissement' => $etablissement,
            'active' => true,
        ]);
        $codes = array_map(static fn (FonctionnaliteEtablissement $f): string => $f->getCapaciteCode(), $lignes);
        sort($codes);

        return array_values($codes);
    }

    /**
     * État complet (toutes les capacités du catalogue, actives ou non) pour un établissement — sert le
     * `GET /etablissements/{id}/fonctionnalites`. Les capacités jamais paramétrées sont représentées par
     * une entité transitoire (non persistée, `active=false`, `parametres=null`).
     *
     * @return list<FonctionnaliteEtablissement>
     */
    public function etat(Etablissement $etablissement): array
    {
        /** @var list<FonctionnaliteEtablissement> $lignes */
        $lignes = $this->em->getRepository(FonctionnaliteEtablissement::class)->findBy(['etablissement' => $etablissement]);
        $parCode = [];
        foreach ($lignes as $ligne) {
            $parCode[$ligne->getCapaciteCode()] = $ligne;
        }

        $resultat = [];
        foreach ($this->catalogue->toutes() as $descripteur) {
            $resultat[] = $parCode[$descripteur->code] ?? $this->transitoire($etablissement, $descripteur->code);
        }

        return $resultat;
    }

    /**
     * Active/désactive/paramètre une capacité (création si absente). Lève `\InvalidArgumentException`
     * si le code n'appartient pas au catalogue.
     *
     * @param array<string, mixed>|null $parametres
     */
    public function definir(Etablissement $etablissement, string $code, bool $active, ?array $parametres): FonctionnaliteEtablissement
    {
        if (!$this->catalogue->existe($code)) {
            throw new \InvalidArgumentException(sprintf('Capacité inconnue du catalogue : « %s ».', $code));
        }

        $entite = $this->trouve($etablissement, $code)
            ?? (new FonctionnaliteEtablissement())->setEtablissement($etablissement)->setCapaciteCode($code);
        $entite->setActive($active)->setParametres($parametres)->toucherModifieLe();

        $this->em->persist($entite);
        $this->em->flush();

        return $entite;
    }

    /**
     * Active le jeu de capacités du preset (additif : n'en désactive aucune autre, cf. §« Preset » de la
     * spec). Les paramètres déjà saisis pour une capacité déjà connue de l'établissement sont conservés.
     *
     * @return list<string> codes activés par le preset
     */
    public function appliquerPreset(Etablissement $etablissement, Metier $metier): array
    {
        $codes = PresetVerticale::capacites($metier);
        foreach ($codes as $code) {
            $existante = $this->trouve($etablissement, $code);
            $this->definir($etablissement, $code, true, $existante?->getParametres());
        }

        return $codes;
    }

    private function trouve(Etablissement $etablissement, string $code): ?FonctionnaliteEtablissement
    {
        /** @var FonctionnaliteEtablissement|null $entite */
        $entite = $this->em->getRepository(FonctionnaliteEtablissement::class)->findOneBy([
            'etablissement' => $etablissement,
            'capaciteCode' => $code,
        ]);

        return $entite;
    }

    private function transitoire(Etablissement $etablissement, string $code): FonctionnaliteEtablissement
    {
        return (new FonctionnaliteEtablissement())->setEtablissement($etablissement)->setCapaciteCode($code)->setActive(false);
    }
}
