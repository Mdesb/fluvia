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
    // ⚠ `PAYS_PAR_DEFAUT` A ETE RETIREE LE 02/09. Elle faisait passer une SUPPOSITION pour un
    // reglage : un etablissement sans pays connu recevait les taux francais. Le pays est desormais
    // une donnee de l'etablissement, avec son propre defaut en base — ou il se voit et se corrige.

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

        // ⚠ LE PAYS VIENT DE L'ETABLISSEMENT, PLUS D'UNE CONSTANTE.
        //
        // Cette methode retombait sur `PAYS_PAR_DEFAUT = 'FR'` quand la requete ne disait rien. Un
        // etablissement espagnol se voyait donc proposer les taux FRANCAIS, avec leurs libelles et
        // leurs dates de validite — et rien ne le signalait, puisque la reponse etait pleine.
        //
        // Un mauvais taux de TVA ne se decouvre pas a l'ecran : il se decouvre au controle fiscal.
        //
        // Le parametre `?country=` reste accepte : il sert a EXPLORER le catalogue d'un autre pays
        // (« combien vaut le taux reduit en Belgique ? ») sans changer d'etablissement. Ce qui change
        // est le defaut : ce n'est plus une constante, c'est une donnee.
        $requete = $this->requetes->getCurrentRequest();
        $demande = $requete?->query->get('country');
        $pays = strtoupper((string) ($demande ?? $etablissement->getPays()));

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

        // ⚠ LE TERRITOIRE SUIT LA MEME REGLE QUE LE PAYS : il vient de l'etablissement, et le
        // parametre ne sert qu'a EXPLORER. Un exploitant guadeloupeen est en `FR` — son pays ne dit
        // donc rien de son regime — et c'est ce champ qui porte la difference entre 20 % et 8,5 %.
        $territoireDemande = $requete?->query->get('territory');
        $territoire = strtoupper(trim((string) ($territoireDemande ?? $etablissement->getFiscalTerritory())));

        // On refuse une forme inattendue plutot que de la normaliser en silence : « canaries » ou
        // « IC ! » rendraient le catalogue de droit commun, c'est-a-dire les taux de la peninsule
        // sur une facture des Canaries, sans qu'aucun message ne le dise.
        if (1 !== preg_match('/^[A-Z0-9-]{0,20}$/', $territoire)) {
            throw new UnprocessableEntityHttpException(
                'Le territoire fiscal s ecrit en majuscules, chiffres et tirets (IC, CORSE, DOM) : « '
                . $territoire . ' » n\'en est pas un.'
            );
        }

        $vue = new VatRateCatalog();
        $vue->country = $pays;
        $vue->territory = $territoire;

        // La date est « aujourd'hui » ici, et c'est le seul endroit ou ce choix se fait. Le depot,
        // lui, prend une date en parametre : expliquer une facture de 2025 demande les taux de 2025,
        // et un referentiel qui ne saurait rendre que l'etat du jour serait faux sur tout le passe
        // des le premier decret.
        $aujourdhui = new \DateTimeImmutable('today');

        foreach ($this->taux->inForce($pays, $aujourdhui, $territoire) as $t) {
            $vue->rates[] = [
                'id' => (string) $t->getId(),
                'rate' => $t->getRate(),
                'category' => $t->getCategory()->value,
                // Le territoire de la LIGNE, qui n'est pas forcement celui demande : un taux de
                // droit commun retenu faute de taux territorial rend `''`, et l'ecran peut alors
                // dire « taux national » plutot que de laisser croire a un taux local.
                'territory' => $t->getTerritory(),
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
