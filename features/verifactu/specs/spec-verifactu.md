# Spec — verifactu

**Statut :** brouillon — **CP-1 en attente de Maxime** (§CP-1, 13 questions en 4 blocs) <!-- brouillon → en revue → validée (CP-1) -->
**Auteur :** session de maintenance du 08/10 (Claude) ; contradiction par un agent séparé, qui n'a pas écrit la spec (§Contradiction / Réponse)
**Date :** 2026-10-08
**Origine :** décision de Maxime du 08/10 (QCM) : « Ça dépendra du type de client, donc il faut tout prévoir avec VERI*FACTU. » L'Espagne se construit en entier, VERI*FACTU compris. Paiement **au guichet seulement**, sur terminal autonome (déclaration du caissier, D122, déjà construite) : aucun prestataire en ligne. Un **autre chantier** fait le socle de traduction (`t()`, catalogues fr et es) et le NIF.
**Zone sensible :** fiscal (facturation et impôt indirect espagnols), NF525 (cohabitation des chaînes), secrets (certificat électronique, dépôt public), cloisonnement multi-tenant, conservation de données hors d'Espagne.
**Ce document n'écrit aucun code et ne planifie rien** : le plan vient après le CP-1.

## Contexte & problème

Le besoin : un exploitant espagnol encaisse au guichet avec Fluvia, et chaque vente devient une **facture** que l'AEAT peut vérifier — enregistrée, chaînée, envoyée (ou signée et conservée), avec son QR. Fluvia, éditeur, doit pouvoir le **déclarer** sans s'exposer à l'amende de l'art. 201 bis LGT. Rien de cela n'existe : aucune occurrence de « AEAT », « NIF » ou « Spain » dans le dépôt, aucune issue (mesuré le 08/10, C-10).

Sources : textes lus en version consolidée sur boe.es (API de données ouvertes du BOE) par la session ; documents techniques de l'AEAT, FAQ de la sede et textes forals lus par deux agents de recherche, sources officielles seulement, avec version et date (§Sources). Deux FAQ ont été relues par la session pour contrôle (colaboración social, traçabilité, mises à jour le 07/10/2026). Code mesuré sur `origin/main` `5870f9b0`.

### Le droit, lu à la source le 08/10/2026

#### F-1 — Qui est obligé, et quand

- **Obligés** (RRSIF — RD 1007/2023, règlement — art. 3.1) : contribuables de l'impôt sur les sociétés (IS) ; de l'IRPF exerçant une activité économique ; non-résidents avec établissement permanent ; entités en attribution de revenus — « aunque solo los usen para una parte de su actividad ».
- **Hors champ** :
  - les entités **totalement exonérées** de l'IS (art. 3.1.a, renvoi à LIS art. 9.1) : État, communautés autonomes, **entités locales**, **organismes autonomes** et entités de droit public de même nature ;
  - les contribuables au **SII** (art. 3.3, renvoi à RIVA art. 62.6) — **sans QR et sans VERI*FACTU, même volontaire** (FAQ AEAT « ámbitos de aplicación ») ;
  - les opérations **sans obligation de facturer** (RD 1619/2012 art. 3 ; même FAQ) ;
  - les opérations d'un établissement permanent à l'étranger (art. 4.2).
- **Champ partiel** : les entités **partiellement exonérées** (LIS art. 9.2 à 9.4 : associations et fondations de la loi 49/2002, autres entités sans but lucratif, fédérations) n'y sont soumises « exclusivamente por las operaciones que generen rentas que estén sujetas y no exentas del Impuesto » (art. 3.1.a). Aucune FAQ de l'AEAT ne le précise (recherche du 08/10).
- **Territoire** (art. 1.3) : au Pays basque et en Navarre, le RRSIF ne vaut que pour les obligés à **domicile fiscal en territoire commun** ; aux Canaries, à Ceuta et à Melilla il s'applique, ses renvois à la TVA valant pour l'IGIC et l'IPSI. La FAQ de l'AEAT raisonne, elle, sur la **normativa** applicable à l'impôt direct (Concierto Económico art. 6 et 14, Convenio navarrais art. 9 et 18) : voir F-11.
- **Plusieurs obligés dans un même système** : permis « siempre que los registros de facturación de cada obligado tributario se encuentren diferenciados » (art. 7).
- **Dates** (RRSIF, disposition finale 4ᵉ, rédaction du RDL 15/2025 du 02/12/2025, **convalidé** par le Congrès, résolution du 11/12/2025) : contribuables de l'IS **avant le 01/01/2027** ; autres obligés **avant le 01/07/2027** ; **producteurs** : produits adaptés dans les neuf mois de l'entrée en vigueur de l'Orden HAC/1177/2024 (29/10/2024), soit **depuis le 29/07/2025**. Un logiciel mis aujourd'hui sur le marché espagnol doit donc être conforme **dès sa première vente** : il n'existe pas de délai propre à un nouveau produit.
- ⚠ **Correction de l'étude du 08/10 — le « report à octobre 2028 » n'est pas un report de VERI*FACTU.** Le BOE du 05/10/2026 publie l'**Orden HAC/1028/2026** (solution publique de facturation électronique), en vigueur le 06/10/2026. Elle fait courir les délais du **RD 238/2026** — facture électronique obligatoire **entre entreprises** (loi « Crea y Crece ») — : douze mois au-delà de 8 M€ de volume d'opérations, vingt-quatre mois pour les autres (RD 238/2026, disposition finale 4ᵉ), soit le **06/10/2027** et le **06/10/2028** (calcul de la session). Aucun texte publié au BOE jusqu'au 08/10/2026 ne déplace les dates VERI*FACTU : le RRSIF consolidé est à jour au 03/12/2025, et les sommaires du BOE du 05 au 08/10 ont été relus en entier.

#### F-2 — Ce qui s'enregistre

- Un **registro de alta**, généré « de forma simultánea o inmediatamente anterior a la expedición de cada factura » (RRSIF art. 9), en XML UTF-8 selon l'annexe de l'Orden HAC/1177/2024 (Orden art. 10) et le XSD `SuministroInformacion.xsd` de l'AEAT. Contenu (RRSIF art. 10) : émetteur, destinataire quand il est obligatoire, série et numéro, dates, type, rectification, substitution, description, montant total, régime, base, taux, quote-part, exonération et sa cause, non-sujétion et sa cause, chaînage, identification du système et du producteur, horodatage à la seconde. **Montants en euros** (art. 10.2).
- Champs obligatoires du XSD ([DR] onglet 2) : `IDVersion` 1.0 ; `IDFactura` (`IDEmisorFactura`, `NumSerieFactura` 60 car., `FechaExpedicionFactura`) ; `NombreRazonEmisor` ; `TipoFactura` ; `DescripcionOperacion` (500 car.) ; `Desglose` (1 à 12 lignes, chacune `CalificacionOperacion` **ou** `OperacionExenta`, et `BaseImponibleOimporteNoSujeto`) ; `CuotaTotal` ; `ImporteTotal` ; `Encadenamiento` ; `SistemaInformatico` ; `FechaHoraHusoGenRegistro` ; `TipoHuella` ; `Huella`. `ClaveRegimen` obligatoire pour l'IVA, l'IGIC et l'IPSI ; `TipoImpositivo` et `CuotaRepercutida` obligatoires en S1 ([VAL] §15.6-15.7).
- **Types** (Orden, annexe, L2) : F1 facture ; **F2 simplifiée** ; F3 facture en substitution de simplifiées ; R1 à R4 rectificatives ; **R5 rectificative de simplifiée**. **`Destinatarios` interdit en F2 et en R5, obligatoire en F1, F3, R1 à R4** ([VAL] point 13). Une F2 est plafonnée à 3 000 € (base + quote-part, tolérance 10 €) ([VAL] §15.8).
- **Codes** : impôt (L1) 01 IVA, 02 IPSI, 03 IGIC, 05 autres — absent, c'est l'IVA ; qualification (L9) S1 sujette non exonérée, N1 non sujette (art. 7, 14…), N2 non sujette par localisation ; exonération (L10) E1 art. 20 LIVA … E6 ; régime (L8A pour l'IVA, L8B pour l'IGIC) 01 régime général.
- Un **registro de anulación** seulement quand une facture « se haya emitido erróneamente » (RRSIF art. 11) : opération inexistante, facture d'essai. Quand le règlement de facturation prévoit une rectification, c'est une rectificative, jamais une annulation ([FAQD] n° 17) ; un numéro annulé n'est jamais réutilisé ([FAQD] n° 6).

#### F-3 — L'empreinte et la chaîne

- **Empreinte** (Orden art. 13 ; [H] §3 et §5) : SHA-256 de la chaîne UTF-8 `nombreCampo=valor&…`, noms de champs du XML, espaces de bord retirés, champ vide écrit `nombre=` ; sortie hexadécimale **majuscule**, 64 caractères.
  - alta : `IDEmisorFactura`, `NumSerieFactura`, `FechaExpedicionFactura` (`dd-mm-yyyy`), `TipoFactura`, `CuotaTotal`, `ImporteTotal`, `Huella` (précédente, vide pour le premier), `FechaHoraHusoGenRegistro` (`YYYY-MM-DDThh:mm:ss±hh:mm`) ;
  - anulación : `IDEmisorFacturaAnulada`, `NumSerieFacturaAnulada`, `FechaExpedicionFacturaAnulada`, `Huella`, `FechaHoraHusoGenRegistro`.
  - Les trois exemples officiels de [H] §6 ont été recalculés par l'agent de recherche : conformes. Une empreinte fausse est « aceptado con errores », pas un rejet ([H] §7).
- **Chaîne** (Orden art. 7) : chaque registre porte, du précédent, NIF, série et numéro, date et les **64 premiers caractères** de l'empreinte ; le premier est marqué `PrimerRegistro=S` ; « **para un determinado obligado tributario, cada sistema informático producirá una única cadena** » ; altas et anulaciones **dans la même chaîne** ; heure exacte « de acuerdo al territorio desde donde se expide la correspondiente factura », **avec le fuseau** ; marge d'erreur d'horloge **une minute** ; avant chaque registre, contrôler que le dernier est bien chaîné et pas daté de plus d'une minute dans le futur.
- **FAQ traçabilité** (mise à jour le 07/10/2026, relue par la session) : « una única cadena por cada pareja (SIF; OT) » ; plusieurs TPV qui facturent **de façon autonome** sont autant de SIF, chacun avec sa chaîne ; un système centralisé qui sert plusieurs obligés tient une chaîne par obligé ; le changement d'année ne rompt pas la chaîne.
- **SaaS** ([FAQD] n° 4) : chaque facturation (un client, ou un centre indépendant) a son propre `NumeroInstalacion`, jamais réutilisé ; `IndicadorMultiplesOT` se calcule par utilisateur.
- **Plusieurs obligés** (Orden art. 2) : le système se comporte « como si fueran sistemas informáticos independientes » — gestion séparée, une chaîne par obligé, VERI*FACTU **indépendant par obligé**, et l'écran montre « claramente y en todo momento » l'obligé pour lequel on travaille ; s'il en sert plusieurs, un message le dit.

