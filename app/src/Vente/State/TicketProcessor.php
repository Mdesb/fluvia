<?php

declare(strict_types=1);

namespace App\Vente\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Vente\Entity\Vente;
use App\Vente\Service\LecteurCorps;
use App\Vente\Service\PanierCalculateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Ticket (POST /ventes/{id}/ticket, CA-11). Impression automatique au-dessus du seuil du point de
 * vente ; en dessous, impression à la demande + renvoi e-mail/SMS proposé au client rattaché. Le
 * duplicata et le renvoi sont tracés dans la réponse. Corps :
 *   { "mode": "imprimer|renvoyer|duplicata", "canal"?: "email|sms" }
 *
 * **Cette réponse ne portait aucune ligne** — ni libellé, ni quantité, ni montant — alors que
 * l'opération accepte `mode: "duplicata"`. Conséquence relevée par `claude-H` en construisant l'écran
 * de caisse : **le ticket n'existait que dans l'onglet du caissier.** La page fermée, le document
 * n'était plus reconstituable, et elle a refusé de proposer un bouton de réimpression plutôt que de
 * promettre un duplicata vide — un bouton qui rend un document vide fait croire que le document
 * existe, et le caissier cesse de chercher ailleurs.
 *
 * **Ce n'était pas un défaut d'écran.** Tout ce module repose sur l'idée qu'une vente validée est
 * *probante* : c'est l'argument de D45 contre la modification d'un règlement, et celui qui a imposé le
 * point de vente dédié en D44-bis plutôt qu'une exemption de scellement. Un justificatif qui n'existe
 * que dans un onglet ouvert n'est probant pour personne. Nous avions une chaîne d'empreintes
 * irréprochable **qui scellait des documents qu'on ne savait pas rééditer** — et tout avait l'air de
 * fonctionner, ce qui est le pire endroit où se tromper.
 *
 * @implements ProcessorInterface<Vente, JsonResponse>
 */
final class TicketProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly PanierCalculateur $calc,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        \assert($data instanceof Vente);

        $corps = $this->lecteur->corps();
        $mode = \is_string($corps['mode'] ?? null) ? $corps['mode'] : 'imprimer';

        // D44-bis — porté par la vente : une vente directe n'a pas de session d'où le déduire, et
        // lisait donc un seuil de 0 € qui la déclarait systématiquement au-dessus du seuil.
        $seuil = $this->calc->centimes($data->getPointDeVente()?->getSeuilImpression() ?? '0.00');
        $auDessusSeuil = $this->calc->centimes($data->getTotal()) >= $seuil;

        $duplicata = false;
        if ($mode === 'imprimer' || $mode === 'duplicata') {
            $duplicata = $data->isImprime();
            $data->setImprime(true);
            $this->em->flush();
        }

        $renvoiPropose = !$auDessusSeuil && $data->getClient() !== null;
        $renvoye = $mode === 'renvoyer' && $data->getClient() !== null;

        return new JsonResponse([
            'vente' => (string) $data->getId(),
            'numero' => $data->getNumero(),
            'date' => $data->getDate()->format(\DATE_ATOM),
            'lignes' => $this->lignes($data),
            'total' => $data->getTotal(),
            'totalRemises' => $data->getTotalRemises(),
            'mode' => $mode,
            'imprime' => $data->isImprime(),
            'impressionAutomatique' => $auDessusSeuil,
            'duplicata' => $duplicata,
            'renvoiPropose' => $renvoiPropose,
            'renvoye' => $renvoye,
            'canal' => $renvoye ? ($corps['canal'] ?? 'email') : null,
        ], JsonResponse::HTTP_OK);
    }

    /**
     * Le contenu du ticket, **relu de la vente et de rien d'autre**.
     *
     * Les libellés viennent de la ligne, pas du catalogue : c'est ce qui rend un duplicata fidèle six
     * mois plus tard, quand le produit a changé de nom ou n'existe plus. Voir
     * `LigneVente::$libelleProduit`.
     *
     * @return list<array<string, mixed>>
     */
    private function lignes(Vente $vente): array
    {
        $lignes = [];
        foreach ($vente->getLignes() as $ligne) {
            $lignes[] = [
                'id' => (string) $ligne->getId(),
                'libelle' => $ligne->getLibelleProduit(),
                'tarif' => $ligne->getLibelleTypeTarif(),
                'quantite' => $ligne->getQuantite(),
                'prixUnitaire' => $ligne->getPrixUnitaire(),
                'impactOptionsUnitaire' => $ligne->getImpactOptionsUnitaire(),
                'remiseLigne' => $ligne->getRemiseLigne(),
                'remiseType' => $ligne->getRemiseType()?->value,
                'montantLigne' => $ligne->getMontantLigne(),
                'optionsSelectionnees' => $ligne->getOptionsSelectionnees(),
                'promotionsAppliquees' => $ligne->getPromotionsAppliquees(),
            ];
        }

        return $lignes;
    }
}
