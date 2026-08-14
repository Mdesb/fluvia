# Positionnement de l'existant & recommandations produit

> Comparaison de **l'existant (logiciel AWOO + brique matérielle d'accès IT Cotation : SmartAccess / ITBOX / tourniquets)** face à la concurrence, puis **deep-dives concurrents** et **propositions** pour le nouveau produit.
> Base : analyse fonctionnelle (`00-synthese-fonctionnelle.md`) + veille (`01-veille-concurrentielle.md`).
> Date : 2026-08-13. Statut : ✅ complet — Partie A (positionnement), Partie B (6 deep-dives concurrents), Partie C (propositions).

---

# PARTIE A — Où se situe l'existant face à la concurrence

## A.0 Rappel du positionnement de départ
L'actif actuel se compose de **deux briques historiquement séparées** :
- **Le logiciel AWOO** (Club Manager admin + Caisse + Boutique en ligne) — moteur billetterie/offre/CRM/gestion/compta.
- **La brique matérielle & contrôle d'accès IT Cotation** (SmartAccess cloud, concentrateur **ITBOX**, lecteurs QR/RFID, tourniquets — aujourd'hui **PERCo**).

C'est déjà une **combinaison rare** (éditeur logiciel **+** maîtrise du matériel d'accès) — la même que les leaders **Elisath, Heitz, Horanet, SLH, Gantner/Vintia**. La majorité des billettistes SaaS (Mapado, SecuTix, Regiondo, Deciplus…) n'ont PAS cette maîtrise matérielle. **C'est le socle de crédibilité de départ.**

## A.1 Forces réelles de l'existant (à conserver / capitaliser)