#### F-4 — Deux modes

- **VERI*FACTU** (RRSIF art. 15-16 ; Orden art. 3, 16, 17) : le système remet à l'AEAT **tous** ses registres, de façon continue et automatique. Il est **présumé conforme par conception** (art. 16.2), **dispensé de signature** (art. 16.3), et n'est pas soumis aux articles 6.b à 6.f, 7.f, 7.h à 7.j, **8 (conservation, export)** et **9 (registre d'événements)** de l'Orden (Orden art. 3). Il n'a pas à conserver les registres ; les factures, si ([FAQD] n° 13). L'option dure au moins jusqu'au **31/12** de l'année du premier envoi ; on y renonce par le champ `FechaFinVeriFactu` d'un envoi parti avant la fin de l'année (Orden art. 17) ; pas de bascule par facture ou par session ([FAQD] n° 3).
- **Envoi** (Orden art. 4, 5, 16 ; [WS]) : SOAP 1.1 document/literal sur HTTPS, authentification par **certificat électronique** ; un lot ne porte qu'**un obligé** ; **contrôle de flux** : attendre « t » secondes depuis le dernier envoi (60 s au départ, la réponse donne la suite dans `TiempoEsperaEnvio`) **ou** d'avoir 1 000 registres, le premier des deux. Réponse : état global `Correcto` / `ParcialmenteCorrecto` / `Incorrecto`, état par registre `Correcto` / `AceptadoConErrores` / `Incorrecto` avec son code, et un CSV si un registre au moins est accepté. **Incident** : remettre « en cuanto sea posible, respetando el orden temporal », avec `Incidencia=S` ; **réessayer au moins une fois par heure** ; **afficher un avertissement** donnant le nombre de registres non remis tant qu'il en reste ; la facturation ne s'interrompt pas ; sans réponse, renvoyer jusqu'à en obtenir une. **Correction** : registre rejeté → nouvel alta `Subsanacion=S`, `RechazoPrevio=X` ; registre accepté avec erreurs → `Subsanacion=S` ([DR] onglet A ; [VAL] §4.3.1).
- **Qui envoie** (Orden art. 5 ; FAQ « colaboración social », mise à jour le 07/10/2026, relue par la session) : l'obligé, **ou un tiers qui le représente** (RGAT art. 79 à 81 ; Orden HAC/1398/2003). Un éditeur peut envoyer pour ses clients avec **son propre certificat** s'il signe la **convention de collaboration sociale de type 017** et si chaque client lui donne une **représentation** selon l'Anexo I de la résolution du 18/12/2024, signée à la main (avec copie de la pièce d'identité) ou par signature électronique qualifiée ou avancée — **jamais** par acceptation de conditions générales.
- **NO VERI*FACTU** (Orden art. 6 à 9, 14, 18 ; [FIR]) : chaque registre et chaque événement **signé** en XAdES Enveloped, classe EPES, politique de signature de l'AGE (OID `2.16.724.1.3.1.1.2.1.9`), RSA/SHA-256, avec un **certificat qualifié** (de l'obligé, de son représentant ou d'un collaborateur social) d'un prestataire inscrit sur la liste de confiance de l'UE ; contrôle à la demande de l'empreinte, de la signature et de la chaîne ; **alarme** qui reste allumée tant que l'intégrité n'est pas rétablie ; **registre d'événements** à chaîne propre (début et fin du mode, lancement et résultat des détections d'anomalies, restauration de sauvegarde, exports, et un **résumé toutes les 6 heures** de fonctionnement et avant l'arrêt) ; conservation dans le système, **export** par période, remise sur **requerimiento** de l'AEAT par un service dédié.

#### F-5 — La facture

- **Toute** opération donne lieu à facture, non sujette ou exonérée comprise, sauf exceptions (RD 1619/2012 art. 2.1). Les opérations **exonérées par l'art. 20 LIVA** n'en exigent pas, sauf les numéros 2, 3, 4, 5, 15, 20, 22, 24, 25 et 28 — ni le 13º (sport) ni le 14º (culture) n'y figurent — **et sauf** destinataire professionnel ou qui l'exige (art. 3.1.a et 2.2.a).
- **Simplifiée** (art. 4) : jusqu'à **400 €** TTC ; jusqu'à **3 000 €** pour, entre autres, « utilización de instalaciones deportivas », hôtellerie et restauration, transport de personnes, stationnement. Un **musée** ou un **spectacle** n'est pas dans la liste.
- **Contenu de la simplifiée** (art. 7.1) : série et numéro **corrélatif dans la série** ; date d'émission et, si elle diffère, date de l'opération ; **NIF et nom de l'émetteur** ; nature des biens ou services ; **taux** (et, au choix, « IVA incluido ») ; **base par taux** quand plusieurs taux coexistent ; contrepartie totale ; rectificative : référence de la facture rectifiée ; pour une opération exonérée, la référence au texte ou l'indication qu'elle est exonérée (art. 7.1.i, renvoi à 6.1.j). Plus le **QR** et, en VERI*FACTU, la phrase (art. 7.5 et 6.5).
- **Séries** : on **peut** séparer par établissement ou par nature d'opérations ; il est **obligatoire** de séparer simplifiées et complètes de la même année (art. 7.1.a) et de mettre les rectificatives d'une facture complète en série propre (art. 6.1.a).
- **Rectificative** (art. 15) : erreur ou modification de base (LIVA art. 80) ; référence de la facture rectifiée ; une simplifiée se rectifie par une simplifiée (R5). La facture **complète émise en échange de simplifiées** n'est **pas** une rectificative (art. 15.6) : c'est une F3, toujours avec destinataire ; on n'annule pas les simplifiées remplacées ; pour rectifier une simplifiée déjà couverte par une F3, on émet une R5 puis une F3 de la R5 ([FAQD] n° 27).
- **Émission et remise** : la facture s'émet **au moment de l'opération** (art. 11) et se **remet au client à l'émission** (art. 17-18) ; électronique seulement **avec son consentement** (art. 9.2). **Un seul original** ; un double seulement en cas de perte ou de pluralité de destinataires, avec la mention « duplicado » (art. 14). **Toute langue**, l'AEAT pouvant exiger une traduction en castillan (art. 12).
- **QR et phrase** (Orden art. 20-21 ; [QR] v0.5.0) : QR de **30×30 à 40×40 mm**, ISO/IEC 18004, correction **M**, marge blanche d'au moins 2 mm (6 recommandés), « **QR tributario:** » au-dessus, premier QR du document, en haut de la première page ; URL `https://www2.agenciatributaria.gob.es/wlpl/TIKE-CONT/ValidarQR?nif=…&numserie=…&fecha=DD-MM-AAAA&importe=…` (`ValidarQRNoVerifactu` hors VERI*FACTU ; préproduction `prewww2.aeat.es`), paramètres encodés en UTF-8 ; **en VERI*FACTU seulement**, juste dessous, « Factura verificable en la sede electrónica de la AEAT » ou « VERI*FACTU ». Le QR est **obligatoire aussi en NO VERI*FACTU** (RD 1619/2012 art. 6.5).

#### F-6 — Les taux qui touchent les métiers de Fluvia

| Opération | Règle | Texte lu |
|---|---|---|
| Pratique sportive vendue par un exploitant privé (padel, piscine en concession, fitness), territoire commun | **21 %** — aucun taux réduit pour la pratique dans l'art. 91, relu en entier | LIVA art. 90 |
| Entrée de musée, galerie, cinéma, théâtre, cirque, concert, spectacle culturel vivant | **10 %** | art. 91.Uno.2.6º |
| Hôtellerie, **camping**, restauration, boissons sur place | **10 %** | art. 91.Uno.2.2º |
| Spectacle sportif amateur | **10 %** | art. 91.Uno.2.8º |
| Service sportif par une entité de droit public, une fédération, le COE, le CPE ou une entité sportive privée « de carácter social » | **exonéré (E1)**, sauf spectacle sportif | art. 20.Uno.13º |
| Musée, monument, zoo, représentation, exposition par une entité de droit public ou culturelle privée de caractère social | **exonéré (E1)** | art. 20.Uno.14º |
| Service rendu **directement** par une administration publique, sans contrepartie ou contre une contrepartie de nature tributaire | **non sujet (N1)** — pas une entité publique d'entreprise locale | art. 7.8º A-B |
| Canaries (IGIC) | général **7 %** ; **3 %** pour l'accès aux représentations et expositions et pour la pratique sportive non exonérée ; exonérations sport et culture symétriques | Ley 4/2012 art. 51.1, 54.2.c et f, 50.Uno.13º et 14º |
| Ceuta, Melilla (IPSI) | **À VÉRIFIER** (non lu) | — |

Les taux bougent : l'art. 91 LIVA est encore modifié **au 01/12/2026** (RDL 29/2026 du 06/10/2026, après la dérogation du RDL 26/2026 par le Congrès le 02/10/2026). Un taux est une **donnée datée et sourcée**, jamais une constante.

#### F-7 — Conservation et hébergement hors d'Espagne

- Les **copies des factures émises** se conservent pendant le délai de la LGT (RD 1619/2012 art. 19.1.b) : **quatre ans** de prescription (LGT art. 66), davantage pour justifier une donnée d'une période prescrite qui produit encore effet (LGT art. 70.3 et 66 bis) ; le Code de commerce impose **six ans** à compter du dernier « asiento » (art. 30). Les registres se conservent pendant le même délai que les factures, **même après un changement de système** (Orden art. 8.3).
- Conservation électronique : **accès en ligne**, chargement à distance et utilisation par l'AEAT — visualisation, recherche, copie, téléchargement, impression (RD 1619/2012 art. 21.2 et 23).
- **Hors d'Espagne** (art. 22.2) : seulement par voie électronique avec accès en ligne, et l'obligé « deberán comunicar **con carácter previo** esta circunstancia a la Agencia Estatal de Administración Tributaria ». La France est dans l'Union : l'art. 19.4 (tiers hors UE) ne joue pas.

#### F-8 — La declaración responsable

