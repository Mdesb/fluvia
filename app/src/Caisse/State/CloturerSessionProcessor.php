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
use App\Compta\Service\RegieHandler;
use App\Securite\Entity\Utilisateur;
use App\Vente\Entity\Avoir;
use App\Vente\Entity\Vente;
use App\Vente\Enum\StatutVente;
use App\Vente\Enum\TypeOperationScellee;
use App\Vente\Nf525\OperationAScellerDto;
use App\Vente\Nf525\ScellementHandler;
use App\Vente\Service\LecteurCorps;
use App\Vente\Service\PanierCalculateur;
use App\Vente\Service\SettlementCoordinator;
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
        private readonly RegieHandler $regieHandler,
        private readonly SettlementCoordinator $reglements,
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
            // Ticket opposable (G-6, D122) : une carte à l'issue inconnue est peut-être débitée. Clore
            // laisserait une déclaration « accepté » écrire après le Z un règlement qu'il ne compte pas.
            $tentative = $vente->getStatut() === StatutVente::EnCours ? $this->reglements->holdingAttempt($vente) : null;
            if ($tentative !== null) {
                throw new UnprocessableEntityHttpException(sprintf(
                    'Clôture refusée : un règlement de %s € (%s) sur la vente n° %s attend son issue (%s). '
                    . 'Reprenez cette vente depuis l\'historique des ventes, puis déclarez ce qu\'affiche le terminal.',
                    $tentative['amount'],
                    $tentative['method'],
                    $vente->getNumero(),
                    $tentative['status']->label(),
                ));
            }
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

        $this->alimenterRegie($data, $pdv, $comptages, $fondReporte);

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

    /**
     * L'ARGENT COMPTÉ AU TIROIR PASSE DANS L'ENCAISSE DE LA RÉGIE.
     *
     * ── LE MAILLON QUI MANQUAIT, ET TOUT CE QU'IL PARALYSAIT ──────────────────────────────
     *
     * `RegieHandler::enregistrerEncaissement()` est la seule méthode qui incrémente
     * `RegieRecettes::soldeEncaisseCentimes`, et `soldeEncaisseCentimes` n'est pas dans le
     * groupe d'écriture de l'API. Relevé sur `app/src/` : AUCUN appelant en production.
     * L'encaisse partait de zéro et ne savait que descendre.
     *
     * En cascade : `depassePlafond()` ne pouvait jamais être vrai, `ClotureGuard` ne bloquait
     * jamais une clôture comptable pour ce motif, et l'écran de versement n'avait jamais rien
     * à verser. Les tests étaient verts parce qu'ils appellent le handler eux-mêmes.
     *
     * ── ⚠ LE MONTANT NUL N'EST PAS UN CAS D'ERREUR, ET C'EST VITAL ────────────────────────
     *
     * `enregistrerEncaissement()` lève une 422 sur un montant `<= 0`. Appelée sans garde,
     * **toute session sans espèces — ou dont le fond reporté égale les espèces comptées —
     * ferait échouer la clôture de caisse**. Une caisse qu'on ne peut plus fermer le soir est
     * un incident d'exploitation bien plus grave que le défaut qu'on corrige ici.
     *
     * La garde est donc en amont, et elle ne signale rien : une session sans espèces n'a
     * simplement rien à remettre au régisseur.
     *
     * ── CE QUI EST PRIS : LE COMPTÉ, PAS LE THÉORIQUE ─────────────────────────────────────
     *
     * `$comptages` porte, pour chaque moyen, le montant RÉELLEMENT compté au tiroir (à défaut
     * de comptage, le théorique — c'est la règle du processor, pas la nôtre). On en retire le
     * fond reporté, qui reste dans la caisse pour la session suivante. La différence est ce
     * que le régisseur emporte, donc ce dont il devient comptable.
     *
     * Prendre le théorique ferait porter à l'encaisse un montant qu'un écart de caisse rend
     * faux le soir même, et l'écart est précisément ce que la Z sert à constater.
     *
     * ── AUCUNE RÉGIE : LE CAS NORMAL, PAS UNE OMISSION ────────────────────────────────────
     *
     * Un exploitant privé ou un délégataire n'en a pas. On ne cherche donc pas à savoir si
     * l'établissement en possède une ailleurs : ce serait une requête à chaque clôture de
     * chaque guichet du produit, pour une ligne d'audit que personne ne lit. Un guichet
     * oublié se voit là où quelqu'un regarde déjà — la fiche de la régie, dans les
     * paramètres, liste les guichets qui l'alimentent et dit quand il n'y en a aucun.
     *
     * ⚠ LE CONTENU SCELLÉ NE CHANGE PAS. `etatDeRegie` garde exactement sa forme : le sceau
     * couvre la caisse telle qu'elle a été comptée, et ce mouvement en est une conséquence.
     * Changer la charge scellée changerait ce que valent les sceaux déjà posés.
     *
     * @param list<array<string, string>> $comptages
     */
    private function alimenterRegie(
        SessionCaisse $session,
        ?\App\Caisse\Entity\PointDeVente $pdv,
        array $comptages,
        string $fondReporte,
    ): void {
        $regie = $pdv?->getRegie();
        if ($regie === null) {
            return;
        }

        $especesComptees = 0;
        foreach ($comptages as $ligne) {
            if (($ligne['moyen'] ?? null) === 'especes') {
                $especesComptees = $this->calc->centimes($ligne['compte']);
                break;
            }
        }

        $montant = $especesComptees - $this->calc->centimes($fondReporte);
        if ($montant <= 0) {
            return;
        }

        $this->regieHandler->enregistrerEncaissement($regie, $montant);

        // Un solde de régie qui bouge sans trace est inacceptable pour de l'argent public. On
        // emprunte le mécanisme que ce processor emploie déjà pour l'alerte d'écart.
        $auteur = $this->security->getUser();
        $this->journal->enregistrer(
            'compta.regie_encaissement',
            'RegieRecettes',
            (string) $regie->getId(),
            $session->getEtablissement()?->getId(),
            $auteur instanceof Utilisateur ? $auteur->getEmail() : null,
        );
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
