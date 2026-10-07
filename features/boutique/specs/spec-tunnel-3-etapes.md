# Spec — Tunnel d'achat de la boutique en 3 étapes (#101), v2 du 04/10/2026

> Remplace la v1 (Jarvis, 14/09, PR #126). Décisions de Maxime du 04/10 : **3 étapes directement**, **e-mail
> obligatoire** pour l'achat sans compte, **case « gestion de commande » corrigée dans ce lot** (avis
> juridique), rattachement des clients traité à part (PR dédiée).

## 1. Pourquoi

Aujourd'hui, 5 écrans : Identification, Bénéficiaires, Consentement, Paiement, Confirmation
(`frontend/src/public/pages/Tunnel.jsx` l.7). L'audit du 14/09 (perspective simplificateur) relève deux
frictions : un écran entier de jargon RGPD, et une identification à onglets (invité / compte / FranceConnect)
avant même de voir ses billets. Le concurrent direct (Eversports) réserve en ligne en 2 à 3 gestes.

Ce que la recherche du 04/10 a établi (vérifié dans le code, `origin/main` 578ae2c6) :
- **le serveur accepte déjà l'achat sans compte** : `IdentifierPanierProcessor` (mode `invite` par défaut,
  e-mail facultatif), `PayerPanierProcessor` n'exige ni compte ni identification (sauf produit SEPA, CA-13),
  `ConfirmerCommandeHandler::resoudreClient` crée la fiche client ;
- **l'ordre bénéficiaires → consentement est imposé** : le consentement n'horodate l'autorisation parentale
  que sur les lignes déjà marquées « mineur » par l'enregistrement des bénéficiaires (RG-M3-13) ;
- **défaut RGPD** : la case « J'accepte que mes données personnelles soient traitées pour la gestion de ma
  commande » crée un `Consentement(canal Email, Accordé)` (`EnregistrerConsentementPanierProcessor` l.52-54),
  que le moteur de campagnes lit comme un **accord marketing** (`CampaignSender` l.114-125,
  `ConsentGatedNotifier`) : tout acheteur reçoit les campagnes. 8 lignes en préprod ;
- un client **déjà connecté** qui achète passe aujourd'hui en invité : sa commande n'apparaît pas dans
  « Mes billets » (le mode `compte` redemande le mot de passe, le Tunnel ignore la session) ;
- le bouchon FranceConnect est actif dans tous les environnements (`services.yaml` l.366), sans garde.

## 2. Cible — trois écrans

**Écran 1 — « Vos billets »**
- Si le client est connecté : « Connecté en tant que <e-mail> » + lien « Ce n'est pas moi ». Rien à saisir.
- Sinon : un champ **e-mail (obligatoire)** — « Pour recevoir vos billets » — et un lien discret
  « J'ai déjà un compte » qui ouvre la connexion existante, puis revient ici.
- Par billet : prénom, nom, et date de naissance si le produit l'exige (comme aujourd'hui).
- **Seulement si le produit l'exige** (nouveau réglage du produit, « autorisation parentale pour les
  mineurs », décoché par défaut — décision CP-1) et qu'un bénéficiaire est mineur (calcul en direct, même
  règle que le serveur : né après aujourd'hui − 18 ans) : une case par mineur, sous lui, **« J'autorise cet
  achat pour <prénom> (je suis son parent ou j'agis avec l'accord de ses parents) »**. Le serveur reste
  l'autorité (422 sinon). Ce n'est pas une exigence du RGPD ici (avis juridique) : c'est un choix métier de
  l'établissement (règlement intérieur, mineur non accompagné).
- **Mention d'information** (pas une case) : « Vos données servent à traiter votre commande et à vous envoyer
  vos billets. » + lien vers la politique de confidentialité de l'établissement. **Si l'établissement n'en a
  pas** (décision CP-1) : une politique type générée à son nom et ses coordonnées, qu'il reste responsable de
  compléter ; aucune vitrine bloquée.
- **Case facultative, non cochée** : « Recevoir les nouveautés et offres de <établissement> par e-mail. »
- Bouton « Continuer vers le paiement ».