| Force | Détail | Vs concurrence |
|-------|--------|----------------|
| **Moteur produit ultra-générique** | 22 types de produits + 5 facettes ; un même moteur couvre billet daté, abonnement, entrainement, séance, visite, location, boutique, bon d'échange… | Plus flexible que la plupart. Elisath cloisonne en gammes métier (ELISO/ELIGLISS/ELISART) → **l'existant est déjà « unifié » là où le leader est fragmenté.** |
| **Tarification riche** | Grille **tarifs × saisons**, **52 types de tarifs** dont sociaux (réduit, RSA, PMR, scolaire, demandeur d'emploi, famille) | Au niveau des meilleurs. Les SaaS fitness/culture sont souvent plus pauvres sur le social. |
| **Compta / régie secteur public** | Trésor Public, **mandats/SEPA**, chèques vacances/culture/loisirs, exports **CIEL/EBP**, régie | Un **vrai différenciateur** vs les SaaS venus du privé (Deciplus, Mapado, Regiondo) qui le couvrent mal. |
| **Contrôle d'accès intégré** | Compostage **AwooScan** (QR), **appairage RFID** (carte 10 entrées), marges d'avance/retard, Espace→Contrôleur→Équipement, ITBOX + tourniquets | Rare et fort. Aligne l'existant sur Elisath/Heitz/SLH/Gantner. |
| **CRM 360° + PMV** | Fiche client complète, **porte-monnaie virtuel prépayé** avec échéance, familles, réseau, adhésions/renouvellements | Solide. Le PMV prépayé est un atout piscine/patinoire. |
| **Multi-sites / réseau** | Multi-établissements, **partage du fichier client par réseau/sous-réseau** | Aligné sur Horanet (multi-sites). |
| **Abonnements récurrents** | Renouvellement auto + débit, sans engagement/résiliation, prix fixe/évolutif | Aligné sur le besoin fitness (SEPA). |
| **Groupes / B2B** | Devis convertibles, planning groupe, ClickAndPay (lien de paiement) | Bon niveau. |

## A.2 Faiblesses / écarts de l'existant (à corriger dans le nouveau)

| Faiblesse | Impact | Ce que fait la concurrence |
|-----------|--------|----------------------------|
| **🔴 3 applications séparées** (admin / caisse / boutique) | Friction, double saisie, incohérences | Les leaders offrent une expérience plus intégrée. **Ta vision de fusion Admin+Caisse répond directement à ça.** |
| **🔴 UI datée & navigation lourde** | Perception « vieux logiciel », formation longue | Mapado/DIPTICK/SLH (Swimpy) mettent en avant une **UX moderne**. Heitz/Rodrigue/AwoO souffrent d'interfaces datées → **fenêtre de tir**. |
| **🔴 Pas d'app mobile client final** | Manque d'expérience self-service | SLH **Swimpy**, Heitz **HeitzFit4**, Deciplus **Decipass**, Resamania — tous ont une app membre. |
| **🔴 Pas d'app staff / admin mobile** | Le « prof qui valide ses entrées » de ta vision n'existe pas encore | Contrôle mobile (PDA/smartphone) chez Qweekle, DIPTICK. |
| **🔴 Pas de wallet mobile** (Apple/Google, PKPass) | Retard sur une tendance forte (>4,4 Md d'utilisateurs) | SKIDATA, Axess (ski wallet) l'ont ; peu d'éditeurs FR encore → **opportunité d'avance**. |
| **🟠 Dépendance PERCo (russe)** | Risque sanctions/réputation marché public | Alternatives EU ouvertes (Automatic Systems, Magnetic/FAAC, dormakaba). |
| **🟠 Droits trop grossiers** (5 rôles globaux) | Difficile d'adapter finement l'UI aux profils | Besoin d'un modèle par permissions/module/établissement (cf. ta vision « UI adaptative »). |
| **🟠 Exports « V1 » hérités en doublon** | Dette technique, confusion | À unifier. |
| **🟠 Pas de connectivité OTA** | Canal de vente en ligne non exploité | Regiondo en a fait sa force (200+ OTA, 6 000+ musées). |
| **🟡 Pas de pilotage énergétique** | Argument collectivité manqué | Bodet/Booky le met en avant (coût énergie piscines). |
| **🟡 Casiers connectés** | Non observé | Quasi-monopole Gantner (bracelet = accès + casier + cashless). |
| **🟡 Mode hors-ligne / robustesse** | Critique en piscine (accueil ne s'arrête jamais) | Horanet communique dessus. |
| **🟡 CRM marketing automation** | E-marketing basique | Mapado/SecuTix (segmentation, campagnes, automation) plus aboutis. |

## A.3 Verdict de positionnement

**L'existant joue déjà dans la bonne catégorie** — celle des rares acteurs « **logiciel + contrôle d'accès matériel, multi-verticales publiques** » (avec Elisath, Heitz, Horanet, SLH, Gantner/Vintia). Son **moteur métier (produits, tarifs, régie, accès) est riche et souvent supérieur** à la moyenne. **Il ne perd pas sur le fond — il perd sur la forme** : UI datée, 3 apps cloisonnées, absence d'app mobile et de wallet, dépendance matérielle à risque.

**Autrement dit** : la refonte ne part pas de zéro fonctionnellement — elle doit surtout **moderniser, unifier et mobiliser** un moteur métier déjà solide, tout en corrigeant quelques angles morts (OTA, énergie, casiers, droits fins, hors-ligne). C'est exactement ce que permet ta stratégie **« socle standard unifié d'abord, puis modules verticaux »**.

> **Position cible en une phrase** : passer de *« un moteur métier puissant mais vieillissant et fragmenté »* à *« le socle unifié moderne et mobile-first pour opérateurs multi-établissements publics, avec contrôle d'accès maîtrisé et ouvert »*.

---

# PARTIE B — Deep-dives concurrents (analyse approfondie)

> ⏳ En cours : 6 recherches approfondies lancées en parallèle (Elisath ; Heitz + Horanet ; SLH + Omniris ; Vintia/Enviso + Qweekle ; Mapado + SecuTix + DIPTICK ; Fitness Xplor + Sportigo + Virtuagym). Chaque fiche détaillera modules, UX, matériel, app mobile, tarifs, forces/faiblesses, et « à leur prendre / là où les battre ».

## B.1 Elisath *(le concurrent frontal n°1)*

*(SAS, 1995, ~30 pers., filiale **Heitz System / Ekkio**, CA groupe >15 M€, **25 % du CA en R&D**)*

**Domination** : revendique **80 % du marché des piscines françaises** et **900+ références** (dont international : Megève, Abidjan, Dubaï, Maroc, Aquarena Sevran 29 M€). Clients **exclusivement collectivités + DSP** (Vert Marine, Récréa, UCPA…). Vente **marché public / UGAP**.

**6 gammes verticales sur un socle commun** : **ELISO** (piscines), **ELIGLISS** (patinoires — dont **location de patins**, parking, comptage par zone, réservation pistes), **ELISPORT** (sport/stades/golf), **ELIPARC** (parcs), **ELISART** (musées/culture), **ELIWAY** (**transports publics** !). → **débordе déjà bien au-delà de la piscine.**

**Briques logicielles** : **ELIWEB** (vente en ligne) · **ELIPILOT** (supervision équipements temps réel) · **ELIPILOT CRM** · **ELISNAPSHOT** (affichage dynamique) · **🎯 EliGreen** (optimisation énergétique) · **🎯 Tiksy** (app mobile client).
- **Fonctionnel** : omnicanal (fixe/mobile PDA/monnayeurs/en ligne), abonnements + **cartes multi-entrées rechargeables + SEPA**, **portail de gestion des familles** (quotient familial), **FMI** temps réel, caisse + **intégration PayFiP/DGFiP**, CRM/fidélisation, **support 7j/365j**.
- **App Tiksy** (transversale) : billet dématérialisé, **badge smartphone (BLE/QR)**, rechargement abo, suivi prélèvements/passages, notifs push, marque blanche.
- **Matériel propriétaire** (fabricant à Messein) : tourniquets **TriLane**, tambours rotatifs, couloirs rapides, **portillons PMR** (anti-panique), lecteurs multi-supports **RFID/QR/NFC/BLE/biométrie**, **crypto EAL5+**, capteurs de comptage.
- **🎯 EliGreen (optimisation énergétique)** : analyse/optimise/anticipe la conso d'énergie des bâtiments publics (7 paramètres, simulation d'impact). **Différenciateur rare et très vendeur** vu l'explosion des coûts énergie des piscines. **Angle à égaler ou neutraliser.**

**🔴 Faiblesses (fort soupçon, à confirmer)** :
- **Cœur logiciel probablement ancien** (base 1995) : le site **ne montre jamais le back-office métier** (que l'app client) → pattern classique d'un **logiciel d'exploitation daté/lourd** sous couche mobile récente. **Talon d'Achille probable.**
- **Petite structure (~30 pers.) pour 900+ sites** → capacité de déploiement/support tendue.
- **Trou de gamme : casiers/consignes connectés absents** du catalogue.
- **NF525 non affichée** (à sonder). **Communication opaque** (aucune démo/vidéo/avis public).
- **Position de rente** → innovation potentiellement ralentie, clients captifs = **brèche pour un challenger**.

### 🎯 À leur PRENDRE (table stakes)
1. Couverture métier piscine : **FMI, gestion familles/quotient familial, cartes multi-entrées + SEPA, location de matériel** (patins).
2. **Offre 360° guichet unique** (logiciel + matériel multi-supports + app + install + support 7j/365j).
3. **Régie publique : PayFiP/DGFiP + NF525** (et l'**afficher** là où Elisath reste muet).
4. **App client aboutie** (équivalent Tiksy, badge smartphone, marque blanche).
5. **Récit énergie** (équivalent/partenariat EliGreen) — sinon perdu d'avance sur ce critère.

### ⚔️ Là où les BATTRE
1. **UX 100 % moderne, cloud/web** vs back-office historique (30 ans) qu'Elisath **n'ose pas montrer** — attaquer par la démo, la vidéo, la transparence.
2. **Combler les trous** : **casiers connectés**, **mode hors-ligne documenté**, **API ouverte / interopérabilité** vs écosystème fermé propriétaire.
3. **Agilité & proximité** vs 30 personnes surchargées + roadmap dictée par le fonds Ekkio.
4. **Casser le verrou** : offre de **migration sans douleur depuis Elisath** (reprise données + parc matériel via lecteurs standards non-propriétaires), **TCO transparent**, viser les clients captifs au renouvellement de marché.

## B.4 Vintia/Enviso & Qweekle

### Vintia — plateforme Enviso *(ex-Gantner Ticketing, groupe SALTO — rebrand 2024)*
- **Bras logiciel d'un groupe hardware** (SALTO = serrures ; Gantner = lecteurs RFID, **casiers**, cashless piscines/parcs) → **intégration verticale software+hardware unique**.
- **Enviso** (cloud, microservices) en modules lisibles : **Sales · Trade** (B2B/revendeurs) **· Booking · Pay** (cashless) **· Admissions App** (scan NFC/caméra/code-barres) **· Guide Planning · Ticketing Widget** (e-commerce embarquable WordPress). **Docs API/développeur publiques.** *(mais 3 plateformes coexistent : Recreatex legacy + Enviso + Lequaro → offre fragmentée.)*
- **Matériel fort** : tourniquets (GT7), **casiers RFID intelligents**, cashless bracelets, kiosques self-service, POS. Interopérable mais **couplage vertical préférentiel** (lock-in).
- **Réfs premium** : Efteling, **Vulcania** (FR, déploiement 4 ans), The Shard, Qatar Museums. Benelux/DACH/UK-centré, présence FR réelle mais moindre.
- **À prendre** : nomenclature modulaire vendable, **module Trade/B2B-groupes**, **docs API + widget e-commerce**, maturité cashless/casiers. **À battre** : prix/agilité vs grands comptes, **vraie neutralité matérielle**, ancrage FR, cohérence produit (vs trio Recreatex/Enviso/Lequaro).

### Qweekle *(SaaS FR, 2017, 425+ opérateurs loisirs)*
- **Tout-en-un mobile-first** : billetterie 360° (open/**horodaté**, multi-entrées, **billetterie Pro CE/collectivités, paiement différé**, e-billet ou thermique/A4) · caisse/boutique/**F&B** · vente en ligne (**packs anniversaire/EVJF**) · planning · **bornes self-service** · CRM/fidélité/**cartes membres photo** · BI (**jauge par catégorie temps réel** sur smartphone) · **casiers/vestiaires · stock**.
- **🎯 « Qweekle Box » = passerelle hardware-agnostique** : se branche sur **tripodes/portillons/portes/électroaimants existants ou neufs** → **l'opposé du lock-in Vintia** (« pas besoin de tout racheter »). **Contrôle d'accès fixe + mobile (PDA/smartphone coupe-file)**, photo abonné anti-fraude.
- **Faiblesses** : **zéro référence nommée / 0 avis public** (crédibilité), startup (pérennité), **pas de hardware propre** (dépend de tiers pour aquatique/cashless), pas de doc API publique.
- **À prendre** : **la Box hardware-agnostique** (argument massue « on garde ton matériel »), **coupe-file mobile + jauges temps réel**, usages loisirs (anniversaires/F&B/vestiaires), fusion caisse/planning/résa sans ré-encodage, support 7/7. **À battre** : **preuve sociale/références**, écosystème accès+cashless plus complet, **API documentée**, solidité perçue.
## B.2 Heitz System & Horanet

> 🔴 **Découverte stratégique majeure** : **Ekkio Capital (fonds LBO) détient à la fois HEITZ System ET ELISATH** → **tes 2 plus gros concurrents sont sous le même actionnaire**, qui **consolide le marché du logiciel aquatique FR**. Conséquence : roadmap/tarifs pilotés par un fonds → **risque de rigidité et de hausse tarifaire** dont IT Cotation peut jouer (agilité, proximité, sur-mesure).

### HEITZ System *(1992, ~50 pers., +2 500 sites, 29 pays — LBO Ekkio)*
- **ADN = fitness/sport privé** étendu au public (« n°1 du logiciel de salle de sport en France »). Piscines/patinoires/musées = extension du socle fitness.
- **Modules** : billetterie/caisse **NF525** (multicanal web/borne/comptoir, carnets multi-visites, boutique) · **abonnements SEPA récurrents** (illimité/carnet/famille/multi-sites) · réservation créneaux + cours (aquagym, bébé nageur) + liste d'attente · **CRM fort** (segmentation, email/SMS, réactivation, **fidélité points + parrainage**) · **FMI** temps réel + blocage seuil · multilingue · API annoncées.
- **App mobile HeitzFit 4** (marque blanche, 4e génération) : réservation, achat, **carte membre QR**, suivi conso d'abo, accès digital. **Mature et soignée** (leur différenciateur B2C).
- **Matériel propriétaire** : bracelets RFID/NFC **waterproof**, **casiers électroniques**, tourniquets/portiques, PDA. Conception hardware+software interne.
- **Faiblesses** : **régie publique DGFiP peu mise en avant** (ADN privé) → point faible collectivités ; **matériel propriétaire = verrouillage** ; **mode hors-ligne non documenté** ; roadmap sous contrôle du fonds.

### HORANET *(1999, ~58 pers., CA 10,5 M€ +18 %, 121 marchés publics — indépendant)*
- **ADN = 100 % collectivités / secteur public**, **concepteur ET fabricant de son matériel**. Plateforme aquatique **AQUAGLISS/AQUALIS**. Double pilier **déchèteries** (récurrence + ancrage territorial).
- **Modules** : caisse **Sango** + **Coupe-File** (borne file d'attente) · **contrôle d'accès FMI** (RFID/QR/NFC/AMC) · **portail citoyen** (achat, **réservation créneaux, rechargement solde, pré-inscription**, 24/7, droits d'accès générés immédiatement) · **multi-sites** centralisé (droits partagés, tarif par site/profil, facturation/stats consolidées) · **communication** (notifications, contacts citoyens/écoles/assos, gestion des demandes).
- **🎯 Régie publique NATIVE** : **DGFiP + NF525 + intégration régies financières (Trésor Public)** explicitement mis en avant = **ticket d'entrée des appels d'offres collectivités**.
- **Matériel propriétaire** : **mTripod** (tourniquet), **mSwing** (portillon **PMR**), **#TAB** (borne badgeage), Sango, automates.
- **🎯 Mode dégradé hors-ligne REVENDIQUÉ** (« fonctionnement autonome indépendant du réseau ») — argument béton piscine.
- **UX** récemment modernisée (portail refondu, app « Piscines de Metz » sur l'App Store) mais reste un **industriel** (UX moins « B2C poli » que les pure-players). CRM/marketing **plus basique** que Heitz. **Franco-français** (pas d'international).
- **Preuve** : cas **Talence** (Bordeaux Métropole) — passage d'Excel à billetterie complète, équipe louée pour sa **réactivité**.

### 🎯 À leur PRENDRE
1. **App mobile citoyen en marque blanche** soignée (façon HeitzFit 4) — pas qu'un portail web.
2. **Régie publique DGFiP/Hélios native + NF525 + mode hors-ligne**, affichés explicitement (façon Horanet) — non négociable en appel d'offres.
3. **SEPA récurrent + FMI temps réel + CRM segmenté/fidélité** (parité Heitz minimum).
4. **Preuve sociale par cas clients chiffrés** (façon Talence).

### 🎯 Là où les BATTRE
1. **Matériel OUVERT vs leur propriétaire** — les deux verrouillent sur leur hardware ; « lecteurs/tourniquets standards, réversibilité des données, pas de dépendance » = argument fort pour acheteurs publics.
2. **Spécialiste collectivité pur** (vs Heitz tiré par le fitness privé) + **UX réellement moderne** (vs Horanet industriel).
3. **Agilité/proximité vs le LBO Ekkio** (Heitz+Elisath) — proximité et sur-mesure, critère d'achat prouvé (Talence).
4. **Transparence tarifaire** — aucun des deux n'affiche de prix.
## B.3 SLH Control & Omniris

> 🔴🔴 **CONSOLIDATION MAJEURE DU MARCHÉ** : **SLH Control est passé sous la « Holding ELIS-HEITZ » le 07/08/2025**. Combiné à Heitz + Elisath (déjà sous Ekkio Capital), cela signifie qu'**un seul groupe (Ekkio / Elis-Heitz) contrôle désormais ELISATH + HEITZ + SLH CONTROL** — soit **3 des plus gros acteurs billetterie/accès piscine & collectivité en France**. → Marché en **oligopolisation**. **Opportunité stratégique forte pour un challenger indépendant** : proximité, agilité, absence de hausse tarifaire post-consolidation, et **argument « éviter le fournisseur unique dominant »** en appel d'offres.

### SLH Control — Oxygène + app Swimpy *(SASU, ~7 pers., CA 2,39 M€, ~300 sites — sous holding Elis-Heitz depuis 2025)*
- **Positionnement** : plateforme **« full web » moderne**, spécialiste **piscines/patinoires collectivités** (« 35 ans d'expérience » revendiqués, entité juridique jeune reprenant un fonds historique).
- **Modules** : billetterie multicanale (guichet, **distributeurs auto**, portail web, app) · abonnements + **rechargement** · réservation créneaux · e-boutique responsive · **espace client personnalisable (marque blanche)** · contrôle d'accès (QR smartphone, pilotage obstacles, **groupes sur bornes**) · **FMI/comptage** temps réel · **interfaçage casiers**.
- **🎯 App grand public Swimpy** (marque blanche) : achat, réservation créneaux (fiches activité avec **vidéos**), rechargement abo, actus/promos, espace perso, paiement. + app musées **Muzé'y**. *(natif iOS/Android non confirmé — possible PWA/responsive.)*
- **Matériel** : tripodes, couloirs rapides, **portillons PMR**, lecteurs **bi-techno RFID+QR**, distributeurs, cartes/**bracelets silicone RFID**, **PDA mobile**. → **intégration de matériel tiers** (pas de tourniquet propriétaire prouvé).
- **Réfs** : Cherbourg (piscine Collignon), Lyon (piscines/patinoires), Pays de l'Or, Meylan.
- **Faiblesses** : **très petite équipe (7 pers.)** → capacité limitée ; **CA en recul (-9,3 %)** ; changement d'actionnaire 2025 (transition) ; **cashless/F&B/boutique peu couverts** ; régie publique non prouvée sur le site.

### Omniris Technologies *(SAS, 1996, 10-19 pers., CA 1,43 M€ — groupe Softnext)*
- **Positionnement** : **suite multi-métiers large** (issue du ski/thermal/balnéaire) — stades, parcs de loisirs/FEC, parcs animaliers, musées, sites touristiques, spas/thermes, aquatique, resorts.
- **Modules** : billetterie & gestion d'activités · contrôle d'accès · **boutique** · **bars/buvettes/restauration (F&B)** · **cashless** · **gestion hébergement** (resorts) · **NF525** · réservation en ligne. **Conception électronique interne** (matériel propriétaire probable). **Pas d'app grand public de marque identifiée** (point faible vs Swimpy).
- **Réfs prestigieuses** : **Gouffre de Padirac, Palais Idéal du Facteur Cheval**, Jardin d'Èze, Vitam'Parc, Fraispertuis City, OK Corral, Didi'land… (belle vitrine).
- **🔴 Faiblesse majeure exploitable** : **santé financière dégradée** — résultat net **négatif** (2025), **fonds propres négatifs**, alerte « capitaux propres < moitié du capital » → **risque fournisseur** à opposer en appel d'offres (garanties financières). Site produit **pauvre en détails/démos**. Moins spécialisé FMI/régie piscine que SLH.

### 🎯 À leur PRENDRE
1. **App grand public de marque façon Swimpy** (et faire mieux : **natif** iOS/Android, notifs push).
2. **La largeur de suite d'Omniris** : **cashless + F&B/buvette + boutique + hébergement** pour adresser parcs/FEC/resorts, pas que les piscines.
3. **NF525 affiché** + **FMI/régie collectivité** (socle qui gagne les appels d'offres piscine).
4. **Preuve sociale par références notoires** (constituer une vitrine de logos).

### 🎯 Là où les BATTRE
1. **Matériel maîtrisé de bout en bout** (ITBOX + tourniquets + lecteurs) vs SLH qui **intègre du tiers** et Omniris peu explicite → « fournisseur unique logiciel+matériel+SAV, robustesse milieu humide ».
2. **Solidité fournisseur** : Omniris **déficitaire/fonds propres négatifs**, SLH **7 pers. en transition** → jouer la **pérennité et la capacité de déploiement/support**.
3. **Couvrir les deux moitiés** : socle FMI/régie collectivité (terrain SLH) **+** cashless/F&B/boutique (terrain Omniris) dans une seule offre.
4. **Indépendance** face à la **consolidation Ekkio** (Elisath+Heitz+SLH) — argument anti-fournisseur-unique.
## B.4 Vintia/Enviso & Qweekle _(en attente)_
## B.5 Mapado, SecuTix & DIPTICK — benchmark UX / CRM / vente en ligne

> Ce ne sont **pas** des concurrents frontaux (ce sont des SaaS « soft-first » **sans hardware d'accès ni régie**) — mais des **modèles d'UX/CRM/en-ligne** à copier. Leur angle mort (contrôle d'accès physique, régie, hors-ligne) = **ton terrain**.

- **Mapado** *(FR, Lyon, ~600 structures, ~70 % publiques)* — UX moderne saluée (plan de salle **« Draw »**, saisie rapide, achat 3 étapes), **CRM auto-enrichi à chaque vente + segmentation instantanée + sync email/SMS**, **modèle tout-inclus sans frais transactionnels** (ex. 30 000 billets/an = 6 900 €/an), support 7j/7. Contrôle d'accès **léger** (app Scan + « Mapado Box » imprimante/TPE). ADN spectacle vivant ; **timed-entry/jauge musée non prouvé**.
- **SecuTix** *(Suisse, ELCA — 75 M billets/an, 204 sites, grands comptes : Centre Pompidou, Musée de la Marine, Opéra de Paris)* — plateforme **S-360** (billetterie+CRM+BI+accès), **timed-entry** natif, **CRM 360° + intégration Salesforce**, **distribution/OTA (44 partenaires) avec reversements auto**, mobile-first (60-70 % du trafic), anti-fraude **TIXNGO**. Lourd, cher, grand compte. **Pas de hardware d'accès propriétaire.**
- **DIPTICK** *(startup FR patrimoine — MAD Paris, Jeu de Paume, Fontevraud)* — spécialiste musées/châteaux, **site de vente 100 % personnalisable**, **éco-conception** (argument RSE marchés publics), **sans commission**. Petit éditeur, peu de recul (6,2/10), CRM/BI probablement légers.

### 🎯 Les meilleures idées à INTÉGRER dans le socle
1. **CRM auto-enrichi + vue 360° visiteur** (chaque vente alimente la fiche, zéro re-saisie) — *Mapado/SecuTix*.
2. **Segmentation dynamique + sync email/SMS + marketing automation** — *Mapado*.
3. **Timed-entry / créneaux horaires + jauge/quotas de 1ʳᵉ classe** couplés au **comptage réel aux portiques** (jauge live) — *SecuTix* + ton hardware = **différenciateur**.
4. **Tunnel d'achat mobile-first, site de vente white-label** (aux couleurs de chaque site) — *SecuTix/DIPTICK*.
5. **Distribution OTA (architecture ouverte + reversements auto)** — *SecuTix* (canal tourisme quasi absent chez les concurrents accès).
6. **Upselling produits/boutique dans le tunnel billet** + **groupes/scolaires avec paiement différé** — *SecuTix*.
7. **Modèle « tout inclus, sans commission sur billet »** (argument marché public fort) + **éco-conception affichée** — *Mapado/DIPTICK*.

### ⚔️ Là où tu les BATS
Tourniquets/portiques/SAS industriels · bornes durcies · **régie & supervision sur site** · **mode hors-ligne** · intégration matérielle bout-en-bout (un seul MOE hardware+software+install+SAV) · déploiement multi-sites terrain — **tout ce qu'un SaaS pur cloud ne sait pas faire**.
> **Positionnement** : *« la meilleure UX/CRM/vente-en-ligne du SaaS (façon Mapado/SecuTix) COUPLÉE à un contrôle d'accès physique + régie sur site que les pure-players ne fournissent pas. »*

## B.6 Fitness — Xplor (Resamania / Deciplus), Sportigo, Virtuagym

> Les 4 sont **mono-verticale fitness privé** → aucun ne fait la régie publique ni le multi-métiers. Objectif : **récupérer les briques fitness** (abonnement récurrent, SEPA, anti-impayés, app membre) pour ta verticale « salle de sport ».

- **Xplor Resamania** *(leader, chaînes/franchises, 159 €/mois + 70 €/porte, **hébergement 2 DC en France**)* — gamme **hardware la plus complète** (lecteur STid **ARC-AQ** RFID+QR, biométrie sur badge, **Gantner GAT 6150 contrôle des douches** = aquatique, tourniquets, PMR), **24/7 sans personnel** avec matériel local (accès même Internet coupé), SEPA/CB + anti-impayés, app membre self-service. **Faiblesse : support client très critiqué.**
- **Xplor Deciplus** *(milieu de gamme, indépendants/box, **tarifs transparents 79-299 €/mois**)* — **support = point fort (4,5/5)**, NF525, **« Decipass »** = **QR dynamique lié au device, fonctionne hors-ligne**, **gestion de jauge**, **notifications de refus personnalisées (impayé/renouvellement/jauge → résolution dans l'app)**. Faiblesse : app membre (DeciCoach) instable, back-office peu moderne.
- **Sportigo** *(challenger FR, 600+ clubs)* — **anti-impayés natif = argument phare** (**représentation SEPA + recouvrement automatique**), accès **QR rotatif + biométrie + reconnaissance faciale**, app marque blanche + gamification. Tarifs opaques, avis Trustpilot polarisés.
- **Virtuagym** *(NL/global, 9 000+ clients)* — riche en **coaching/engagement/communauté**, multi-PSP (GoCardless/Mollie/Adyen…). **🔴 Hébergement aux USA = point faible RGPD majeur** exploitable.

### 🎯 Briques fitness à INTÉGRER (must-have pour être crédible)
1. **Abonnement récurrent flexible** (mensuel **et** hebdo, engagement, pauses, promos, cartes cadeaux, échelonné).
2. **SEPA + CB automatisé** (mandats, paiement en ligne par l'adhérent, caution CB) multi-PSP.
3. **🎯 Moteur anti-impayés (priorité #1)** : détection + **représentation SEPA + recouvrement** (Sportigo) + **couplage accès↔impayé** (refus de badge avec **notif expliquant l'impayé + résolution immédiate dans l'app** — Decipass). **C'est le combo gagnant.**
4. **App membre mobile** : résa cours + liste d'attente, self-service paiement/abo, **badge QR dynamique lié au device + hors-ligne**, gamification — **en soignant la stabilité** (là où Deciplus/Virtuagym pèchent).
5. **Accès 24/7 sans personnel** (matériel local, jauge temps réel, restauration d'accès après paiement).
6. **CRM/rétention + NF525 + hébergement France**.

### ⚔️ Là où tu les BATS
**Multi-verticales** (complexe municipal piscine+sport+tennis+spa en un seul système — impossible pour eux) · **régie publique / marchés publics / quotient familial** (espace vierge) · **hardware aquatique** (seul Resamania l'a) · **RGPD/souveraineté** vs Virtuagym (US) · **qualité app + support FR** (failles nettes des leaders) · **transparence tarifaire**.

---

# PARTIE C — Propositions pour le nouveau produit

> Synthèse actionnable de tout ce qui précède. **À discuter pas à pas** — ce sont des recommandations, pas des décisions actées.

## C.1 — Le contexte de marché en 4 constats

1. **🔴 Le marché piscine/collectivité se consolide brutalement** : **Ekkio (Elis-Heitz) détient Elisath + Heitz + SLH Control** — 3 des plus gros. → un incumbent dominant mais **plus lent, plus cher, moins local** après consolidation.
2. **Le vrai fossé du marché = le combo « UX moderne SaaS + contrôle d'accès matériel + régie publique »**. Les leaders accès (Elisath, Horanet, Gantner/Vintia) ont **un back-office daté** ; les SaaS modernes (Mapado, SecuTix, Qweekle) **n'ont pas le hardware/régie**. **Personne ne fait excellemment les deux.**
3. **Presque personne ne couvre vraiment les 4 verticales** sur un socle unifié (seuls Elisath et Gantner/Vintia, de façon cloisonnée en gammes).
4. **Ton actif de départ (moteur AWOO riche + hardware IT Cotation) te place déjà dans le bon camp** — il faut moderniser/unifier/mobiliser, pas réinventer.

## C.2 — Positionnement recommandé (à valider ensemble)

> **« Le socle unifié moderne et mobile-first pour opérateurs multi-établissements publics — billetterie + CRM + régie + contrôle d'accès maîtrisé et OUVERT — une seule base pour piscine, patinoire, sport et musée. »**

3 promesses de rupture face au marché :
- **Moderne & mobile** (là où Elisath/Horanet/Heitz cachent un back-office daté) — UX fluide, app client **et** app staff, wallet.
- **Ouvert & sans verrou** (là où tous imposent leur matériel propriétaire) — matériel standard (OSDP/API), **reversibilité des données**, migration facile depuis Elisath.
- **Unifié** (là où le leader cloisonne en 6 gammes) — un seul socle, un seul fichier client, une seule offre pour les 4 métiers.

## C.3 — Le SOCLE STANDARD : modules & priorités (ta stratégie « socle d'abord »)

| # | Module socle | Contenu clé | Priorité |
|---|--------------|-------------|----------|
| 1 | **Offre & Tarification** | Moteur produit générique (repris/modernisé d'AWOO), **grille tarifs × saisons**, tarifs sociaux/quotient familial, stock dédié/partagé, multi-sites, multilingue | 🔴 Fondation |
| 2 | **Vente & Caisse unifiées** | **Fusion Admin+Caisse** (ta vision), UI adaptative aux droits, paiement scindé, NF525, mode vente rapide, périphériques | 🔴 Fondation |
| 3 | **Vente en ligne (Boutique)** | Tunnel moderne mobile-first, **site white-label** par établissement, timed-entry/créneaux, bénéficiaires, upselling | 🔴 Fondation |
| 4 | **CRM 360°** | Fiche unifiée auto-enrichie à chaque vente, **PMV prépayé**, familles, segmentation dynamique, réseau | 🟠 Cœur |
| 5 | **Marketing** | Sync email/SMS, campagnes, automation, promotions, fidélité, avis | 🟠 Cœur |
| 6 | **Gestion / Back-office** | Commandes, devis/groupes, stocks, ressources (encadrants), reporting/stats | 🟠 Cœur |
| 7 | **Compta & Régie publique** | **PayFiP/DGFiP, NF525, mandat/SEPA, Trésor Public**, exports CIEL/EBP | 🔴 Ticket d'entrée collectivités |
| 8 | **Socle transverse** | **Utilisateurs / rôles / droits fins** (par module/action/établissement), multi-entités, journal, API | 🔴 Fondation |

## C.4 — Les MODULES VERTICAUX (par-dessus le socle)

- **🎯 Contrôle d'accès (le cœur de ta valeur)** : compostage/QR, **appairage RFID**, marges de validation, Espace→Contrôleur→Équipement, **ITBOX ouvert (OSDP + API REST + Wiegand legacy)**, **mode hors-ligne robuste**, jauge/FMI temps réel (comptage réel aux portiques), contrôle **mobile (PDA/smartphone staff)** + **coupe-file**, **wallet (PKPass/Google)**.
- **Piscine** : FMI, cartes multi-entrées, recharges, **casiers connectés** (comblement du trou d'Elisath), contrôle douches (cf. Resamania/Gantner), bracelets étanches.
- **Patinoire** : location de patins/matériel, séances, comptage par zone.
- **Salle de sport** : **abonnements récurrents + SEPA + moteur anti-impayés** (représentation + couplage accès↔impayé), app membre, 24/7 sans personnel, cours/coachs.
- **Musée** : billets datés/horodatés, visites guidées, groupes/scolaires, expositions/jauge, boutique, **connectivité OTA**.

## C.5 — Différenciateurs prioritaires (must-win)

**À ÉGALER (table stakes — sans ça, hors-jeu en appel d'offres) :**
1. Régie publique **PayFiP/DGFiP + NF525** affichés · 2. **FMI + gestion familles/quotient** · 3. **App client aboutie** (billet + badge smartphone + recharge + notifs, marque blanche) · 4. **Support 7j/365 + hébergement France/RGPD** · 5. **SEPA récurrent**.

**POUR GAGNER (différenciateurs de rupture) :**
1. **UX back-office réellement moderne** (attaquer le point faible n°1 d'Elisath/Horanet) — transparence, démos vidéo.
2. **Matériel OUVERT + migration facile depuis Elisath** (reprise données + parc via lecteurs standards) — casser le verrou de l'incumbent consolidé.
3. **Socle vraiment unifié multi-verticales** (complexe municipal mixte en un seul système).
4. **App staff mobile** (le « prof qui valide ses entrées ») + **wallet mobile** — angles morts du marché.
5. **Moteur anti-impayés couplé à l'accès** (repris du meilleur du fitness) pour la verticale sport.
6. **Casiers connectés + mode hors-ligne documenté** (trous de gamme des leaders).
7. *(À arbitrer)* **Équivalent/partenariat « optimisation énergétique »** (EliGreen) — sinon Elisath garde ce critère très vendeur.

## C.6 — Alertes & décisions stratégiques à trancher

1. **🔴 Sortir de la dépendance PERCo (russe)** — sanctions/réputation en marché public. Cibler un partenaire matériel **ouvert et européen** : **Automatic Systems** (idéal, Wiegand/OSDP/TCP-IP, ancrage FR), **Magnetic/FAAC**, **dormakaba/Alvarado** (API DirectConnect), **A3M** (aquatique FR). → **à décider tôt** (impacte l'archi accès).
2. **Ouverture matérielle = choix d'architecture fondateur** : concevoir l'ITBOX/le contrôle d'accès autour d'**OSDP + API REST** dès le départ (ne pas se re-verrouiller sur un fournisseur).
3. **Hébergement France + RGPD** = argument de vente ET contrainte d'archi → à acter avec la stack PHP 8.4/MariaDB.
4. **Ne PAS aller sur le terrain SKIDATA/Axess** (ski/stades) ni SecuTix (musées nationaux grands comptes) — se concentrer sur **collectivités moyennes multi-équipements**, là où l'incumbent consolidé est vulnérable.
5. **Biométrie/reconnaissance faciale : à éviter comme accès courant** (CNIL — disproportionné dès qu'un badge existe).
6. **À faire préciser par toi** : les concurrents « Nayan » et « Elae » (introuvables — Elae ≈ Elisath ?) ; et ta position exacte vis-à-vis de Partner Talent (éditeur d'AwoO) et d'IT Cotation.

## C.7 — Séquencement proposé (à discuter)
1. **Cahier des charges du SOCLE** (modules C.3) — global puis détaillé, écrans en artefacts interactifs.
2. **Puis module vertical Contrôle d'accès** (ton cœur de valeur + différenciateur).
3. **Puis verticales métier** (piscine → sport → patinoire → musée), selon ta priorité commerciale.

> Prochaine étape logique : **démarrer le cahier des charges du socle** (en commençant par **Offre & Tarification**, la fondation) — mais on avance **pas à pas, à ton rythme**, comme tu l'as demandé.
