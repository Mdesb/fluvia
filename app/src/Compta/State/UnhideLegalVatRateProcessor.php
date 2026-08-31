<?php

declare(strict_types=1);

namespace App\Compta\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Compta\ApiResource\VatRateCatalog;
use App\Compta\Entity\HiddenLegalVatRate;
use App\Compta\Entity\LegalVatRate;
use App\Compta\Entity\ProfilExploitant;
use App\Securite\Service\ContexteEtablissement;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * « L'OEIL » qui se rallume : demasquer un taux legal pour l'exploitant courant.
 *
 * ⚠ SYMETRIQUE DE `HideLegalVatRateProcessor`, ET C'EST CE QUI EN FAIT UN MASQUE PLUTOT QU'UNE
 * SUPPRESSION. On demasque par le MEME identifiant que celui qui a servi a masquer : l'ecran n'a
 * aucune ligne de preference a retenir, donc rien a perdre entre deux chargements. Un oeil qu'on ne
 * peut rallumer qu'en sachant comment il s'est eteint n'est pas un oeil.
 *
 * ⚠ LE PROFIL COMPTABLE EST RESOLU ICI, JAMAIS RECU. Masquer un taux chez le voisin tiendrait
 * sinon en une requete, et le voisin ne le verrait pas : le symptome est une ABSENCE dans une
 * liste. Un cloisonnement qui se contourne par un champ du corps ne cloisonne rien.
 *
 * ⚠ ET MASQUER N'INVALIDE RIEN. Un taux masque qui sert deja a une facture continue de s'appliquer ;
 * le masque porte sur ce qu'on PROPOSE. Rien ici ne touche aux taux de l'exploitant, ni aux lignes
 * qui les portent — confondre les deux ferait disparaitre de l'historique des lignes valides, et ce
 * serait la forme la plus couteuse du defaut puisqu'elle passerait pour du rangement.
 *
 * @implements ProcessorInterface<mixed, VatRateCatalog>
 */
final class UnhideLegalVatRateProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly LecteurCorps $lecteur,
        private readonly EntityManagerInterface $em,
        private readonly ContexteEtablissement $contexte,
        private readonly VatRateCatalogProvider $catalogue,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): VatRateCatalog
    {
        $corps = $this->lecteur->corps();
        $brut = $corps['legalVatRateId'] ?? null;

        if (!\is_string($brut) || !Uuid::isValid($brut)) {
            throw new UnprocessableEntityHttpException('Identifiant de taux legal obligatoire.');
        }

        $etablissement = $this->contexte->etablissementActif();
        if (null === $etablissement) {
            throw new UnprocessableEntityHttpException('Etablissement actif requis (en-tete X-Etablissement).');
        }

        // @cloisonnement-verifie : 31/08/2026 — `LegalVatRate` ne PORTE aucun etablissement, et ne
        // peut donc pas etre compare a un perimetre : c'est un referentiel de faits de droit,
        // volontairement global (voir le docbloc de l'entite). Resoudre un taux legal depuis
        // l'entree client n'expose rien — le taux hongrois est public.
        //
        // ⚠ CE QUI EST SENSIBLE ICI N'EST PAS LE TAUX, C'EST LE PROFIL — et il n'est JAMAIS recu :
        // il est resolu quelques lignes plus bas depuis `etablissementActif()`, par le meme chemin
        // que `AccountingScopeExtension` emprunte pour filtrer les lectures du module. Un profil
        // accepte depuis le corps aurait permis d'ecrire chez le voisin, et le symptome aurait ete
        // une ABSENCE dans sa liste — invisible.
        $legal = $this->em->getRepository(LegalVatRate::class)->find(Uuid::fromString($brut));
        if (!$legal instanceof LegalVatRate) {
            throw new UnprocessableEntityHttpException('Ce taux legal n\'existe pas.');
        }

        $profil = $this->em->getRepository(ProfilExploitant::class)
            ->findOneBy(['etablissementPrincipal' => $etablissement]);

        if (!$profil instanceof ProfilExploitant) {
            throw new UnprocessableEntityHttpException(
                'Aucun profil comptable n\'est rattache a cet etablissement : un masquage s\'y rattache.'
            );
        }

        // Idempotent : demasquer un taux qui ne l'etait pas n'est pas une erreur. L'ecran peut
        // envoyer le geste sans savoir l'etat courant, ce qui evite la classe de defauts ou deux
        // onglets se contredisent.
        $existant = $this->em->getRepository(HiddenLegalVatRate::class)
            ->findOneBy(['profilExploitant' => $profil, 'legalVatRate' => $legal]);

        if ($existant instanceof HiddenLegalVatRate) {
            $this->em->remove($existant);
            $this->em->flush();
        }

        return $this->catalogue->provide($operation, $uriVariables, $context);
    }
}