- Le **producteur** certifie par une déclaration responsable que le système est conforme (RRSIF art. 13.1) ; elle figure « por escrito y de modo visible en el propio sistema informático **en cada una de sus versiones** », est remise au client et au distributeur (art. 13.2), et le producteur **conserve celles de toutes les versions** (art. 13.3).
- **Forme imposée** (Orden art. 15) : titre « DECLARACIÓN RESPONSABLE DEL SISTEMA INFORMÁTICO DE FACTURACIÓN », puis **dans cet ordre**, chaque donnée **précédée de son libellé** : a) nom du système ; b) code identifiant (2 caractères, [VAL] §3.1.4) ; c) identifiant complet de la version ; d) composants et fonctions ; e) s'il ne fonctionne **que** comme VERI*FACTU ; f) s'il sert **plusieurs obligés** ; g) types de signature hors VERI*FACTU ; h) producteur ; i) **NIF espagnol** du producteur ou, à défaut, autre identifiant avec son type et son pays ; j) adresse postale ; k) déclaration de conformité au 29.2.j LGT, au RRSIF, à l'Orden et aux spécifications de la sede ; l) date (jour, mois, année) et lieu (localité, pays). Accessible dans le système « de forma rápida, fácil e intuitiva » (la FAQ recommande « Ayuda » ou « Acerca de »), remise au client sur papier ou dans un format électronique gratuit (art. 15.3). Aucune signature électronique exigée.

#### F-9 — Ce que risque l'éditeur

LGT art. 201 bis, en vigueur depuis le 11/10/2021 :
- produire ou commercialiser un système qui permet de ne pas enregistrer, d'enregistrer autre chose ou d'altérer (1.b à 1.d), ou qui ne respecte pas les spécifications d'intégrité, de conservation, d'accessibilité, de lisibilité, de traçabilité et d'inaltérabilité (1.e) : **150 000 € par exercice où il y a eu des ventes et par type de système** ;
- ne pas certifier — ici, pas de declaración responsable — : **1 000 € par système commercialisé** (1.f) ;
- le **client** qui détient un système non conforme : **50 000 € par exercice** (2 et 4).

#### F-10 — La facture électronique entre entreprises

RD 238/2026 : toute facture à un professionnel établi en Espagne sera **électronique structurée**, à partir du 06/10/2027 (plus de 8 M€) ou du 06/10/2028 (les autres) (F-1). **Les simplifiées en sont exclues**, sauf la simplifiée « qualifiée » de l'art. 7.2 (RD 238/2026 art. 4.1). L'Orden HAC/1028/2026 retient EN 16931 en syntaxe UBL pour la solution publique, et un champ (BT-ES-25) qui porte **l'URL du QR VERI*FACTU ou TicketBAI**.

#### F-11 — Pays basque, Navarre, Canaries, Ceuta et Melilla

- **TicketBAI** (Álava, Bizkaia, Gipuzkoa) est **obligatoire pour tous** : Gipuzkoa depuis le 01/06/2023, Álava depuis le 01/12/2022, Bizkaia depuis le 01/01/2026 (NF 8/2023). Le critère n'est pas le seul domicile mais la **normativa foral** applicable à l'impôt direct (FAQ de Gipuzkoa ; Concierto Económico art. 14 : domicile, avec la règle des 12 M€ et 75 %). Un contribuable soumis à TicketBAI est **hors VERI*FACTU** ; un établissement basque d'une société de territoire commun relève, en règle générale, de **VERI*FACTU**.
- Ce que TicketBAI demande à l'éditeur et au logiciel : **inscription** du logiciel au registre foral (formulaire, déclaration responsable, mémoire descriptive ; numéro de licence TBAI à porter dans chaque fichier ; inscription valable dans les trois territoires) ; XML **signé XAdES EPES avant l'émission**, chaîné sur les **100 premiers caractères de la signature** précédente ; **code TBAI** de 39 caractères ; QR propre à chaque territoire ; envoi **immédiat** en Gipuzkoa et en Álava (REST, certificat client), dépôt au **LROE** (modèles 140 et 240) en Bizkaia. Sanction en Gipuzkoa : 20 % du chiffre d'affaires, minimum 20 000 € (NF 2/2014 art. 133 bis.2). Sources : DF 32/2020 et OF 521/2020 (Gipuzkoa), DF 48/2021 (Álava), DF 82/2020, OF 1482/2020 et NF 8/2023 (Bizkaia).
- **Navarre** : hors RRSIF pour les contribuables de normativa navarraise ; **aucun système propre adopté** au 08/10/2026 — la Resolución 52/2026 (BON du 16/07/2026) présente « NaTicket » comme un projet, sans date.
- **Canaries, Ceuta, Melilla** : VERI*FACTU s'applique (FAQ AEAT) ; impôt 03 IGIC ou 02 IPSI dans le registre ; clés de régime L8B pour l'IGIC. Un établissement à Ceuta ou Melilla d'une entreprise au SII échappe au règlement (FAQ AEAT).

### Le code, mesuré le 08/10 sur `origin/main` `5870f9b0`

#### C-1 — NF525 : trois chaînes, aucune n'est la bonne pour l'Espagne

- **Trois chaînes indépendantes**, trois clés HMAC d'environnement : ventes et caisse dans `nf525_operation_scellee`, **par point de vente**, unicité `(point_de_vente_id, numero_sequence)` (`Vente/Nf525/Entity/OperationScellee.php:27-28`, clé `NF525_SEAL_KEY`) ; factures, par profil exploitant, sur `facturation_facture` (`ScellementFactureHandler.php:17-19`, `NF525_FACTURATION_SEAL_KEY`, **sans unicité**, simple index `Facture.php:66`) ; écritures, par `(profil, journal)` (`ScellementEcritureHandler.php:17`, `NF525_COMPTA_SEAL_KEY`).
- Algorithme : `empreinte = sha256(json_canonique(payload) . '|' . empreinte_précédente)`, signature HMAC-SHA256 (`HashChainSignataire.php:11-53`) ; le docblock la dit « placeholder » (l.17-21). Aucun verrou explicite : la sérialisation tient à la contrainte unique (`ScellementHandler.php:14-15, 28-47`). Horodatage = heure serveur (`OperationScellee.php:97`).
- Le scellement de la vente a lieu **dans la transaction de validation** (`ValiderVenteService.php:192-199`) ; payload sans TVA ni vendeur (l.442-466) — c'est l'objet de D123 et D124, pas encore construit.
- Types scellés : `vente`, `avoir`, `correction_reglement`, `cloture_journaliere`, `cloture_z` ; `cloture_mensuelle` et `cloture_annuelle` sans appelant (`TypeOperationScellee.php:12-31`).
- `InalterabiliteListener` : append-only **au niveau ORM seulement** (aucun trigger), `CHAMPS_VENTE_FIGES` en liste noire (l.33-45), `estAppendOnly()` l.100-114.
- **Aucun registre d'événements** (rien de proche d'un JET) ; le journal d'audit `audit_entree` n'est ni chaîné ni signé. Aucune archive NF525.
- Vérification de chaîne : `POST /nf525/verifier-chaine` (ventes), qu'**aucun écran n'appelle** ; factures et écritures ont la leur, appelée par l'écran.
- Taille : 2 083 lignes dans les dossiers `*/Nf525/`, 1 233 de plus autour.
- **Conséquence pour l'Espagne** : aucune des trois chaînes n'a le bon périmètre (VERI*FACTU chaîne par obligé et par système, F-3), ni le bon contenu (empreinte de l'AEAT, pas un HMAC), ni le bon format (XML de l'Orden). Il faut une **quatrième** chaîne, à part. Ce qui se réutilise : le patron append-only, le calcul en transaction, le patron du verrou `FOR UPDATE` de `GenerateurNumeroFacture`, la vérification à la demande.

#### C-2 — Établissement et exploitant

- `Etablissement` porte déjà `pays` (ISO 3166, défaut `FR`, l.107-110), **`fiscalTerritory`** (`''` = droit commun, `IC` cité pour les Canaries, l.128-131), `devise` (défaut `EUR`, l.148-151), `fuseauHoraire` (l.173-175). **Aucun écran** ne les règle : seulement l'API `PATCH`. Ni langue ni adresse.
- `ProfilExploitant` est **l'identité légale qui facture** : raison sociale, adresse, `tvaIntracommunautaire`, `etablissementPrincipal`, `etablissementsRattaches` (`Compta/Entity/ProfilExploitant.php:52-212`) ; les séries de facture et la chaîne des factures sont **par profil** (C-4). Le SIREN y est **refusé hors de France** (« Renseignez le numéro de TVA intracommunautaire à la place », l.332-355). Aucun NIF, aucun régime fiscal espagnol.
- La forme juridique vit dans `Legal/Entity/LegalIdentity.php` (une par établissement).

#### C-3 — TVA

- `TauxTva` : `taux`, `vatCategory` (nullable), `libelle`, `origineLegale` → `LegalVatRate` ; **pas de motif d'exonération**. `VatCategory` : S, Z, E, AE, K, G, O — **ni `L` (IGIC) ni `M` (IPSI)**. Le sérialiseur CII écrit `VAT` en dur (`CiiSerializer.php:258, 487`). **Aucun champ « impôt »** : l'IGIC n'est qu'un taux avec un libellé.
- Le catalogue légal (`LegalVatRate`, unicité `(country, territory, category, valid_from)`) amorce pour l'Espagne **21 % seulement** en péninsule, et **7 % et 3 % IGIC** aux Canaries (`SeedLegalVatRatesCommand.php:107-150`), avec pour source la loi 20/1991 alors que les taux sont fixés par la **loi 4/2012 art. 51** (F-6). **Le 10 % espagnol manque** (musées, restauration, camping), comme toute exonération et toute non-sujétion.
- La TVA gravée sur la ligne (D123) n'existe pas encore : lot 6 du ticket opposable.

#### C-4 — Facturation, séries et D107

- `Facture` : numéro **unique global** (`Facture.php:63`), nature facture / avoir / acompte, origine `ticket_encaisse` (la « facture justificative ») ou `vente_a_terme`. Séries `SerieNumerotation` **par profil exploitant et exercice**, préfixes `FA` et `AVF`, compteur sous `SELECT … FOR UPDATE` dans la transaction d'émission (`GenerateurNumeroFacture.php`).
- **Le numéro de ticket n'est pas un numéro de facture** : `<numéro de session>-T00001`, ou `D-<pdv>-00001` en vente directe, calculé par `COUNT + 1` **sans verrou** (`Vente/Service/GenerateurNumero.php:30-67`) ; l'avoir de caisse `AV-AAAAMMJJ-00001` sur un compteur **global** (l.69-78). Rien de cela n'est une série corrélative au sens de l'art. 7.1.a.
- La facture justificative s'émet **à la demande**, sur une vente scellée et payée (`POST /factures/depuis-vente`) ; l'avoir `AVF` est total seulement et, sur une justificative, sans écriture.
- **D107** (31/08, `DECISIONS.md:3320-3358`) : « aucun destinataire : ce n'est pas une facture, c'est un ticket », et la règle **ne porte pas sur le montant**, « délibérément », parce que le seuil de la simplifiée est « un nombre qu'on ne peut pas vérifier depuis le code et qui bouge avec les textes ». **En Espagne, les deux branches s'inversent** : sans destinataire, c'est une facture simplifiée ; et le montant décide de la forme (400 € / 3 000 €, F-5). Les seuils y deviennent des données datées et sourcées, comme les taux.