**Écran 2 — « Paiement »** : inchangé (appel `payer` à l'arrivée, retour du prestataire).

**Écran 3 — « Confirmation »** : inchangé, plus le **code de retrait click & collect** affiché à l'écran
(aujourd'hui il n'est que dans le PDF envoyé par e-mail).

FranceConnect disparaît de l'écran tant qu'il n'est qu'un bouchon (il reste disponible côté serveur pour les
tests, derrière un drapeau d'environnement fermé par défaut).

## 3. Ce qui change côté serveur

1. **Identification** : `POST /identifier` accepte un mode `session` (client authentifié par son jeton) qui
   rattache le panier au compte, sans redemander le mot de passe, **seulement si le compte appartient au
   groupe de la vitrine** (sinon 404 : même défaut que l'en-tête forgé corrigé par la PR de rattachement).
   Le mode `invite` exige désormais un e-mail valide.
2. **Gestion de commande ≠ consentement** : l'enregistrement de la commande ne crée plus de
   `Consentement(Email, Accordé)`. La base légale du traitement de la commande est l'exécution du contrat
   (RGPD art. 6.1.b ; une case obligatoire n'est pas un consentement libre, art. 7.4 — vérifié sur cnil.fr le
   04/10/2026) ; le panier garde l'horodatage de la **mention
   affichée** et sa **version** (pour la preuve d'information).
3. **Accord marketing** : seule la case facultative crée un `Consentement(Email, Accordé, source=boutique)`,
   avec horodatage et version du texte.
4. **Reprise** (décision CP-1 : statut « invalidé ») : nouvel état `Invalide` du consentement, avec motif
   (« recueil non conforme — case de gestion de commande »), date et référence du lot ; la ligne d'origine est
   conservée comme preuve. Ciblage exact, jamais par la seule source : `source=boutique`, canal e-mail, client
   = `client_resolu` d'un panier dont `consentement_rgpd_horodatage` = la date de recueil (8 lignes en
   préprod ; seul chemin de création vérifié). `down()` remet `Accordé` sur exactement ces lignes (repérées
   par le motif). Le moteur de campagnes exclut `Invalide`.
5. Garde FranceConnect : le bouchon ne répond que si `FRANCECONNECT_BOUCHON_AUTORISE=1` (fermé par défaut,
   ouvert en test), même modèle que `PAIEMENT_EN_LIGNE_BOUCHON_AUTORISE` (#104/#116).

## 4. Ordre des appels de l'écran 1

`identifier` (session ou e-mail) → `beneficiaires` → `consentement` (autorisations parentales + accord
marketing éventuel + version de la mention). Si un appel échoue : message affiché, on reste sur l'écran, on
renvoie l'ensemble (aucun appel ne change d'état irréversiblement ; la seule trace d'une nouvelle tentative
est une ligne `Consentement` en plus).

## 5. Critères d'acceptation

1. Le tunnel compte 3 étapes affichées ; un client connecté ne saisit ni e-mail ni mot de passe, et sa
   commande apparaît dans « Mes billets ».
2. Sans compte, impossible de continuer sans e-mail valide (front et serveur, 422).
3. Aucune commande ne crée de `Consentement(Email, Accordé)` si la case marketing n'est pas cochée ; cochée,
   elle en crée un avec la version du texte. Test qui le prouve dans les deux sens.
4. Produit qui exige l'autorisation : un mineur sans case cochée → `payer` refusé (422) ; avec → accepté.
   Produit qui ne l'exige pas : aucune case, `payer` accepté. Tests dans l'ordre réel des appels.
4b. Un client connecté d'un autre groupe ne peut pas rattacher le panier à son compte (404).
4c. La reprise invalide exactement les 8 lignes de préprod et ne touche pas un accord marketing réel (test).
5. La mention d'information et sa version sont horodatées sur le panier.
6. Le code de retrait click & collect s'affiche sur la confirmation.
7. FranceConnect absent de l'écran ; le bouchon refuse hors drapeau.
8. Parcours exercé de bout en bout sur la vitrine de démo `/b/piscine-a` avec le bouchon de paiement
   (achat invité, client connecté, mineur, marketing coché / non coché).

## 6. Hors lot

- Refonte visuelle de la vitrine ; paiement réel (prestataire) ; envoi réel des e-mails (le mailer reste nul
  en préprod, #190 — les billets restent visibles sur l'écran de confirmation) ; remboursement par un invité.
- Le texte définitif de la politique de confidentialité de chaque établissement (le modèle par défaut reste à
  compléter par chacun).
- Questions pour l'avocat : base légale des données des bénéficiaires tiers (6.1.f ?) et information au titre
  de l'art. 14 ; une case marketing par établissement dans un groupe ?

## 7. Taille annoncée et livraison

Une seule PR, déployée d'un bloc (le serveur rend l'e-mail obligatoire : livré seul, il casserait l'écran
actuel). Commits : (1) serveur — consentement, reprise, mode session, réglage produit, drapeau FranceConnect,
politique type (~300 lignes) ; (2) écran — `Tunnel.jsx` (~ -150 / +150). Tests PHPUnit pour chaque critère
serveur ; parcours écran exercé sur `/b/piscine-a`.

## 8. Objections du CP-1 et réponses

- Contradicteur — reprise ciblée par `source=boutique` trop large, « retiré » faux, `down()` aveugle : ciblage
  exact par le panier, statut `Invalide` avec motif, `down()` sur les lignes au motif (§3.4).
- Contradicteur — mode `session` sans contrôle d'appartenance : contrôle du groupe de la vitrine (§3.1, 4b).
- Contradicteur — commit serveur seul casse l'écran : une PR, un déploiement (§7).
- Simplificateur — libellé parental non fixé : fixé (§2).
- Juridique — case parentale = choix métier ; décision CP-1 : seulement si le produit l'exige (§2).
- Juridique — vitrine sans politique : décision CP-1, modèle par défaut (§2).
