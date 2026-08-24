# Ordres pour `claude-F`

> **Écrit par `claude-A` seul.** Tu le lis, tu ne l'écris jamais — c'est ce qui garantit
> qu'il n'y a jamais de conflit de fusion dessus.

---

## 2026-08-24 12:40 · Premier ordre — le séjour d'abord, les modules ensuite

Tu portes les **deux seuls vrais manques** pour couvrir camping, hôtellerie et CHR. Mais l'ordre dans
lequel tu les prends décide de leur valeur.

### Commence par ACT-3, le séjour

C'est contre-intuitif : hébergement et restauration semblent plus urgents. Ils ne le sont pas.

**Le séjour est ce qui transforme « six modules » en « un logiciel ».** Un client, une période, et tout
ce qu'il consomme sur place — emplacement, entrées piscine, additions du bar, parties de bowling — sur
**un même compte, réglé une fois au départ**. C'est la réponse à l'exigence de Maxime : « on doit tout
pouvoir intégrer dans un seul logiciel simple et hyper clair ».

Sans lui, tu livres deux modules de plus dans une liste. Avec lui, tu livres ce qui les relie.

### Ce sur quoi tu t'appuies, et qui existe déjà

- Le **porte-monnaie virtuel** (`App\Crm`) : compte, mouvements, solde après, débit atomique.
- Le **contrôle d'accès** : supports, droits, passages.
- La **réservation** : créneaux, ressources, jauges.

Tu n'inventes ni le paiement, ni l'accès. Tu inventes le **fil** qui rattache une consommation à un
séjour.

### Puis ACT-2 (hébergement) et ACT-4 (restauration)

D16 a déjà tranché le principe : **réserver est un acte unique**. Une table de 8, une chambre de 4, un
court de padel — même acte. Ce qui varie, c'est l'unité de temps, le mode de capacité, et surtout ce
qui se produit **après**.

L'hébergement ajoute la **nuitée** : tarif par nuit, calendrier d'occupation, chambre libérée le matin
et relouable le soir. C'est une couche mince, **pas un module parallèle à la réservation**. Si tu te
retrouves à réécrire un moteur de créneaux, arrête-toi et signale-le : c'est le signe que tu diverges.

Trois manques précis sont déjà identifiés dans D16 — une réservation consomme N unités et pas 1, on
réserve un **type** et l'instance est affectée plus tard, et il existe **deux niveaux de capacité
imbriqués**. Ce sont tes fondations, lis D16 en entier.
