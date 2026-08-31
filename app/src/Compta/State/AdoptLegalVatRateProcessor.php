<?php

declare(strict_types=1);

namespace App\Compta\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Compta\ApiResource\VatRateCatalog;
use App\Compta\Entity\LegalVatRate;
use App\Compta\Entity\ProfilExploitant;
use App\Compta\Entity\TauxTva;
use App\Securite\Service\ContexteEtablissement;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * « REPRENDRE » UN TAUX LEGAL : le serveur cree le taux de l'exploitant a partir du referentiel.
 *
 * ⚠ POURQUOI C'EST LE SERVEUR QUI LE FAIT, ET PAS L'ECRAN.
 *
 * La premiere version laissait l'ecran composer le `POST /api/taux_tvas` : libelle, valeur, profil
 * comptable, et l'IRI du taux legal. Trois defauts en sont sortis, tous du meme genre — l'ecran
 * devait savoir des choses qui ne le regardent pas :
 *
 *   — le PROFIL COMPTABLE est obligatoire et non pose par le serveur sur `TauxTva`. L'ecran devait
 *     le choisir, et refusait de le faire quand il y en avait plusieurs — une fonctionnalite qui
 *     s'eteint selon la configuration du client ;
 *   — l'IRI du taux legal supposait une ressource exposee, alors que le referentiel n'en est
 *     volontairement pas une (voir `LegalVatRate`) ;
 *   — et rien n'empechait de reprendre deux fois le meme taux, ni d'en recopier la valeur de
 *     travers.
 *
 * Ici, l'ecran n'envoie qu'un identifiant. Le reste — profil, libelle, valeur, filiation — est lu a
 * la source, ce qui est le seul moyen que le taux repris soit exactement celui de la loi.
 *
 * @implements ProcessorInterface<mixed, VatRateCatalog>
 */
final class AdoptLegalVatRateProcessor implements ProcessorInterface
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
                'Aucun profil comptable n\'est rattache a cet etablissement : un taux doit s\'y rattacher.'
            );
        }

        // ⚠ IDEMPOTENT SUR LA FILIATION, PAS SUR LA VALEUR. Deux taux a 20 % peuvent etre deux
        // choses differentes — un normal francais, un normal autrichien. C'est l'origine qui dit
        // s'il s'agit du meme, et c'est elle qu'on interroge. Reprendre deux fois ne cree donc
        // rien : on rend le catalogue tel quel, et l'ecran affichera « deja repris ».
        $existant = $this->em->getRepository(TauxTva::class)
            ->findOneBy(['profilExploitant' => $profil, 'origineLegale' => $legal]);

        if (!$existant instanceof TauxTva) {
            $taux = (new TauxTva())
                ->setLibelle($legal->getLabel())
                ->setTaux($legal->getRate())
                ->setProfilExploitant($profil)
                ->setOrigineLegale($legal)
                ->setActif(true);

            $this->em->persist($taux);
            $this->em->flush();
        }

        // On rend le catalogue a jour plutot qu'un accuse de reception vide : l'ecran a besoin de
        // savoir ce qui est desormais repris, et une seconde requete pour l'apprendre laisserait une
        // fenetre ou l'ecran affiche l'inverse de ce qui vient de se passer.
        return $this->catalogue->provide($operation, $uriVariables, $context);
    }
}
