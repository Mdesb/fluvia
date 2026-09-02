<?php

declare(strict_types=1);

namespace App\Facturation\Einvoicing;

use App\Facturation\Entity\Facture;
use Dompdf\Adapter\CPDF;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * FACTUR-X — le PDF que l'humain lit, avec le XML que la machine lit à l'intérieur.
 *
 * Factur-X est un **PDF/A-3** portant en pièce jointe le CII d'EN 16931, nommé `factur-x.xml` et
 * déclaré `AFRelationship /Alternative`. Les trois conditions comptent : un PDF avec une pièce
 * jointe qui n'est pas PDF/A-3, ou dont le nom diffère, est refusé.
 *
 * ── ⚠ J'AI FAILLI CONSIGNER UN BLOCAGE QUI N'EXISTAIT PAS ──────────────────────────────────────
 *
 * Ma première conclusion était que la chaîne d'outils ne savait pas produire de PDF/A-3 : j'avais
 * cherché `'pdfa'` en minuscules dans `Options.php` et trouvé zéro. La méthode s'appelle
 * `isPdfAEnabled`, avec un A majuscule. **La recherche était fausse, pas la bibliothèque.**
 *
 * Dompdf sait tout faire : `Options::setIsPdfAEnabled(true)` déclenche `enablePdfACompliance()`
 * dans l'adaptateur CPDF, qui pose le XMP `pdfaid:part=3 conformance=B` et l'intention de sortie
 * avec le profil ICC sRGB embarqué. `Cpdf::addEmbeddedFile()` pose la pièce jointe et
 * `associateFile()` la relie au catalogue.
 *
 * Un blocage documenté à tort coûte plus qu'un blocage réel : personne ne le rouvre.
 *
 * ── CE QUI N'EST PAS PROUVÉ, ET QUI NE PEUT PAS L'ÊTRE ICI ─────────────────────────────────────
 *
 * ⚠ **CE FICHIER N'A ÉTÉ SOUMIS À AUCUN VALIDATEUR.** La conformité PDF/A-3 se prouve avec veraPDF,
 * celle du Factur-X avec le validateur de la FNFE, celle du XML avec le schematron d'EN 16931. Rien
 * de tout cela ne tourne dans ce dépôt.
 *
 * Ce que les tests prouvent : la structure attendue est présente. Ce qu'ils ne prouvent pas : qu'un
 * validateur l'accepte. Les deux phrases se ressemblent et ne disent pas la même chose — la seconde
 * demande un outil qu'on n'a pas.
 *
 * ── ⚠ UNE OBSERVATION À TRANCHER AVEC UN VALIDATEUR, PAS ICI ───────────────────────────────────
 *
 * Sonde du 02/09 sur un PDF produit par cette classe : la chaîne `pdfaid` n'apparaît **pas en
 * clair**, `/Metadata` deux fois, `FlateDecode` cinq fois. Le XMP de niveau document est donc écrit
 * dans un flux **compressé**.
 *
 * Or la norme PDF/A demande — sauf erreur de ma part, et c'est bien le problème — que ce flux reste
 * non compressé, pour qu'un outil d'archive puisse le lire sans décodeur. Si c'est exact, ce fichier
 * n'est **pas conforme** malgré une structure par ailleurs correcte.
 *
 * Je ne peux pas en décider : je n'ai ni le texte de la norme ni veraPDF. C'est écrit ici pour que
 * la question soit posée au premier passage de validation, et non redécouverte par un refus de
 * plateforme. Une incertitude nommée coûte moins qu'une conformité supposée.
 */
final class FacturXAssembler
{
    /**
     * Le nom du fichier embarqué, imposé par la spécification Factur-X.
     *
     * ⚠ Il ne se choisit pas. Un `facture.xml` produirait un PDF valide qu'aucune plateforme ne
     * saurait lire : elles cherchent ce nom exact.
     */
    public const NOM_EMBARQUE = 'factur-x.xml';

    public function __construct(
        private readonly CiiSerializer $serialiseur,
    ) {
    }

    /**
     * @param string $html le rendu visuel de la facture — ce que l'humain lira
     *
     * @throws InvoiceNotEmittableException si un terme obligatoire manque
     */
    public function assemble(string $html, Facture $facture): string
    {
        // ⚠ LA SÉRIALISATION D'ABORD, ET C'EST DÉLIBÉRÉ. Elle refuse une facture incomplète ; la
        // faire en premier évite de fabriquer un PDF qu'on jetterait — et surtout évite qu'un
        // débordement du rendu masque le vrai motif du refus.
        $xml = $this->serialiseur->serialize($facture);

        $options = new Options();
        $options->setIsRemoteEnabled(false);
        $options->setChroot(sys_get_temp_dir());
        // C'est cette ligne qui fait le PDF/A-3 : l'adaptateur appelle `enablePdfACompliance()` à sa
        // construction, ce qui pose le XMP et l'intention de sortie ICC.
        $options->setIsPdfAEnabled(true);

        // ⚠ PDF/A EXIGE UNE POLICE ENTIEREMENT EMBARQUABLE, ET LES POLICES DE BASE DU PDF NE LE
        // SONT PAS.
        //
        // Helvetica, Times et Courier ne sont pas incluses dans le fichier : le lecteur les
        // substitue. PDF/A l'interdit — un document d'archive doit se rendre identique dans dix ans,
        // sur une machine qui n'a plus ces polices. Sans cette ligne, la bibliotheque leve
        // « A fully embeddable font must be used when generating a document in PDF/A mode ».
        //
        // DejaVu Sans est livree avec Dompdf et couvre le latin etendu. On la pose par DEFAUT, ce
        // qui ne suffit pas si le HTML appelle explicitement une police non embarquable : dans ce
        // cas la meme exception remonte, avec son message, et c'est le gabarit qu'il faut corriger.
        $options->setDefaultFont('DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $canevas = $dompdf->getCanvas();

        // ⚠ SANS L'ADAPTATEUR CPDF, PAS DE PIÈCE JOINTE — et il faut le dire au lieu de rendre un
        // PDF muet. Dompdf sait aussi rendre en GD (une image) : dans ce cas il n'y a pas de
        // structure PDF où loger le XML, et un Factur-X sans son XML n'est qu'un PDF.
        if (!$canevas instanceof CPDF) {
            throw new \RuntimeException(sprintf(
                'Factur-X exige l adaptateur CPDF ; le rendu utilise %s, qui ne sait pas embarquer de fichier.',
                $canevas::class,
            ));
        }

        $chemin = tempnam(sys_get_temp_dir(), 'facturx-') ?: null;
        if ($chemin === null) {
            throw new \RuntimeException('Impossible de creer le fichier temporaire du XML embarque.');
        }

        try {
            file_put_contents($chemin, $xml);

            $cpdf = $canevas->get_cpdf();
            $cpdf->addEmbeddedFile(
                $chemin,
                self::NOM_EMBARQUE,
                'Facture electronique EN 16931 (CII)',
                'text/xml',
                // ⚠ `Alternative` SUR LE CATALOGUE, pas sur une page. La relation dit que le XML est
                // une représentation ALTERNATIVE du document entier — c'est ce que Factur-X exige, et
                // c'est ce qui distingue une facture électronique d'un PDF avec un fichier joint.
                [$cpdf->catalogId => 'Alternative'],
            );

            return (string) $dompdf->output();
        } finally {
            // Le XML est copié dans le PDF au moment de `output()` : on ne peut pas le supprimer
            // avant, et on ne doit pas le laisser après.
            @unlink($chemin);
        }
    }
}
