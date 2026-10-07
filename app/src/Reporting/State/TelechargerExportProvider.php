<?php

declare(strict_types=1);

namespace App\Reporting\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Reporting\Entity\Export;
use App\Reporting\Security\ExportDownloadAuthorizer;
use App\Reporting\Enum\NiveauEntite;
use App\Reporting\Security\PerimetreReportingResolver;
use App\Reporting\Service\StockageExportInterface;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * RÉCUPÉRER LE FICHIER D'UN EXPORT — la moitié qui manquait.
 *
 * ── CE QUI ÉTAIT CASSÉ ──────────────────────────────────────────────────────────────────────────
 *
 * `ExportManuelProcessor` générait le fichier, `StockageExportLocal::stocker()` l'écrivait sur
 * disque, `Export::cheminStockage` gardait sa référence — et **aucune route ne permettait de le
 * lire**. La Compta (`/compta/exports/{id}/telecharger`), le DMS et le SEPA en ont une ; le module
 * d'analyse n'en avait pas. On pouvait donc demander un export et ne jamais l'obtenir.
 *
 * `StockageExportLocal::recuperer()` existait déjà, complet, et personne ne l'appelait.
 *
 * ── ⚠ UN PROVIDER ÉCRIT À LA MAIN N'EST PAS CLOISONNÉ ───────────────────────────────────────────
 *
 * `PerimetreReportingExtension` s'accroche aux opérations Doctrine (`Get`/`GetCollection` servies
 * par le provider standard). Un provider sur mesure comme celui-ci **ne la traverse pas** : sans le
 * contrôle explicite ci-dessous, n'importe qui portant `reporting.lire` téléchargerait l'export
 * d'un autre établissement en connaissant son identifiant. Deux fuites de ce type ont déjà été
 * trouvées dans ce dépôt par ce même mécanisme.
 *
 * Le contrôle porte sur l'entité **résolue**, jamais sur l'identifiant reçu, et il est ici et non
 * dans une méthode voisine : `bin/garde-fou-cloisonnement.php` a refusé deux fois l'ordre inverse
 * sur un autre processeur en citant l'IDOR d'appairage du 22/08.
 *
 * ── POURQUOI DU BASE64 ──────────────────────────────────────────────────────────────────────────
 *
 * Seul le CSV est généré aujourd'hui, et du texte brut passerait. Mais les générateurs XLSX et PDF
 * existent en attente (`…GenerateurExportStub`), et le jour où l'un des deux est écrit, un contenu
 * binaire dans du JSON deviendrait illisible en silence. L'encodage est posé maintenant, pendant
 * qu'il ne coûte rien.
 */
final class TelechargerExportProvider implements ProviderInterface
{
    public function __construct(
        private readonly ExportDownloadAuthorizer $autorisation,
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly PerimetreReportingResolver $resolver,
        private readonly StockageExportInterface $stockage,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): Export
    {
        $utilisateur = $this->security->getUser();
        \assert($utilisateur instanceof Utilisateur);

        // API Platform convertit l'identifiant selon le type de la propriete : `Export::$id` est
        // un `Uuid`, donc ce que l'on recoit ici est un OBJET, pas une chaine. La premiere version
        // testait `is_string()` et rendait 404 sur toute demande — le `GET` unitaire de la meme
        // ligne repondait pourtant 200. On accepte les deux formes.
        $idBrut = $uriVariables['id'] ?? null;
        $id = match (true) {
            $idBrut instanceof Uuid => $idBrut,
            \is_string($idBrut) && Uuid::isValid($idBrut) => Uuid::fromString($idBrut),
            default => null,
        };
        if ($id === null) {
            throw new NotFoundHttpException('Export introuvable.');
        }

        $export = $this->em->getRepository(Export::class)->find($id);
        if (!$export instanceof Export) {
            throw new NotFoundHttpException('Export introuvable.');
        }

        // LA MEME REGLE QUE LE CONTROLEUR, ET LA MEME IMPLEMENTATION.
        // Elle manquait ici : mesure du 15/09, directeur regional couvrant A1 et non demandeur,
        // controleur 403 et provider 200.
        $this->autorisation->assertPeutTelecharger($export, $utilisateur);

        // ⚠ EN PLUS, ET ON NE LE RETIRE PAS. Ce controle porte sur la PORTEE de l'export confrontee
        // au perimetre du lecteur, la ou la regle ci-dessus porte sur son DEMANDEUR. Il couvre le
        // cas d'un `reporting.configurer` qui partage un etablissement avec le demandeur sans que
        // son perimetre couvre la portee de l'export. Ce provider est donc strictement plus severe
        // que le controleur — dit, et non suppose identique.
        $this->verifierPerimetreLecture($export, $utilisateur);

        // TROIS CAS, ET ILS NE SE DISENT PAS PAREIL.
        //
        // ⚠ `Envoye` EST UN SUCCÈS. `ExecuterRapportsCommand` stocke le fichier, passe à `Genere`,
        // envoie le courriel, puis passe à `Envoye` sans toucher au chemin de stockage : le fichier
        // est toujours là. L'exclure ici rendait « la génération n'a pas abouti » à un destinataire
        // qui a le fichier dans sa boîte.
        // Meme source de verite que l'autre porte : `StatutExport::fichierDisponible()`.
        if (!$export->getStatut()->fichierDisponible()) {
            // On ne rend pas 404 : l'export existe, il a échoué. Confondre les deux ferait chercher
            // un identifiant faux là où il y a un message d'erreur à lire.
            throw new UnprocessableEntityHttpException(sprintf(
                'Cet export n\'a pas de fichier : %s',
                $export->getMessageErreur() ?? 'la génération n\'a pas abouti.',
            ));
        }

        // ⚠ UN FICHIER ABSENT N'EST PAS UNE GÉNÉRATION RATÉE. Ce cas arrivera avec la rétention :
        // dire « la génération n'a pas abouti » d'un fichier purgé enverrait chercher un défaut qui
        // n'a jamais eu lieu.
        if ($export->getCheminStockage() === null) {
            throw new UnprocessableEntityHttpException(
                'Le fichier de cet export n\'est plus disponible. La génération avait abouti ; '
                . 'seul le fichier a été retiré du stockage.',
            );
        }

        $export->setContenuBase64(base64_encode($this->stockage->recuperer($export->getCheminStockage())));

        return $export;
    }

    /**
     * LE CONTRÔLE PORTE SUR L'EXPORT RÉSOLU, ET SUR SON RATTACHEMENT.
     *
     * `lire`, et non `configurer` : télécharger, c'est sortir ce qu'on a le droit de voir — c'est
     * d'ailleurs la règle que le processeur applique déjà pour décider ce qu'un export contient.
     */
    private function verifierPerimetreLecture(Export $export, Utilisateur $utilisateur): void
    {
        $perimetre = $this->resolver->perimetreEffectif($utilisateur, 'lire');

        $autorise = match ($export->getNiveau()) {
            NiveauEntite::Etablissement => $export->getEtablissement() !== null
                && $perimetre->estAutoriseEtablissement($export->getEtablissement()->getId()),
            NiveauEntite::Region => $export->getRegion() !== null
                && $perimetre->estAutoriseRegion($export->getRegion()->getId()),
            NiveauEntite::Groupe => $export->getGroupe() !== null
                && $perimetre->estAutoriseGroupe($export->getGroupe()->getId()),
        };

        if (!$autorise) {
            // 404 et non 403 : l'existence même d'un export hors périmètre ne se confirme pas.
            throw new NotFoundHttpException('Export introuvable.');
        }
    }
}