#### C-5 — Le ticket opposable, dont l'Espagne dépend

Lots 1 à 4 fusionnés (#286, #291, #293, #298) ; **lots 5 à 10 pas encore** : ni taux gravé sur `LigneVente`, ni `DocumentTicket`, ni journal des éditions, ni lignes d'avoir avec taux (mesuré). Décisions qui s'appliquent : D122 (terminal muet déclaré), D123 (taux de la catégorie gravé, un seul calcul, refus avant paiement), D124 (journal des éditions à part, vendeur figé, impression comptée seulement si confirmée, duplicata identique), D125 (avoir en lignes négatives avec leur taux), D126 (ordre des lots). Dernière décision : D131.

#### C-6 — Le hors-ligne

`SynchroOperationsProcessor` recrée la vente **à la synchronisation**, avec l'heure du serveur, un numéro attribué à ce moment, et la scelle alors (`SynchroOperationsProcessor.php:128-162`, `Vente.php:286`). Aucun écran ne l'appelle et le service worker ne met aucune réponse en cache : **il n'existe pas de caisse hors ligne aujourd'hui**.

#### C-7 — Asynchrone, alertes, appels externes

- Messenger : transport `async` Doctrine, 5 tentatives, `worker` à deux instances (`compose.preprod.yaml:182-200`). Ordonnanceur maison `ScheduleCatalog` (533 lignes) bouclé toutes les 60 s par le service `scheduler`, sur liste blanche.
- Notifications : `platform_notification`, une ligne par destinataire, gravité info / attention / critique, alimentée par `NotifyOnDomainEvent`. E-mail : `MAILER_DSN=null://null`, D82 et D94 (un canal non raccordé refuse).
- `symfony/http-client` est là ; **aucun SOAP**, et **`ext-soap` absente** du conteneur (mesuré : PHP 8.4.26 ; openssl, sodium, dom, xml présents). `endroid/qr-code` ^6 présent ; **aucune bibliothèque de signature XML** (`robrichards/xmlseclibs` absent).

#### C-8 — Secrets et certificats

Chiffrement applicatif libsodium, **une clé d'environnement par usage**, imposée au démarrage (`compose.preprod.yaml:38-71`). Précédent exact d'un **secret confié par le client** : la clé d'API OCR, chiffrée par `ChiffreurApiKeyOcr` (`crypto_secretbox`, `OCR_API_KEY_ENCRYPTION_KEY`). Aucun certificat client (`.p12`, mTLS) n'est géré nulle part. `.gitignore` n'a **aucun motif générique `*.p12`, `*.pfx`, `*.key`**.

#### C-9 — Version et pages légales

Aucune version du produit : ni fichier `VERSION`, ni tag, ni `version` dans `composer.json` ; le déploiement écrit le commit et la date (`infra/deploy-preprod.sh`, `GET /plateforme/version-chargee`, qu'aucun écran n'appelle). **Aucune page « À propos »** ; `MentionsLegales.jsx` génère les documents publics **de l'exploitant**, pas de l'éditeur.

#### C-10 — Langue

Aucune bibliothèque i18n, aucune langue sur l'établissement, `'fr-FR'` écrit en dur 132 fois dans le frontend. Le seul `t()` traduit le vocabulaire métier. → dépend de l'autre chantier.

#### C-11 — Hébergement, sauvegarde, purge

Hébergement : VPS OVH à **Gravelines** (France) (`infra/README.md:1-3`). Sauvegarde : un dump par jour, **conservé 14 jours, sur le même hôte** (`infra/sauvegarde-base.sh:37-90`). Aucune purge des tables de vente, de facture, NF525 ou d'écritures ; l'anonymisation RGPD ne touche que les `Client`. Le registre des traitements propose **10 ans** pour les pièces comptables (`docs/juridique/registre-traitements.md:47, 58`).

## Décisions déjà rendues, qui s'imposent

- **08/10, Maxime** : l'Espagne en entier, VERI*FACTU compris ; paiement au guichet seulement, terminal autonome (D122) ; traduction et NIF dans un autre chantier.
- **D5** : identifiants anglais dans les fichiers ajoutés.
- **D7-bis** : un événement se publie après le commit.
- **D45** : on ne modifie jamais une opération scellée ; on ajoute.
- **D61** : ce qui va sur le ticket est gravé à la vente.
- **D63-bis** : un seul calcul, plusieurs appelants.
- **D66-ter** : une migration ne fabrique pas de donnée fiscale.
- **D94** : un canal non raccordé refuse, il n'annonce jamais un succès.
- **D107** : vaut pour la France ; **inversée en Espagne** (C-4).
- **D122 à D126** : le ticket opposable (C-5), socle de ce chantier.

## Objectifs (Goals)

Les options des questions de CP-1 sont indiquées entre crochets là où elles changent l'objectif ; l'objectif est écrit dans la variante **recommandée**.

### A — Qui est dans le champ, et comment Fluvia le sait

- **G-1 — Deux niveaux de réglage, parce que le droit en pose deux.** La demande disait « par établissement » ; le droit rattache la chaîne et le mode à l'**obligé** (F-3 : « una única cadena » par obligé et par système ; Orden art. 2.c : VERI*FACTU indépendant **par obligé**). Donc :
  - **par établissement** : `pays` (`FR` ou `ES`) et `fiscalTerritory` — pour l'Espagne `''` (péninsule et Baléares, IVA), `IC` (Canaries, IGIC), `CE` et `ML` (Ceuta, Melilla, IPSI) — qui décident de l'impôt (L1), de la liste de clés de régime (L8A ou L8B), du catalogue de taux et du fuseau par défaut (`Atlantic/Canary` pour `IC`). Ces champs existent (C-2) ; ils reçoivent un écran.
  - **par obligé** [Q-A1] — le `ProfilExploitant` — : NIF (autre chantier), et un **régime de facturation espagnol** : statut (IS / autre obligé / partiellement exonéré / hors champ — entité totalement exonérée / hors champ — SII), normativa de l'impôt direct (commune / Álava / Bizkaia / Gipuzkoa / Navarre), mode (VERI*FACTU ou NO VERI*FACTU) et date d'effet. Statut et normativa sont **déclarés par l'exploitant** — Fluvia ne peut pas connaître son domicile fiscal ni son régime d'IS —, avec le texte qui fonde chaque option affiché à côté ; la déclaration est tracée (qui, quand) et ne change qu'en avant.
- **G-2 — Aucun mélange.** Un établissement français ne produit jamais un registre, une mention ou une série espagnols, et son chemin NF525 reste **identique à l'octet** (payload, chaîne, vérification). Un obligé espagnol n'a que des établissements `ES` ; un profil qui mêlerait `FR` et `ES` est refusé. Une société française qui exploite en Espagne par un établissement permanent y a un **profil distinct**, avec son NIF espagnol.
- **G-3 — Rien ne se vend en Espagne avant que tout soit en place.** Un établissement `ES` ne valide aucune vente tant que : l'obligé est complet (NIF valide, statut, normativa, mode) ; la declaración responsable de la **version en service** existe (G-28) ; en VERI*FACTU, un certificat utilisable est configuré (G-24) ; la devise est l'euro (RRSIF art. 10.2) ; le territoire est pris en charge (IPSI : voir F-6). Le refus nomme ce qui manque. Normativa forale : selon Q-C3. Statut hors champ : selon Q-A4. **Seule la caisse vend en Espagne** (décision du 08/10) : boutique en ligne, souscription d'abonnement avec échéances ou prélèvement, facturation automatique d'un no-show (`DebitPmvStrategie`), API partenaire, synchronisation hors ligne et toute autre création de vente hors du guichet sont **refusées** pour un établissement `ES`, avec un message, et un test par point d'entrée.
- **G-4 — Le traitement fiscal espagnol d'une ligne est complet, daté et gravé.** Le catalogue légal reçoit, sourcés et datés (le patron de `SeedLegalVatRatesCommand`) : 21 %, 10 % (art. 91.Uno.2.2º, 2.6º, 2.8º), l'exonération E1 et sa cause (art. 20.Uno.13º, 14º), la non-sujétion N1 (art. 7.8º) ; IGIC 7 % et 3 % avec leur vraie source (loi 4/2012 art. 51, 54.2) et ses exonérations (art. 50.Uno.13º, 14º). La correspondance comptable d'une catégorie porte, pour l'Espagne : **impôt** (IVA / IGIC / IPSI), **qualification** (S1, N1, E1), **cause** d'exonération ou de non-sujétion, **taux**, **clé de régime**. Tout cela est **gravé sur la ligne** à sa création (même mécanisme que D123) et jamais relu. Une ligne sans traitement espagnol complet est **refusée avant paiement** (même règle que D123, Q-B3).
- **G-5 — Toute vente espagnole est une facture enregistrée** [Q-A3], y compris une vente exonérée qui n'exigerait pas de facture et y compris pour une entité partiellement exonérée : aucun réglage ne permet de vendre sans registre (art. 201 bis.1.b vise un système qui « permite no reflejar » une transaction).

### B — Registres, empreinte, chaîne : à côté de NF525

- **G-6 — Pas de facture sans son registre.** Chaque facture espagnole (F2, F1, F3, R5, R1 à R4) a son **registro de alta**, généré **dans la transaction** qui valide la vente ou émet la facture, **avant** que le document existe. Si la génération échoue, la vente n'est pas validée.
- **G-7 — Une chaîne par obligé** [Q-B2], dans un **stockage propre**, append-only — jamais `nf525_operation_scellee`, jamais la chaîne des factures —, altas et anulaciones mêlées. Deux validations simultanées sur deux caisses d'un même obligé sont **sérialisées par un verrou explicite** sur la tête de chaîne (le patron `FOR UPDATE` de `GenerateurNumeroFacture`), pas seulement par une contrainte unique. Unicités : (obligé, rang) ; (obligé, série et numéro, type de registre).
- **G-8 — L'empreinte de l'AEAT, au caractère près.** Calcul selon [H] v0.1.2 ; montants toujours écrits avec deux décimales et le point ; `FechaHoraHusoGenRegistro` = l'instant de génération dans le fuseau de l'établissement, avec son décalage ; refus de générer si le dernier registre est daté de plus d'une minute dans le futur (alarme et événement en NO VERI*FACTU). Les trois exemples officiels de [H] §6 sont des tests.
- **G-9 — Le XML gardé tel qu'il est né.** Chaque registre conserve ses octets XML, conformes au XSD épinglé de l'AEAT (test de validation) ; les envois sont construits à partir de ces octets, jamais régénérés.
- **G-10 — NF525 cohabite** [Q-B1]. Pour un établissement espagnol, les scellements internes existants (vente, facture, écriture) **continuent**, comme contrôle d'intégrité interne, sans aucune mention NF525 sur un document espagnol ; la chaîne VERI*FACTU est la seule chaîne fiscale montrée à l'AEAT. Elles ne partagent ni stockage, ni clé, ni séquence. Pour un établissement français, rien ne change (G-2).
- **G-11 — L'annulation reste une exception.** Un registro de anulación ne sert qu'à une facture **émise par erreur** ; il demande un droit dédié et un motif ; une annulation ou un remboursement de vente passe **toujours** par une rectificative (G-17). Le numéro annulé n'est jamais réemployé.
- **G-12 — Vérifier à la demande** : l'empreinte d'un registre, la chaîne entière ou une tranche, avec le résultat « chaîné / rompu » et l'ordre des dates — exigé en NO VERI*FACTU (Orden art. 6.b, 6.e, 7.h), offert dans les deux modes parce qu'il ne coûte presque rien.

### C — La facture simplifiée, la facture complète, la rectificative

- **G-13 — En Espagne, le ticket est une facture simplifiée (F2).** Elle porte un **numéro de facture** distinct du numéro de vente interne : série de l'établissement [Q-B2], numéro **corrélatif sans trou**, attribué sous verrou dans la transaction de validation. Une validation qui échoue ne consomme aucun numéro.
- **G-14 — Le montant décide de la forme.** F2 seulement si le total ne dépasse pas **400 €** TTC, ou **3 000 €** quand toutes les lignes relèvent de l'art. 4.2 (attribut de la catégorie : installations sportives, hôtellerie-restauration, stationnement…). Au-delà, la caisse demande l'identité du client **avant** la validation et émet une F1. Seuils datés et sourcés (C-4).
- **G-15 — Le contenu de la simplifiée** (art. 7.1, F-5) : série et numéro ; date d'émission et date d'opération si elle diffère ; nom et NIF de l'obligé, **figés à la validation** (patron D124) ; libellés gravés ; taux par ligne et « IVA incluido » (ou « IGIC incluido ») ; base et quote-part **par taux** quand il y en a plusieurs ; cause d'exonération ou de non-sujétion le cas échéant ; total ; QR et phrase (G-26). **En castillan** (catalogue `es` de l'autre chantier). Ni code d'accès (D124) ni mention NF525.
- **G-16 — La facture avec destinataire.** Un client qui veut une facture à son nom l'obtient en **F1** si la caisse le sait avant la validation, ou en **F3** ensuite, en échange d'une ou plusieurs F2 (art. 15.6) : série propre au niveau de l'obligé, destinataire obligatoire, références des F2 substituées, qui ne sont pas annulées. **Jamais** une « facture justificative » française par-dessus une F2 : ce serait deux factures pour une opération.
- **G-17 — La rectificative.** Un remboursement ou une annulation de vente devient une **R5** (rectificative de simplifiée, « por diferencias ») bâtie sur l'avoir en lignes négatives du ticket opposable (D125), dans une série de rectificatives de l'établissement, avec la référence de la F2 ; une F1 ou une F3 se rectifie par une R1 à R4 avec destinataire ; une F2 déjà couverte par une F3 : R5 puis F3 de la R5 ([FAQD] n° 27). En Espagne, la R5 **est** le justificatif d'avoir.
- **G-18 — Émise à la validation, remise au client** [Q-B3]. En Espagne, valider la vente **émet** l'original : il compte comme original au journal des éditions (D124) et s'imprime par défaut ; l'envoi électronique suppose le **consentement** du client, enregistré (art. 9.2). « Réimprimer » produit un **« DUPLICADO n° k »**, permis pour perte de l'original (art. 14), compté au journal.
- **G-19 — Pas de vente hors ligne en Espagne** [Q-B4] : sans serveur, pas de registre, donc pas de facture. Le lot hors-ligne (Q-A2 du ticket opposable, D122) ne s'ouvrira pas aux établissements `ES` sans spec propre.

### D — L'envoi à l'AEAT (mode VERI*FACTU)

- **G-20 — Une file qui ne perd rien et ne bloque jamais la caisse.** Chaque registre entre dans une file d'envoi **dans la même transaction** que sa génération ; un envoyeur (ordonnanceur ou Messenger) part de la file, **un obligé par lot**, 1 000 registres au plus, dans l'ordre de génération, en respectant le « t » de la dernière réponse (60 s au départ). La caisse ne l'attend jamais.
- **G-21 — Chaque réponse est gardée, chaque refus corrigé.** Par lot : CSV, état global, horodatage ; par registre : état et code d'erreur. `Incorrecto` → un **nouvel** alta `Subsanacion=S`, `RechazoPrevio=X`, généré dans la chaîne, le registre d'origine intact ; `AceptadoConErrores` → `Subsanacion=S`. Sans réponse : renvoi du même lot jusqu'à réponse, sans dupliquer.
- **G-22 — Une panne se voit.** `Incidencia=S` sur les lots retardés ; nouvel essai **au moins une fois par heure** ; tant qu'un registre attend, un **bandeau** sur la caisse et l'administration de l'obligé dit combien et depuis quand ; une notification (`platform_notification`, gravité « attention », puis « critique » au-delà d'un seuil à fixer au plan) à l'exploitant ; une alerte au support de Fluvia.
- **G-23 — Préproduction contre préproduction.** La préprod de Fluvia ne parle **qu'**aux points d'accès de préproduction de l'AEAT (`prewww1`, `prewww2`) ; le point d'accès de production n'existe que dans la configuration de production ; un test le prouve.
- **G-24 — Le certificat** [Q-C1]. Recommandé : d'abord le certificat de l'exploitant (de préférence un certificat de **sceau**), puis Fluvia en représentant dès que la convention 017 est signée. Dans tous les cas : jamais dans le dépôt (motifs `*.p12`, `*.pfx`, `*.key` ajoutés au `.gitignore`), chiffré au repos par libsodium sous une **clé d'environnement dédiée** (patron `ChiffreurApiKeyOcr`), déchiffré en mémoire par l'envoyeur seulement ; NIF du certificat contrôlé contre l'obligé (ou contre le représentant habilité) ; alerte **30 jours** avant l'expiration ; certificat expiré = envoi suspendu, file conservée, bandeau G-22.
- **G-25 — Changer de mode selon la règle.** NO VERI*FACTU → VERI*FACTU à tout moment ; VERI*FACTU → NO VERI*FACTU seulement au **1ᵉʳ janvier** suivant, par `FechaFinVeriFactu` dans un envoi parti avant le 31/12 ; l'écran l'explique et refuse le reste.

### E — QR, phrase, declaración responsable

- **G-26 — Le QR sur chaque facture espagnole**, dans les deux modes : « QR tributario: » au-dessus ; **35 mm** sur le ticket de 80 mm (entre 30 et 40 mm), correction M, marge d'au moins 2 mm ; premier QR du document, en haut ; URL du mode (`ValidarQR` ou `ValidarQRNoVerifactu`) et de l'environnement ; paramètres encodés ; en VERI*FACTU seulement, « VERI*FACTU » juste dessous, dans une taille comparable au reste. Le ticket espagnol ne porte **aucun autre QR** (D124 : pas de code d'accès).
- **G-27 — Le système se décrit lui-même.** Bloc `SistemaInformatico` : `NombreRazon` et identifiant du producteur (`IDOtro` tant qu'IT Cotation n'a pas de NIF espagnol), `NombreSistemaInformatico` « Fluvia », `IdSistemaInformatico` sur deux caractères, `Version` = la **version du système de facturation** [Q-D1], `NumeroInstalacion` **par obligé**, jamais réemployé, `TipoUsoPosibleSoloVerifactu` [Q-A2], `TipoUsoPosibleMultiOT=S`, `IndicadorMultiplesOT` calculé par utilisateur.
- **G-28 — La declaración responsable dans le logiciel** [Q-D1]. Une entrée « Declaración responsable » dans le menu d'aide de l'application, pour tout utilisateur d'un établissement espagnol, affiche la déclaration de la **version en service**, dans la forme exacte de l'Orden art. 15.1 (a à l, en castillan), et la donne en PDF ; une URL publique la sert aussi au client et au distributeur. Les déclarations de **toutes** les versions sont conservées, immuables, dans le dépôt. Une version sans déclaration n'émet aucune facture espagnole (G-3).
- **G-29 — L'obligé toujours visible.** Sur la caisse et les écrans de facturation d'un établissement espagnol, le nom et le NIF de l'obligé restent affichés ; un utilisateur qui travaille pour plusieurs obligés en est averti (Orden art. 2.d).

### F — Conservation, hébergement, accès de l'AEAT

- **G-30 — Rien ne disparaît** [Q-C4]. Factures, registres, événements, lots et réponses espagnols sont conservés **dix ans** après la fin de l'année de leur émission, y compris après la fin du contrat ; aucune purge ne les touche (test).
- **G-31 — L'export.** Par obligé et par période : les registres en XML (les octets gardés, format de l'annexe), les événements en NO VERI*FACTU, les factures en PDF ; accessible à l'exploitant depuis l'application, et remis en entier à la fin du contrat (Orden art. 8.3).
- **G-32 — Hébergé en France, dit à l'AEAT, ouvert à l'AEAT** [Q-C2]. L'activation espagnole présente à l'exploitant ce qu'il doit communiquer avant tout (conservation hors d'Espagne, art. 22.2 : pays, hébergeur, modalités d'accès en ligne) et enregistre la date à laquelle il dit l'avoir fait ; tant qu'elle manque, un avertissement reste affiché. Un **accès de consultation fiscale** en lecture seule — recherche, affichage, téléchargement, impression des factures et des registres de l'obligé — peut être ouvert par l'exploitant à la demande de l'AEAT (art. 21.2 et 23).

### G — Le mode NO VERI*FACTU (selon Q-A2 : lot suivant, dans ce chantier)

- **G-33 — Signer.** Chaque registre et chaque événement en XAdES Enveloped EPES, politique de l'AGE, avec un certificat qualifié (de l'obligé, de son représentant ou d'un collaborateur social) ; vérification de la signature à la demande.
- **G-34 — Le registre d'événements.** Chaîne propre par obligé, types L2E, **résumé toutes les 6 heures** de fonctionnement et avant tout arrêt ; ce qu'est un « arrêt » pour un service en ligne : **À VÉRIFIER**.
- **G-35 — L'alarme.** Une anomalie d'intégrité ou de chaîne allume une alarme visible qui ne s'éteint pas tant qu'elle n'est pas levée, et produit un événement.
- **G-36 — Le requerimiento.** Remise des registres conservés par le service `RequerimientoSOAP`, avec `RefRequerimiento`, tels qu'ils ont été générés.

### H — TicketBAI et Navarre (selon Q-C3 : lot à part)

- **G-37** — Dans ce chantier : un obligé de normativa **basque** est **refusé** à l'activation, avec la raison ; un obligé de normativa **navarraise** est servi **sans registre ni QR** tant qu'aucun système navarrais n'est publié (statut verrouillé comme en Q-A4) ; la publication de NaTicket rouvre la question.

## Cas limites

| Cas | Comportement attendu | G |
|---|---|---|
| Établissement français, vente ordinaire | payload, chaîne et vérification NF525 identiques à l'octet ; aucun registre | G-2 |
| Profil qui rattacherait un établissement `FR` et un `ES` | refus | G-2 |
| Établissement `ES` sans declaración pour la version en service | aucune vente validée ; le refus nomme la déclaration | G-3, G-28 |
| Établissement `ES` en devise autre que l'euro | refus | G-3 |
| Commande en ligne, souscription avec échéances, API partenaire sur un établissement `ES` | refus explicite | G-3 |
| Catégorie sans traitement fiscal espagnol complet | refus à l'ajout au panier | G-4 |
| Vente de 120 € d'entrées de musée | F2 | G-13, G-14 |
| Vente de 450 € d'entrées de musée | identité demandée avant validation, F1 | G-14, G-16 |
| Location de courts pour 2 500 € | F2 (art. 4.2.i) | G-14 |
| Client qui veut une facture à son nom après coup | F3 en échange de la F2, F2 non annulée | G-16 |
| Remboursement partiel d'une F2 | R5 par différences, lignes négatives au taux gravé | G-17 |
| Remboursement d'une F2 déjà couverte par une F3 | R5, puis F3 de la R5 | G-17 |
| Facture d'essai émise par erreur | registro de anulación, droit dédié, motif ; numéro jamais réemployé | G-11 |
| Deux caisses d'un même obligé valident en même temps | registres sérialisés, chaîne intacte, numéros sans trou | G-7, G-13 |
| Validation qui échoue après l'attribution du numéro | transaction annulée, numéro non consommé, aucun registre | G-6, G-13 |
| Horloge du serveur en avance puis corrigée | refus de générer tant que le dernier registre est daté de plus d'une minute dans le futur | G-8 |
| AEAT injoignable pendant six heures | ventes normales ; bandeau avec le nombre de registres en attente ; essais au moins horaires ; envoi dans l'ordre avec `Incidencia=S` | G-20, G-22 |
| Lot sans réponse (délai dépassé) | même lot renvoyé jusqu'à réponse | G-21 |
| Registre refusé par l'AEAT | nouvel alta `Subsanacion=S`, `RechazoPrevio=X` ; l'original reste | G-21 |
| Certificat expiré | envoi suspendu, file gardée, bandeau ; alerte 30 jours avant | G-24 |
| Préprod de Fluvia | ne parle qu'à la préproduction de l'AEAT | G-23 |
| Demande de passer en NO VERI*FACTU en juin | refusée jusqu'au 1ᵉʳ janvier suivant | G-25 |
| Vente le 31/12 à 23:59:30, registre suivant le 01/01 | chaîne continue, séries de la nouvelle année | G-7, G-13 |
| Établissement aux Canaries | impôt 03, clés L8B, IGIC 7 % ou 3 %, fuseau `Atlantic/Canary` | G-1, G-4 |
| Établissement à Ceuta ou Melilla | refusé tant que les taux d'IPSI ne sont pas sourcés | G-3 |
| Obligé de normativa basque | refusé à l'activation (Q-C3) | G-37 |
| Réimpression d'une F2 | « DUPLICADO n° k », comptée | G-18 |
| Établissement `ES` sans réseau | aucune vente | G-19 |
| Fin de contrat | export complet remis ; conservation continuée | G-30, G-31 |

## Hors périmètre

- **NIF et traduction** (`t()`, catalogues fr et es) : autre chantier, **prérequis** (G-3, G-15).
- **Contribuables au SII** : aucun envoi SII ; leur statut est déclaré et ils sont servis selon Q-A4.
- **Facture électronique entre entreprises** (RD 238/2026) : une F1 à un professionnel devra être électronique structurée au 06/10/2027 (plus de 8 M€) ou au 06/10/2028 : lot à part, **avant la première de ces dates** pour un client concerné (F-10).
- Facturation électronique aux administrations publiques espagnoles : non étudiée ici.
- **TicketBAI** (selon Q-C3) et **NaTicket** (non publié).
- **IPSI** de Ceuta et Melilla : taux non lus, territoire refusé d'ici là.
- Recargo de equivalencia, régime simplifié, critère de caisse, REAGYP, agences de voyages : refusés à la configuration.
- Comptabilité espagnole (PGC), libros registro, modèles 303 et 390.
- Paiement en ligne, boutique, abonnements à échéances, SEPA en Espagne (G-3).
- Caisse hors ligne (G-19).
- ESC/POS (comme en France).
- **Sauvegarde hors site** : la sauvegarde actuelle (14 jours, même hôte, C-11) ne garantit pas une conservation de dix ans ; c'est un lot d'infrastructure, **prérequis** avant le premier client espagnol (voir Contradiction, R-4).
- Certification NF525 ; homologation (il n'en existe pas en Espagne : la declaración responsable en tient lieu).

## Parcours utilisateur / UX

1. **Activer l'Espagne** (administrateur) — sur l'établissement : pays, territoire, fuseau, devise. Sur l'exploitant : NIF, statut, normativa, mode, chacun avec son texte ; le certificat (G-24) ; la communication préalable (G-32). Une liste de contrôle dit ce qui manque ; tant qu'elle n'est pas verte, la caisse refuse avec la même liste.
2. **Vendre** (caissier) — en-tête : nom et NIF de l'obligé. Panier, règlement (D122 inchangé), validation : la F2 s'imprime, QR en haut. Au-delà du plafond, la caisse demande l'identité du client avant de valider (F1).
3. **Facture au nom du client** — à la caisse avant validation (F1), ou depuis l'historique ensuite (F3).
4. **Rembourser** — même geste qu'en France (D125) ; le papier est une R5.
5. **Panne d'envoi** — bandeau « 37 factures pas encore transmises à l'AEAT depuis 14:05 — nouvel essai à 15:05 » ; rien ne bloque la vente.
6. **Declaración responsable** — menu Aide ; PDF téléchargeable.
7. **Exporter** — par période, depuis l'administration.

## Contraintes & décisions techniques connues

- **Ordre de construction** : après les lots 6 à 9 du ticket opposable (taux gravé, ventilation, avoirs en lignes, document et journal des éditions) et le chantier NIF et traduction. Ce qui n'en dépend pas peut partir avant : réglages (G-1), catalogue (G-4), chaîne et empreinte (G-7, G-8), envoyeur (G-20 à G-23), declaración (G-28).
- **SOAP sans `ext-soap`** : enveloppe XML postée par `symfony/http-client` avec le certificat client — fiche de référence à produire au plan (version installée), sinon ajout de l'extension à l'image (décision du plan).
- **Signature XAdES** (NO VERI*FACTU) : aucune bibliothèque présente ; choix et fiche au plan.
- **QR** : `endroid/qr-code` ^6, déjà utilisé.
- **Migrations à la main**, `BINARY(16)`, SQL demandé à Doctrine avant ; append-only déclaré dans `InalterabiliteListener::estAppendOnly()` ; cloisonnement par la liste blanche existante.
- **Horloge** : l'obligé doit garantir une minute de précision (Orden art. 7.f) ; la synchronisation NTP du VPS est à mesurer au plan (**À VÉRIFIER**).
- **Documents techniques de l'AEAT épinglés** (§Sources) ; toute nouvelle version se relit avant d'être suivie.
- **Revue** : `relecteur` et `security-reviewer` en adversaire à chaque étape (fiscal, NF525, secret, cloisonnement).
- **D5** : identifiants anglais.

## Points UNVERIFIED / À VÉRIFIER

**Bloquants pour CP-1** : aucun ; les questions du §CP-1 tranchent ce qui est décision.

**Bloquants pour le plan (CP-2), chacun sur l'étape qu'il nomme :**

- [ ] **Convention 017 pour un éditeur étranger** : IT Cotation (société française) peut-elle la signer, et lui faut-il un NIF espagnol et un certificat admis par la sede ? Bloque la variante « Fluvia représentant » de G-24.
- [ ] **Certificat de sceau** : admis pour l'envoi VERI*FACTU (le WSDL expose un point d'accès « Sello », `www10`), et pour quels obligés ? Bloque G-24.
- [ ] **Procédures IZ862 et IZ863** (apoderamiento) : libellé et portée pour l'envoi par web service.
- [ ] **Forme de la communication préalable** de l'art. 22.2 RD 1619/2012 (déclaration censale ? formulaire de la sede ?) et ce qu'elle doit dire. Bloque le texte de G-32.
- [ ] **Langue de la declaración responsable** : rien trouvé ; castillan retenu par prudence (titre imposé en castillan par l'Orden).
- [ ] **NIF espagnol d'IT Cotation** : en a-t-elle un ? Sinon `IDOtro` (admis, Orden art. 15.1.i).
- [ ] **Taux d'IPSI** (Ceuta, Melilla) : non lus.
- [ ] **IGIC** : changements de taux en 2025-2026 (le consolidé du BOE est au 30/12/2024).
- [ ] **Cas limites du Concierto** : société de territoire commun au-delà de 12 M€ et 75 % d'opérations au Pays basque — normativa forale, donc TicketBAI ? Le RD (domicile) et la FAQ (normativa) divergent.
- [ ] **« Arrêt » d'un service en ligne** pour le résumé d'événements (G-34).
- [ ] **Empreinte et montants** : l'AEAT recalcule-t-elle sur la chaîne reçue ou sur une valeur normalisée ? G-8 écrit toujours deux décimales, ce qui est sûr dans les deux cas.
- [ ] **Marge tolérée sur `FechaHoraHusoGenRegistro`** côté AEAT ([VAL] §20 ne la chiffre pas).
- [ ] **Synchronisation d'horloge du VPS** (NTP), à mesurer.
- [ ] **Dates d'application du RD 238/2026** : 06/10/2027 et 06/10/2028 sont un calcul de la session à partir de l'entrée en vigueur de l'Orden HAC/1028/2026.

## CP-1 — questions pour Maxime

Quatre blocs, à poser en QCM (outil de sélection), quatre questions au plus par bloc. Chaque option dit ce qu'elle coûte ; la recommandée est marquée.

### Bloc A — Le champ et le réglage

**Q-A1 — Qui est « l'obligé » dans Fluvia ?**
- **A (recommandé) — Le `ProfilExploitant`**, qui porte déjà raison sociale, adresse, établissements rattachés, séries et chaîne des factures : on lui ajoute le NIF (autre chantier) et le régime espagnol. Une société française avec un établissement permanent en Espagne a un second profil pour ses établissements espagnols. *Conséquence* : une seule source d'identité ; un groupe franco-espagnol se règle en deux profils.
- **B — Une nouvelle entité « obligé espagnol »** rattachée aux établissements, à côté du profil. *Conséquence* : deux sources pour la raison sociale et l'adresse, à tenir d'accord ; plus de code.

**Q-A2 — Les deux modes : quand ?**
- **A (recommandé) — VERI*FACTU d'abord, NO VERI*FACTU en lot suivant, dans ce chantier**, avant le premier client qui le demande. Les premières versions se déclarent « solo VERI*FACTU » (Orden art. 15.1.e), la suivante non, avec sa propre déclaration. *Conséquence* : la première mise en service ne porte que les exigences allégées de VERI*FACTU (ni signature, ni registre d'événements, ni conservation des registres — Orden art. 3), donc moins de prise à l'art. 201 bis ; un client qui refuse l'envoi en temps réel attend le second lot.
- **B — Les deux modes ensemble.** *Conséquence* : signature XAdES, bibliothèque à choisir, registre d'événements, alarme, export et requerimiento avant la première vente : délai plus long, surface de non-conformité plus large dès le départ.
- **C — VERI*FACTU seulement, pour de bon.** *Conséquence* : le plus court ; « il faut tout prévoir » n'est pas tenu, et un client qui veut garder ses registres chez lui ne peut pas prendre Fluvia.

**Q-A3 — Une vente qui n'exige pas de facture (sport ou culture exonérés d'une entité sociale, part exonérée d'un club) ?**
- **A (recommandé) — Toujours une F2 enregistrée.** *Conséquence* : un seul chemin ; l'AEAT voit tout ; aucun réglage ne permet de ne pas enregistrer (art. 201 bis.1.b) ; ces factures, facultatives, partent quand même à l'AEAT.
- **B — Un justificatif non fiscal**, sans registre ni QR, pour ces ventes. *Conséquence* : classer chaque ligne « avec ou sans obligation » ; deux sortes de papier ; une erreur de classement fait une vente non enregistrée, et l'éditeur fournit l'interrupteur.

**Q-A4 — Un exploitant hors champ (commune ou organisme autonome en régie directe, contribuable au SII) ?**
- **A (recommandé) — Servi, avec des factures sans registre, ni QR, ni phrase**, sur son statut déclaré à l'activation, tracé, et **verrouillé après la première facture** (un changement passe par le support, tracé). *Conséquence* : la régie directe, cœur de Fluvia en France, reste vendable en Espagne ; le contribuable au SII envoie ses propres registres SII par ses moyens ; l'éditeur fournit un statut qui coupe l'enregistrement, d'où le verrou et la mention dans la declaración.
- **B — Refusé** tant qu'un lot ne l'a pas étudié. *Conséquence* : aucun interrupteur ; les communes espagnoles ne peuvent pas prendre Fluvia.
- **C — Servi, et Fluvia construit l'envoi SII.** *Conséquence* : un second protocole d'envoi, délai de 4 jours ; hors de proportion aujourd'hui.

### Bloc B — La chaîne et les documents

**Q-B1 — Un établissement espagnol garde-t-il les scellements NF525 internes ?**
- **A (recommandé) — Oui, comme contrôle interne**, sans aucune mention NF525 sur un document espagnol ; la chaîne VERI*FACTU est la seule chaîne fiscale. *Conséquence* : aucune branche dans `ValiderVenteService`, les avoirs, les clôtures Z et journalières, la facturation et la comptabilité ; deux mécanismes à stocker pour une vente espagnole.
- **B — Non, coupés pour l'Espagne.** *Conséquence* : une seule chaîne par vente, mais une condition dans chacun de ces chemins — autant de façons de casser la France —, et les clôtures perdent leur scellement en Espagne.

**Q-B2 — Une chaîne par obligé ou par établissement ? Et quelles séries ?**
- **A (recommandé) — Une chaîne par obligé** (un `NumeroInstalacion` par obligé), **séries par établissement**, par type (simplifiées, rectificatives de simplifiées) et par année ; complètes, F3 et leurs rectificatives par obligé. *Conséquence* : lecture la plus sûre de l'Orden art. 7.c pour un système central (FAQ : une chaîne par couple système-obligé) ; toutes les caisses d'un obligé se sérialisent sur un verrou, ce qui tient au volume d'un guichet.
- **B — Une chaîne par établissement**, chaque établissement déclaré comme une installation. *Conséquence* : moins d'attente entre caisses ; mais la FAQ ne réserve la pluralité de chaînes qu'aux TPV qui facturent **de façon autonome**, ce que des caisses reliées en temps réel au même serveur ne font pas : risque de lecture contraire de l'AEAT.
- **C — Séries par point de vente** (avec chaîne par obligé). *Conséquence* : des dizaines de séries ; aucun gain, puisque la chaîne sérialise déjà.

**Q-B3 — Quand le ticket espagnol est-il remis ?**
- **A (recommandé) — À la validation** : c'est l'original, compté au journal des éditions, imprimé par défaut ; par e-mail seulement avec le consentement du client, enregistré. *Conséquence* : en Espagne, la règle française « une impression ne compte que si une imprimante la confirme, à la demande » (D124) cède, parce que la facture se remet à l'émission (art. 17-18) ; plus de papier qu'en France.
- **B — À la demande, comme en France.** *Conséquence* : une facture émise mais pas remise ; contraire aux art. 17-18 RD 1619/2012.

**Q-B4 — La vente hors ligne en Espagne ?**
- **A (recommandé) — Interdite** : sans serveur, ni registre ni facture. *Conséquence* : un guichet espagnol sans réseau ne vend pas (il n'existe de toute façon pas de caisse hors ligne aujourd'hui, C-6).
- **B — Confiée au lot hors-ligne**, avec registres produits sur le poste. *Conséquence* : une chaîne par poste (chaque poste devient un SIF autonome), son propre `NumeroInstalacion`, la signature locale : un chantier en soi.

### Bloc C — Envoi, hébergement, pays forals

**Q-C1 — Avec quel certificat Fluvia envoie-t-il ?**
- **A — Le certificat de chaque exploitant**, téléversé et chiffré. *Conséquence* : marche dès le premier jour, sans démarche auprès de l'AEAT ; Fluvia détient des certificats qui ouvrent, selon leur type, d'autres démarches fiscales du client ; un renouvellement par client tous les deux ou trois ans.
- **B — Fluvia en représentant** : convention de collaboration sociale 017, et une représentation signée par chaque client (Anexo I). *Conséquence* : un seul certificat à protéger, limité à cet usage ; dépend de l'AEAT (éligibilité d'une société française **À VÉRIFIER**, délai inconnu) ; chaque client signe un document, à la main ou par signature qualifiée — jamais par simple acceptation.
- **C (recommandé) — A pour démarrer, de préférence un certificat de sceau ; B dès que la convention est signée.** *Conséquence* : pas d'attente ; le risque de détenir des certificats clients est borné dans le temps ; deux chemins d'envoi à tenir pendant la transition.

