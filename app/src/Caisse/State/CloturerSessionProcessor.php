<?php

declare(strict_types=1);

namespace App\Caisse\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Audit\Service\JournalAudit;
use App\Caisse\Entity\AlerteEcartCaisse;
use App\Caisse\Entity\ClotureZ;
use App\Caisse\Entity\MouvementCaisse;
use App\Caisse\Entity\SessionCaisse;
use App\Caisse\Enum\EtatCaisse;
use App\Caisse\Enum\EtatSession;
use App\Caisse\Enum\TypeMouvement;
use App\Securite\Entity\Utilisateur;
use App\Vente\Entity\Avoir;
use App\Vente\Entity\Vente;
use App\Vente\Enum\StatutVente;
use App\Vente\Enum\TypeOperationScellee;
use App\Vente\Nf525\OperationAScellerDto;
use App\Vente\Nf525\ScellementHandler;
use App\Vente\Service\LecteurCorps;
use App\Vente\Service\PanierCalculateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Clôture Z d'une session (RG-M2-06 / CA-14). Totalise ventes, moyens de paiement et remboursements,
 * calcule l'écart théorique vs compté, produit un état de régie archivé/ré-imprimable, scelle la
 * clôture (NF525) et fige définitivement la session (irréversible). Refusée si des paiements sont
 * incohérents (vente en cours avec règlements partiels). Le fond est reporté/repris selon le
 * paramétrage transmis. Corps attendu :
 *   { "comptages": [{"moyen":"especes","compte":"…"}], "versement":"…", "fondReporte":"…" }
 *
 * Réponse graduée (US-CAISSEZ-01/03, RG-CAISSEZ-01/02/09) : le calcul (théorique, comptages, écart,
 * `etatDeRegie`) est **toujours** effectué et persisté dans `ClotureZ`, quel que soit le rôle de
 * l'auteur. Seule la réponse HTTP diffère selon `caisse.voir_z` — complète pour un porteur du droit
 * (comportement inchangé), accusé minimal sinon (comptage aveugle, RG-CAISSEZ-02). Une alerte d'écart
 * (`AlerteEcartCaisse`) est créée dans la même transaction si `|ecartTotal| > toleranceEcartCaisse` du
 * point de vente (RG-CAISSEZ-05/06/07).
 *
 * @implements ProcessorInterface<SessionCaisse, JsonResponse>
 */
