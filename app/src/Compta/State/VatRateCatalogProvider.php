<?php

declare(strict_types=1);

namespace App\Compta\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Compta\ApiResource\VatRateCatalog;
use App\Compta\Entity\HiddenLegalVatRate;
use App\Compta\Entity\ProfilExploitant;
use App\Compta\Repository\LegalVatRateRepository;
use App\Securite\Service\ContexteEtablissement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Sert les taux legaux applicables, et les masquages de l'exploitant courant.
 *
 * @implements ProviderInterface<VatRateCatalog>
 */
final class VatRateCatalogProvider implements ProviderInterface
{
    private const PAYS_PAR_DEFAUT = 'FR';

    public function __construct(
        private readonly LegalVatRateRepository $taux,
        private readonly EntityManagerInterface $em,
        private readonly ContexteEtablissement $contexte,
        private readonly RequestStack $requetes,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): VatRateCatalog
    {
        $etablissement = $this->contexte->etablissementActif();
        if (null === $etablissement) {
            throw new UnprocessableEntityHttpException('Etablissement actif requis (en-tete X-Etablissement).');
        }

        $requete = $this->requetes->getCurrentRequest();
        $pays = strtoupper((string) ($requete?->query->get('country') ?? self::PAYS_PAR_DEFAUT));

        // ⚠ ON N'ACCEPTE QU'UN CODE ISO A DEUX LETTRES, ET ON REFUSE LE RESTE PLUTOT QUE DE LE
        // NORMALISER.
        //
        // « fr », « France », « FRA » rendraient tous une liste vide, et une liste vide se lit
        // « aucun taux dans ce pays » — un mensonge tranquille. Un 422 dit ce qui ne va pas ; un
        // tableau vide laisse chercher ailleurs.
        if (1 !== preg_match('/^[A-Z]{2}$/', $pays)) {
            throw new UnprocessableEntityHttpException(
                'Le pays se donne en code ISO a deux lettres (FR, BE, DE) : « ' . $pays . ' » n\'en est pas un.'
            );
        }

        $vue = new VatRateCatalog();
        $vue->country = $pays;

        // La date est « aujourd'hui » ici, et c'est le seul endroit ou ce choix se fait. Le depot,
        // lui, prend une date en parametre : expliquer une facture de 2025 demande les taux de 2025,
        // et un referentiel qui ne saurait rendre que l'etat du jour serait faux sur tout le passe
        // des le premier decret.
        $aujourdhui = new \DateTimeImmutable('today');

        foreach ($this->taux->inForce($pays, $aujourdhui) as $t) {
            $vue->rates[] = [
                'id' => (string) $t->getId(),
                'rate' => $t->getRate(),
                'category' => $t->getCategory()->value,
                'label' => $t->getLabel(),
                'validFrom' => $t->getValidFrom()->format('Y-m-d'),
                'validUntil' => $t->getValidUntil()?->format('Y-m-d'),
                'source' => $t->getSource(),
            ];
        }

        // ⚠ LES MASQUAGES SONT LUS PAR LE PROFIL DE L'ETABLISSEMENT ACTIF, PAS PAR CELUI DU CORPS.
        //
        // Le meme chemin que `AccountingScopeExtension` emprunte pour filtrer les lectures du
        // module : `profilExploitant.etablissementPrincipal`. Resoudre autrement rendrait les
        // preferences du voisin — et comme le symptome est une ABSENCE dans une liste, personne ne
        // s'en apercevrait.
        $profil = $this->em->getRepository(ProfilExploitant::class)
            ->findOneBy(['etablissementPrincipal' => $etablissement]);

        if ($profil instanceof ProfilExploitant) {
            $masquages = $this->em->getRepository(HiddenLegalVatRate::class)
                ->findBy(['profilExploitant' => $profil]);

            foreach ($masquages as $m) {
                $cible = $m->getLegalVatRate();
                if (null !== $cible) {
                    $vue->hidden[] = (string) $cible->getId();
                }
            }
        }

        return $vue;
    }
}