**Q-C2 — Les données espagnoles restent-elles en France ?**
- **A (recommandé) — Oui**, avec la communication préalable faite par l'exploitant (guidée et datée dans l'application) et un accès de consultation fiscale ouvert à la demande de l'AEAT. *Conséquence* : une seule infrastructure ; l'exploitant porte la démarche, Fluvia la lui rend facile et visible.
- **B — Hébergement en Espagne** pour les clients espagnols. *Conséquence* : pas de communication préalable ; une seconde infrastructure, ses sauvegardes, sa supervision.
- **C — En France, sans s'en occuper.** *Conséquence* : moins de code ; l'exploitant qui l'ignore est en infraction sur la conservation, et le découvre en contrôle.

**Q-C3 — TicketBAI et la Navarre ?**
- **A (recommandé) — Lot à part, après VERI*FACTU** ; d'ici là, un obligé de normativa basque est refusé à l'activation ; un obligé navarrais est servi sans registre tant qu'aucun système navarrais n'est publié. *Conséquence* : le Pays basque attend ; le travail de VERI*FACTU (impôt, séries, QR, file, certificat) sert ensuite, mais la chaîne (sur la signature), le format, l'inscription au registre foral et le LROE de Bizkaia sont propres à TicketBAI.
- **B — Dans ce chantier.** *Conséquence* : deux formats, deux chaînes, une inscription par l'éditeur, trois QR, deux voies d'envoi, avant toute première vente.
- **C — Jamais.** *Conséquence* : le Pays basque est fermé à Fluvia.

