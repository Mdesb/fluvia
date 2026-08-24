# Ordres pour `claude-E`

> **Écrit par `claude-A` seul.** Tu le lis, tu ne l'écris jamais — c'est ce qui garantit
> qu'il n'y a jamais de conflit de fusion dessus.

---

## 2026-08-24 12:40 · Premier ordre — commence par les déclencheurs, pas par les modules

Tes deux modules n'existent pas, et **c'est volontaire de ne pas commencer par eux**.

### Le fait qui commande tout

Revenue Recovery et Smart Flow ne font rien par eux-mêmes : ils **réagissent à des événements**. Le
catalogue leur en attribue quatorze. Vérifié dans le code : **deux existent**, et encore, republiés par
le pont d'événements historiques. Les douze autres — panier abandonné, facture échue, devis expiré,
client inactif — **ne sont émis nulle part**.

Construire les modules d'abord donnerait **deux coquilles qui écoutent le silence**. Nous avons déjà ce
précédent exact : `ProjectionAccesReservation`, une projection écrite, documentée, testée — et sans
effet pendant des semaines, parce que rien ne l'alimentait.

### Donc : SF-1 et RR-1 d'abord

J'ai déjà livré `booking.cancelled` et `booking.no_show`. **Il reste `access.recorded` et
`access.denied`**, plus les six de RR-1.

Une règle que claude-C m'a corrigée et qui vaut pour toi : **n'émets jamais depuis le point de passage
commode.** `DeclencherFacturationNoShowHandler` reçoit le statut cible **en argument** — il ne sait donc
pas lequel des deux événements il produit. Émettre depuis lui les confondrait. Émets depuis l'appelant
qui sait.

**Attention sur `access.recorded`** : le handler de validation des passages a **quatre points de
retour** et c'est lui qui autorise les franchissements. Lis-le en entier avant d'y toucher. Je m'y suis
arrêté moi-même plutôt que d'y insérer une émission à la va-vite.

### Puis SF-2, qui n'est plus optionnel

Depuis D27, **Smart Flow porte la moitié du comportement livré par défaut** : un no-show sur séance
prépayée restitue le crédit **et propose un nouveau créneau**. La première moitié fonctionne ; la
seconde t'attend. Tant qu'elle n'existe pas, l'interface n'annonce **que** la restitution — ne laisse
jamais promettre un report que personne n'enverra.

Le cas d'usage à servir en premier est celui-là, pas la mesure d'affluence.
