<?php

declare(strict_types=1);

namespace App\Boutique\Billet;

use App\Boutique\Entity\BilletQrMeta;
use App\Boutique\Entity\LigneCommandeMeta;
use App\Boutique\Entity\RetraitClickCollect;
use App\Boutique\Enum\StatutRetraitPhysique;
use App\Offre\Entity\Produit;
use App\Vente\Entity\BilletSupport;
use App\Vente\Entity\LigneVente;
use App\Vente\Entity\Vente;
use App\Platform\Pdf\PoliceDeclaree;
use Dompdf\Dompdf;
use Dompdf\Options;
use Doctrine\ORM\EntityManagerInterface;
use Twig\Environment;

/**
 * Rend un **billet PDF par `Vente`**, regroupant tous les `BilletSupport` de la commande (un par
 * bénéficiaire/article, RG-M3-04) — US-L8-08, RG-M3-04/14, CA-11/CA-12. Entête établissement, produit,
 * créneau (timed-entry), bénéficiaire, QR image (`GenerateurImageQr`), numéro de billet, mentions.
 *
 * Moteur PDF : `dompdf/dompdf`, sans dépendance à `ext-gd`/`ext-imagick` (le QR est embarqué en SVG,
 * rendu par `dompdf/php-svg-lib` — testé en conteneur sans ces extensions, cf. rapport du lot).
 */
final class GenerateurPdfBillet
{
    public function __construct(
        private readonly Environment $twig,
        private readonly GenerateurImageQr $generateurQr,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** @return string contenu binaire du PDF */
    public function genererPourVente(Vente $vente): string
    {
        $billets = [];
        foreach ($vente->getSupports() as $support) {
            $billets[] = $this->vueBillet($support);
        }

        $html = $this->twig->render('boutique/billet/pdf.html.twig', [
            'etablissementNom' => $vente->getEtablissement()?->getNom() ?? '',
            'numeroCommande' => $vente->getNumero(),
            'dateCommande' => $vente->getDate(),
            'billets' => $billets,
        ]);

        return $this->rendrePdf($html);
    }

    /** @return array<string, mixed> */
    private function vueBillet(BilletSupport $support): array
    {
        $ligneVente = $support->getLigne();
        $ligneMeta = $ligneVente instanceof LigneVente
            ? $this->em->getRepository(LigneCommandeMeta::class)->findOneBy(['ligneVente' => $ligneVente])
            : null;
        $produit = $ligneVente instanceof LigneVente
            ? $this->em->getRepository(Produit::class)->find($ligneVente->getProduit())
            : null;
        $meta = $this->em->getRepository(BilletQrMeta::class)->findOneBy(['billetSupport' => $support]);
        $retrait = $this->em->getRepository(RetraitClickCollect::class)->findOneBy(['billetSupport' => $support]);

        $payload = $support->getIdentifiantSupport();
        if ($payload === null || $payload === '') {
            // Revue de sécurité — faille mineure : jamais de repli sur l'UUID brut du support (non
            // signé HMAC, prévisible/énumérable) dans le QR d'un billet — cf.
            // `App\Vente\Service\GenerateurCodeSupport` (CA-12). Anomalie de données à corriger plutôt
            // qu'à masquer silencieusement.
            throw new \RuntimeException(sprintf(
                'Support de billet %s sans identifiant signé (RG-M3-14/CA-12) : génération du QR refusée.',
                (string) $support->getId(),
            ));
        }
        $image = $this->generateurQr->generer($payload);

        return [
            'numeroSupport' => $payload,
            'produit' => $this->libelleProduit($produit),
            'beneficiaire' => $this->libelleBeneficiaire($ligneMeta),
            'creneau' => $this->libelleCreneau($ligneMeta),
            'repliQr' => $meta?->isRepliQr() ?? true,
            'supportPhysique' => $meta?->getStatutRetraitPhysique() !== null
                && $meta->getStatutRetraitPhysique() !== StatutRetraitPhysique::NonApplicable,
            'codeRetrait' => $retrait?->getCodeRetrait(),
            'qrDataUri' => $image->dataUri(),
        ];
    }

    private function libelleProduit(?Produit $produit): string
    {
        if ($produit === null) {
            return 'Article';
        }
        $libelle = $produit->getLibelle();

        return $libelle['fr'] ?? (array_values($libelle)[0] ?? $produit->getCode());
    }

    private function libelleBeneficiaire(?LigneCommandeMeta $meta): ?string
    {
        $simple = $meta?->getBeneficiaireSimple();
        if ($simple === null || $simple === []) {
            return null;
        }

        return trim(($simple['prenom'] ?? '') . ' ' . ($simple['nom'] ?? '')) ?: null;
    }

    private function libelleCreneau(?LigneCommandeMeta $meta): ?string
    {
        $creneau = $meta?->getCreneau();
        if ($creneau === null) {
            return null;
        }

        return $creneau->getDebut()->format('d/m/Y H:i') . ' - ' . $creneau->getFin()->format('H:i');
    }

    private function rendrePdf(string $html): string
    {
        $options = new Options();
        $options->setIsRemoteEnabled(false);
        $options->setChroot(\sys_get_temp_dir());

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(PoliceDeclaree::dans($html, PoliceDeclaree::BASE), 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }
}