**Q-C4 — Combien de temps conserver ?**
- **A — Six ans après la fin de l'année d'émission** (Code de commerce art. 30), y compris après la fin du contrat. *Conséquence* : le minimum défendable ; un contrôle qui remonte plus loin (LGT art. 66 bis et 70.3) trouve un vide.
- **B (recommandé) — Dix ans**, aligné sur le registre des traitements de Fluvia pour les pièces comptables. *Conséquence* : couvre les contrôles longs ; justification RGPD déjà écrite ; plus de stockage.
- **C — Jusqu'à la fin du contrat, puis export remis et suppression.** *Conséquence* : l'exploitant porte seul la conservation ensuite (Orden art. 8.3 l'y oblige de toute façon) ; risque qu'il perde l'export.

### Bloc D — La version

**Q-D1 — Qu'est-ce qu'une « version » qui demande sa declaración ?**
- **A (recommandé) — Une version du système de facturation, distincte des déploiements** (« 1.0 », « 1.1 »…), qui change à toute modification d'un chemin de facturation espagnole ; chaque version a sa déclaration, signée par Maxime (date et lieu), versée au dépôt ; un garde-fou de la CI refuse une modification de ces chemins sans nouvelle version et nouvelle déclaration. *Conséquence* : Maxime signe une déclaration par version de facturation, pas par déploiement ; la liste des chemins se fixe au plan.
- **B — Chaque déploiement est une version** (son commit), avec une déclaration générée. *Conséquence* : plusieurs déclarations par semaine ; une « signature » générée sans geste de Maxime tient mal comme engagement du producteur.

## Critères d'acceptation

- **G-1** : réglage de `pays`, `fiscalTerritory` et du régime espagnol par écran ; chaque déclaration de statut et de normativa est tracée ; un changement « en arrière » est refusé.
- **G-2** : sur un jeu de ventes françaises, payloads NF525, chaînes et vérification identiques avant et après le chantier (comparaison à l'octet) ; aucune ligne dans les tables espagnoles ; profil mixte `FR` + `ES` refusé.
- **G-3** : chacune des conditions manquantes refuse la validation et est nommée ; chaque point d'entrée autre que la caisse refuse une vente pour un établissement `ES` (un test par point d'entrée).
- **G-4** : chaque taux, exonération et non-sujétion porte sa source et sa date ; une ligne sans traitement complet est refusée à l'ajout ; un changement de correspondance après la vente ne change pas le registre ni le duplicado.
- **G-5** : aucun réglage ni rôle ne valide une vente `ES` sans registre (recherche des chemins de validation, test par chemin).
- **G-6** : échec forcé de la génération → vente non validée, aucun numéro consommé, aucun document.
- **G-7** : 50 validations concurrentes sur deux caisses d'un même obligé (connexions distinctes) → chaîne vérifiée sans rupture, rangs et numéros sans trou ; test réellement concurrent.
- **G-8** : les trois exemples de [H] §6 donnent les empreintes officielles ; un oracle indépendant recalcule l'empreinte de 1 000 registres générés ; horloge simulée en retard → refus.
- **G-9** : chaque registre généré valide contre le XSD épinglé ; le lot envoyé contient les octets gardés.
- **G-10** : vente `ES` → un maillon NF525 interne et un registre VERI*FACTU, aucune mention NF525 sur le document ; clés et tables distinctes (test de configuration).
- **G-11** : annulation sans le droit dédié → 403 ; annulation d'une vente remboursable → refus avec renvoi vers la rectificative ; numéro annulé jamais réattribué.
- **G-12** : registre altéré en SQL → vérification en échec, nommant le registre.
- **G-13** : numéros corrélatifs par série sur un an simulé, aucun trou après des validations en échec.
- **G-14** : 400,01 € de musée → F1 exigée ; 2 999 € de courts → F2 ; 3 000,01 € de courts → F1.
- **G-15** : le rendu contient chaque mention de l'art. 7.1 ; base par taux sur une vente à deux taux ; cause d'exonération sur une ligne E1 ; aucun code d'accès, aucune mention NF525.
- **G-16** : F3 référence les F2 ; les F2 restent ; F1 et F3 ont un destinataire ; aucune facture justificative française pour un établissement `ES`.
- **G-17** : remboursement partiel → R5 aux montants de l'avoir, au centime ; F2 couverte par F3 → R5 puis F3 de la R5.
- **G-18** : validation → original compté ; réimpression → « DUPLICADO n° 2 » ; e-mail sans consentement enregistré → refus.
- **G-19** : aucune vente `ES` acceptée par la synchronisation hors ligne.
- **G-20** : 2 500 registres en attente → trois lots (1 000, 1 000, 500), dans l'ordre, espacés selon « t » (horloge simulée) ; jamais deux obligés dans un lot.
- **G-21** : réponse simulée `Incorrecto` → nouvel alta de subsanación chaîné, original intact ; délai dépassé → même lot renvoyé.
- **G-22** : AEAT simulée injoignable → essai au moins horaire, `Incidencia=S`, bandeau avec le compte exact, notification.
- **G-23** : configuration de préprod → aucun hôte de production de l'AEAT joignable par l'envoyeur (test).
- **G-24** : aucun fichier `*.p12`, `*.pfx`, `*.key` versionnable ; certificat stocké chiffré, illisible sans la clé ; NIF discordant refusé ; alerte à J-30 ; expiré → envoi suspendu.
- **G-25** : VERI*FACTU → NO VERI*FACTU en cours d'année refusé ; `FechaFinVeriFactu` envoyé avant le 31/12.
- **G-26** : QR décodé = URL attendue (mode, environnement, encodage) ; taille mesurée dans le PDF entre 30 et 40 mm ; « VERI*FACTU » présent en VERI*FACTU, absent sinon.
- **G-27** : bloc `SistemaInformatico` valide contre le XSD ; `NumeroInstalacion` distinct par obligé ; `IndicadorMultiplesOT` vrai pour un utilisateur à deux obligés.
- **G-28** : la page affiche a) à l) dans l'ordre, libellés compris ; version en service sans fichier de déclaration → aucune facture `ES`.
- **G-29** : nom et NIF de l'obligé présents sur la caisse ; avertissement pour un utilisateur à deux obligés.
- **G-30** : aucune commande de purge ne supprime ni n'anonymise une donnée fiscale espagnole (test).
- **G-31** : l'export d'une période contient chaque registre, octet pour octet, et chaque facture.
- **G-32** : sans date de communication préalable → avertissement ; accès de consultation en lecture seule : aucune écriture possible (test).
- **G-33 à G-36** : critères écrits avec le lot NO VERI*FACTU.
- **G-37** : obligé de normativa basque refusé à l'activation ; obligé navarrais servi sans registre.

