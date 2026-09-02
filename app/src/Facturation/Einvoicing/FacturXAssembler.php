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
 * ── ✓ LE PDF/A-3B EST VALIDÉ — ET UNE INQUIÉTUDE DE MA PART ÉTAIT INFONDÉE ─────────────────────
 *
 * Verdict de **veraPDF**, l'implémentation de référence, le 02/09 sur un fichier produit par cette
 * classe :
 *
 *     PASS /data/temoin-facturx.pdf 3b
 *
 * ⚠ Et le validateur discrimine : le même appel sur un PDF ordinaire rend `FAIL` avec rc=1. Un
 * « PASS » d'un outil qui ne chargerait aucune règle vaudrait zéro ; celui-ci sait refuser.
 *
 * ⚠ J'AVAIS ÉCRIT ICI QUE LE XMP COMPRESSÉ CASSAIT PEUT-ÊTRE LA CONFORMITÉ. C'est faux. J'avais
 * observé, à raison, que `pdfaid` n'apparaît pas en clair et que le flux est `FlateDecode` ; j'en
 * avais tiré, de mémoire et à tort, que la norme l'interdisait. L'observation était bonne,
 * l'inférence ne l'était pas — et c'est exactement pour ça que je l'avais posée comme une QUESTION
 * plutôt que comme un défaut. Une incertitude nommée se lève en une commande ; un défaut affirmé à
 * tort se propage.
 *
 * `infra/valider-facturx.sh` rejoue cette validation, témoin négatif compris.
 *
 * ⚠ CE QUI RESTE NON VALIDÉ : le XML contre le schematron d'EN 16931 (les ~100 règles `BR-xx`), et
 * le couple contre le validateur Factur-X de la FNFE. PDF/A-3B dit que l'ENVELOPPE est conforme ;
 * il ne dit rien du CONTENU de la facture.
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

    /**
     * L'espace de noms de l'extension XMP Factur-X.
     *
     * ⚠ Il ne se choisit pas plus que le nom du fichier : le validateur cherche exactement celui-ci.
     */
    private const NS_FACTURX = 'urn:factur-x:pdfa:CrossIndustryDocument:invoice:1p0#';

    public function __construct(
        private readonly CiiSerializer $serialiseur,
    ) {
    }

    /**
     * Le bloc XMP de Factur-X : ce que le fichier embarqué EST, et la déclaration qui l'autorise.
     *
     * ⚠ DEUX DESCRIPTIONS, ET OUBLIER LA SECONDE CASSE LE PDF/A — MESURÉ.
     *
     * La première (`fx:`) dit le type, le nom du fichier, la version et le niveau de conformité :
     * sans elle, le validateur Factur-X rend HUIT erreurs. Je l'ai posée seule, et veraPDF est passé
     * de `PASS` à `FAIL` :
     *
     *     « All properties specified in XMP form shall use either the predefined schemas […]
     *       or […] be described in an extension schema »
     *
     * PDF/A interdit une propriété XMP d'un espace de noms inconnu si rien ne la DÉCRIT. La seconde
     * description (`pdfaExtension`) est cette déclaration : elle dit au lecteur d'archive ce que
     * `fx:DocumentType` signifie, sans quoi le document n'est plus auto-descriptif — ce qui est la
     * raison d'être de PDF/A.
     *
     * ⚠ C'est le cas d'école de deux validateurs qui ne posent pas la même question. Satisfaire l'un
     * a cassé l'autre, et un seul des deux l'aurait laissé passer en silence.
     */
    /**
     * ⚠ PUBLIQUE POUR ETRE TESTABLE, ET C'EST UN CHOIX ASSUME.
     *
     * Le XMP finit dans un flux COMPRESSE du PDF : `assertStringContainsString('fx:DocumentType')`
     * sur les octets du fichier echoue, meme quand le bloc est bien la. Un test qui decompresserait
     * le flux deviendrait un lecteur de PDF — beaucoup de code pour prouver une concatenation.
     *
     * On teste donc la CONSTRUCTION ici, et l'INJECTION dans `infra/valider-facturx.sh`, ou un vrai
     * validateur lit le PDF fini. Deux moities, deux preuves, chacune par le chemin qui convient.
     */
    public function xmpFacturX(): string
    {
        $proprietes = '';
        foreach ([
            'DocumentType' => 'INVOICE, ORDER, …',
            'DocumentFileName' => 'nom du fichier XML embarque',
            'Version' => 'version de la specification Factur-X',
            'ConformanceLevel' => 'profil : MINIMUM, BASIC, EN 16931, EXTENDED',
        ] as $nom => $description) {
            $proprietes .= '<rdf:li rdf:parseType="Resource">'
                . '<pdfaProperty:name>' . $nom . '</pdfaProperty:name>'
                . '<pdfaProperty:valueType>Text</pdfaProperty:valueType>'
                . '<pdfaProperty:category>external</pdfaProperty:category>'
                . '<pdfaProperty:description>' . $description . '</pdfaProperty:description>'
                . '</rdf:li>';
        }

        return '<rdf:Description rdf:about=""'
            . ' xmlns:pdfaExtension="http://www.aiim.org/pdfa/ns/extension/"'
            . ' xmlns:pdfaSchema="http://www.aiim.org/pdfa/ns/schema#"'
            . ' xmlns:pdfaProperty="http://www.aiim.org/pdfa/ns/property#">'
            . '<pdfaExtension:schemas><rdf:Bag><rdf:li rdf:parseType="Resource">'
            . '<pdfaSchema:schema>Factur-X PDFA Extension Schema</pdfaSchema:schema>'
            . '<pdfaSchema:namespaceURI>' . self::NS_FACTURX . '</pdfaSchema:namespaceURI>'
            . '<pdfaSchema:prefix>fx</pdfaSchema:prefix>'
            . '<pdfaSchema:property><rdf:Seq>' . $proprietes . '</rdf:Seq></pdfaSchema:property>'
            . '</rdf:li></rdf:Bag></pdfaExtension:schemas>'
            . '</rdf:Description>'
            . '<rdf:Description xmlns:fx="' . self::NS_FACTURX . '" rdf:about="">'
            . '<fx:DocumentType>INVOICE</fx:DocumentType>'
            . '<fx:DocumentFileName>' . self::NOM_EMBARQUE . '</fx:DocumentFileName>'
            . '<fx:Version>1.0</fx:Version>'
            . '<fx:ConformanceLevel>EN 16931</fx:ConformanceLevel>'
            . '</rdf:Description>';
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

        // ⚠ UN CACHE DE POLICES CORROMPU FAIT ECHOUER LE PDF/A, ET J'AI CRU POUVOIR LE DEPLACER.
        //
        // Dompdf ecrit ses metriques dans `vendor/dompdf/dompdf/lib/fonts/`. Un run INTERROMPU y
        // laisse un `.ufm.json` tronque ; DejaVu devient alors inutilisable, la resolution retombe
        // sur Times — police de base, sans fichier binaire, non embarquable — et PDF/A refuse :
        //
        //     « A fully embeddable font must be used when generating a document in PDF/A mode »
        //
        // C'est arrive dans le worktree ou j'avais tue plusieurs suites completes ; le clone de
        // deploiement, lui, restait vert. Le meme code, deux verdicts.
        //
        // ⚠ MA PREMIERE CORRECTION ETAIT FAUSSE, ET PIRE QUE LE MAL. J'ai pointe `setFontCache` vers
        // un repertoire a nous, en croyant n'y deplacer que des metriques. Ce repertoire porte aussi
        // le REGISTRE des polices : vide, Dompdf ne trouvait plus DejaVu du tout —
        // « Unable to find a suitable font replacement for: 'DejaVu Sans' ». Trois tests au lieu de
        // deux. Je l'ai retiree.
        //
        // Le remede, quand ca arrive : supprimer les caches generes, que Dompdf reconstruit.
        //
        //     rm -f app/vendor/dompdf/dompdf/lib/fonts/*.ufm.json
        //     rm -f app/vendor/dompdf/dompdf/lib/fonts/*.afm.json

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

            // ⚠ L'EXTENSION XMP FACTUR-X — SANS ELLE, LE PDF EST REFUSE.
            //
            // Le PDF/A-3 pose son propre XMP (`pdfaid:part=3`), et ca ne suffit pas : Factur-X exige
            // en plus un bloc `fx:` qui dit CE QUE le fichier embarque est. Mesure du 02/09 avec le
            // validateur Factur-X, avant ce bloc : HUIT erreurs, toutes ici —
            //
            //     XMP Metadata: ConformanceLevel not found / contains invalid value
            //     XMP Metadata: DocumentType not found / invalid
            //     XMP Metadata: DocumentFileName not found / contains invalid value
            //     XMP Metadata: Version not found / contains invalid value
            //
            // ⚠ Et veraPDF disait PASS pendant ce temps. Les deux outils ne repondent pas a la meme
            // question : « ce PDF est-il un PDF/A-3 conforme ? » et « ce PDF/A-3 est-il un
            // Factur-X ? ». Un vert sur la premiere ne dit rien de la seconde.
            //
            // Le nom du fichier declare ici DOIT etre celui reellement embarque : une plateforme lit
            // ce champ pour savoir quoi extraire.
            $cpdf->setAdditionalXmpRdf($this->xmpFacturX());

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