final class CloturerSessionProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly PanierCalculateur $calc,
        private readonly ScellementHandler $scellement,
        private readonly Security $security,
        private readonly JournalAudit $journal,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        \assert($data instanceof SessionCaisse);
        if ($data->getEtat() === EtatSession::Close) {
            throw new ConflictHttpException('Session déjà close : clôture irréversible (RG-M2-06).');
        }

        $ventes = $this->em->getRepository(Vente::class)->findBy(['session' => $data]);

        // Refus si paiements incohérents : une vente en cours porte des règlements partiels.
        foreach ($ventes as $vente) {
            if ($vente->getStatut() === StatutVente::EnCours && !$vente->getPaiements()->isEmpty()) {
                throw new UnprocessableEntityHttpException('Clôture refusée : des paiements sont incohérents (vente en cours réglée partiellement).');
            }
        }

        $theorique = [];
        $totalVentesCentimes = 0;
        foreach ($ventes as $vente) {
            if (!\in_array($vente->getStatut(), [StatutVente::Validee, StatutVente::AvoirEmis], true)) {
                continue;
            }
            $totalVentesCentimes += $this->calc->centimes($vente->getTotal());
            foreach ($vente->getPaiements() as $paiement) {
                $impute = $this->calc->centimes($paiement->getMontant()) - $this->calc->centimes($paiement->getRendu());
                $theorique[$paiement->getMoyenCode()] = ($theorique[$paiement->getMoyenCode()] ?? 0) + $impute;
            }
        }

        // Espèces : ajouter le fond et les mouvements d'espèces de la session.
        $fondCentimes = $this->calc->centimes($data->getFondDeCaisse());
        $theorique['especes'] = ($theorique['especes'] ?? 0) + $fondCentimes + $this->mouvementsEspeces($data);

        // Remboursements / avoirs de la session.
        $totalRemboursements = 0;
        foreach ($this->em->getRepository(Avoir::class)->findAll() as $avoir) {
            if ($avoir->getVenteOrigine()?->getSession()?->getId()->equals($data->getId())) {
                $totalRemboursements += $this->calc->centimes($avoir->getMontant());
            }
        }

        $corps = $this->lecteur->corps();
        $comptesSaisis = [];
        foreach ($corps['comptages'] ?? [] as $ligne) {
            if (\is_array($ligne) && isset($ligne['moyen'])) {
                $comptesSaisis[(string) $ligne['moyen']] = $this->calc->centimes(number_format((float) ($ligne['compte'] ?? 0), 2, '.', ''));
            }
        }

        // Réponse graduée (RG-CAISSEZ-01/02) : calculé une fois, réutilisé pour la garde ci-dessous
        // et pour le branchement de la réponse HTTP en fin de méthode.
        $peutVoirZ = $this->security->isGranted('PERM', 'caisse.voir_z');

        // Comptage aveugle (RG-CAISSEZ-04, CA-10) : un auteur sans `caisse.voir_z` doit compter au
        // moins les espèces pour que la clôture soit significative. Un porteur de `caisse.voir_z`
        // conserve le comportement actuel (comptage manquant = réputé conforme).
        if (!$peutVoirZ && !\array_key_exists('especes', $comptesSaisis)) {
            throw new UnprocessableEntityHttpException('Comptage espèces requis (comptage aveugle).');
        }

        $comptages = [];
        $ecartTotal = 0;
        foreach (array_unique([...array_keys($theorique), ...array_keys($comptesSaisis)]) as $moyen) {
            $theo = $theorique[$moyen] ?? 0;
            $compte = $comptesSaisis[$moyen] ?? $theo; // à défaut de comptage : réputé conforme.
            $ecart = $compte - $theo;
            $ecartTotal += $ecart;
            $comptages[] = [
                'moyen' => $moyen,
                'theorique' => $this->calc->decimal($theo),
                'compte' => $this->calc->decimal($compte),
                'ecart' => $this->calc->decimal($ecart),
            ];
        }

        $versement = isset($corps['versement']) ? number_format((float) $corps['versement'], 2, '.', '') : '0.00';
        $fondReporte = isset($corps['fondReporte']) ? number_format((float) $corps['fondReporte'], 2, '.', '') : $data->getFondDeCaisse();

        $cloture = (new ClotureZ())
            ->setSession($data)
            ->setComptages($comptages)
            ->setTotalVentes($this->calc->decimal($totalVentesCentimes))
            ->setTotalRemboursements($this->calc->decimal($totalRemboursements))
            ->setVersement($versement)
            ->setFondReporte($fondReporte)
            ->setEcartTotal($this->calc->decimal($ecartTotal));
        $cloture->setEtatDeRegie([
            'session' => $data->getNumero(),
            'pointDeVente' => $data->getPointDeVente()?->getLibelle(),
            'ouvertureLe' => $data->getOuvertureLe()->format(\DateTimeInterface::ATOM),
            'clotureLe' => $cloture->getHorodatage()->format(\DateTimeInterface::ATOM),
            'fondDeCaisse' => $data->getFondDeCaisse(),
            'totalVentes' => $cloture->getTotalVentes(),
            'totalRemboursements' => $cloture->getTotalRemboursements(),
            'comptages' => $comptages,
            'ecartTotal' => $cloture->getEcartTotal(),
            'versement' => $versement,
            'fondReporte' => $fondReporte,
        ]);
        $this->em->persist($cloture);

        // Scellement NF525 de la clôture.
        $pdv = $data->getPointDeVente();
        if ($pdv !== null) {
            $this->scellement->sceller(new OperationAScellerDto(
                $pdv,
                TypeOperationScellee::ClotureZ,
                'ClotureZ',
                $cloture->getId(),
                $cloture->getEtatDeRegie(),
            ));
        }

        // Alerte d'écart (RG-CAISSEZ-05/06/07) : créée dans la même transaction, avant le flush final,
        // si l'écart dépasse la tolérance du point de vente. Aucune alerte si la session n'a pas de
        // point de vente (cas défensif, ne devrait jamais se produire en usage réel).
        if ($pdv !== null) {
            $ecartAbsCentimes = abs($this->calc->centimes($cloture->getEcartTotal()));
            $toleranceCentimes = $this->calc->centimes($pdv->getToleranceEcartCaisse());
            if ($ecartAbsCentimes > $toleranceCentimes) {
                $auteur = $this->security->getUser();
                \assert($auteur instanceof Utilisateur);

                $alerte = (new AlerteEcartCaisse())
                    ->setCloture($cloture)
                    ->setSession($data)
                    ->setEtablissement($data->getEtablissement())
                    ->setEcartMontant($cloture->getEcartTotal())
                    ->setToleranceAppliquee($pdv->getToleranceEcartCaisse())
                    ->setAuteurCloture($auteur)
                    ->setHorodatage($cloture->getHorodatage());
                $this->em->persist($alerte);

                $this->journal->enregistrer(
                    'caisse.alerte_ecart',
                    'AlerteEcartCaisse',
                    (string) $alerte->getId(),
                    $data->getEtablissement()?->getId(),
                    $auteur->getEmail(),
                );
            }
        }

        // Fige la session (irréversible) et sécurise la caisse.
        $data->fermer();
        $data->getCaisse()?->setEtat(EtatCaisse::Securisee);

        $this->em->flush();

        // Branchement de la réponse (RG-CAISSEZ-01/02/09) : le calcul ci-dessus est strictement
        // inchangé et toujours persisté, seule la charge utile HTTP diffère selon `$peutVoirZ`.
        if ($peutVoirZ) {
            return new JsonResponse([
                'cloture' => (string) $cloture->getId(),
                'session' => $data->getNumero(),
                'etatSession' => $data->getEtat()->value,
                'totalVentes' => $cloture->getTotalVentes(),
                'totalRemboursements' => $cloture->getTotalRemboursements(),
                'comptages' => $comptages,
                'ecartTotal' => $cloture->getEcartTotal(),
                'versement' => $versement,
                'fondReporte' => $fondReporte,
                'etatDeRegie' => $cloture->getEtatDeRegie(),
            ], JsonResponse::HTTP_OK);
        }

        // Comptage aveugle (RG-CAISSEZ-02) : accusé minimal, aucun champ monétaire dans la charge utile.
        return new JsonResponse([
            'cloture' => (string) $cloture->getId(),
            'session' => $data->getNumero(),
            'etatSession' => $data->getEtat()->value,
            'horodatageCloture' => $cloture->getHorodatage()->format(\DateTimeInterface::ATOM),
            'message' => 'Caisse fermée.',
        ], JsonResponse::HTTP_OK);
    }

    private function mouvementsEspeces(SessionCaisse $session): int
    {
        $solde = 0;
        foreach ($this->em->getRepository(MouvementCaisse::class)->findBy(['session' => $session]) as $mouvement) {
            $montant = $this->calc->centimes($mouvement->getMontant());
            $solde += match ($mouvement->getType()) {
                TypeMouvement::Entree, TypeMouvement::Apport => $montant,
                TypeMouvement::Sortie, TypeMouvement::Retrait, TypeMouvement::Versement => -$montant,
            };
        }

        return $solde;
    }
}