## Sources

Lues par la session : RD 1007/2023 (BOE-A-2023-24840, consolidé au 03/12/2025) ; RDL 15/2025 (BOE-A-2025-24446, convalidation du 11/12/2025) ; Orden HAC/1177/2024 (BOE-A-2024-22138) ; RD 1619/2012 (BOE-A-2012-14696) ; LGT (BOE-A-2003-23186) art. 29, 66, 70, 201 bis ; LIVA (BOE-A-1992-28740) art. 7, 20, 90, 91 ; LIS (BOE-A-2014-12328) art. 9 ; Code de commerce (BOE-A-1885-6627) art. 30 ; RD 238/2026 (BOE-A-2026-7295) ; Orden HAC/1028/2026 (BOE-A-2026-20587) ; sommaires du BOE du 05 au 08/10/2026 ; FAQ de la sede « colaboración social » et « trazabilidad » (mises à jour le 07/10/2026).

Lues par les agents de recherche (sites officiels seulement) :
- **[H]** Especificaciones huella, v0.1.2, 27/08/2024 ;
- **[QR]** Código QR y servicio de cotejo, v0.5.0, 10/12/2025 ;
- **[WS]** Descripción servicios web, v1.0.3, 28/07/2025, et WSDL `SistemaFacturacion.wsdl` ;
- **[DR]** Diseños de registro, v1.0, 28/10/2024 ;
- **[VAL]** Validaciones y errores, v1.2.2, 08/04/2026 ;
- **[FIR]** Especificaciones de firma, v0.1.5, 06/03/2025 ;
- **[FAQD]** Aclaraciones para desarrolladores, v1.3, 04/12/2025 ;
- FAQ VERI*FACTU de la sede (mise à jour du 21/07/2026) ;
- Concierto Económico (BOE-A-2002-9969) ; Convenio navarrais (BOE-A-1990-31117) ; loi 4/2012 (BOE-A-2012-9282) ; NF 3/2020, DF 32/2020, OF 521/2020 (Gipuzkoa) ; NF 13/2021, DF 48/2021 (Álava) ; NF 5/2020, DF 82/2020, OF 1482/2020, NF 8/2023 (Bizkaia) ; Resolución 52/2026 (BON du 16/07/2026).

## Contradiction / Réponse

### Première relecture (auteur de la spec, 08/10)

Trois axes : ce qui rendrait Fluvia non conforme, ce qui casserait NF525, ce qui exposerait l'éditeur à l'art. 201 bis.

- **R-1 — Les chaînes NF525 sont par point de vente ; VERI*FACTU veut une chaîne par obligé et par système.** Réutiliser `nf525_operation_scellee` aurait intercalé des registres espagnols dans une chaîne de caisse, ou donné autant de chaînes que de caisses. → **retenue** : stockage propre, verrou explicite (G-7), granularité en Q-B2.
- **R-2 — D107 dit l'inverse du droit espagnol.** Sans destinataire, c'est une facture simplifiée ; et le montant décide de la forme. → **retenue** : G-13, G-14, seuils en données datées.
- **R-3 — Le numéro de ticket se calcule par `COUNT + 1` sans verrou, par session.** Ce n'est pas une série corrélative ; deux caisses peuvent tirer le même rang. → **retenue** : un numéro de facture distinct, sous verrou, sans trou (G-13).
- **R-4 — Une sauvegarde de 14 jours sur le même hôte ne conserve pas dix ans.** Un disque perdu emporte les factures que l'exploitant doit garder (RD 1619/2012 art. 19 ; Orden art. 8.3). → **retenue comme prérequis** : lot d'infrastructure avant le premier client espagnol (Hors périmètre).
- **R-5 — Un statut « hors champ » est un interrupteur qui coupe l'enregistrement** : exactement ce que vise l'art. 201 bis.1.b. → **retenue** : statut verrouillé après la première facture, tracé, cité dans la declaración (Q-A4) ; l'option B le supprime.
- **R-6 — La boutique, les abonnements, l'API partenaire et le no-show créent des ventes ailleurs qu'à la caisse.** Une vente espagnole y échapperait au registre. → **retenue** : seule la caisse vend en Espagne, un test par point d'entrée (G-3), no-show automatique compris ; et quel que soit le point d'entrée, une vente espagnole validée a son registre (G-6), ce qui ferme la porte qu'un point d'entrée oublié laisserait ouverte.
- **R-7 — Le « report à octobre 2028 » venait de la facture électronique entre entreprises.** → **retenue** : F-1 corrigé, F-10.
- **R-8 — Le catalogue espagnol n'a que 21 % ; l'IGIC cite la mauvaise loi.** Un musée ou un restaurant espagnol serait taxé à 21 %. → **retenue** : G-4.
- **R-9 — Le QR fiscal doit être le premier du document.** Le ticket affiché porte aujourd'hui le code d'accès (C-5). → **retenue** : D124 l'a déjà retiré du ticket ; G-26 interdit tout autre QR.
- **R-10 — Une F2 ne peut pas porter de destinataire** ([VAL] point 13) : la simplifiée « qualifiée » de l'art. 7.2 ne passe pas en F2. → **retenue** : tout destinataire mène à une F1 ou une F3 (G-16).
- **R-11 — Une vente hors ligne serait une facture sans registre.** → **retenue** : G-19, Q-B4.

<!-- CONTRADICTION -->
