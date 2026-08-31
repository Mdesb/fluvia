import { useEffect, useState } from 'react'
import Modal from './Modal.jsx'
import ClientPicker from './ClientPicker.jsx'
import { api, membres, ApiError } from '../api/client.js'

// LE DEVIS SE FAIT DEPUIS LA FICHE DU CLIENT, PAS SEULEMENT DEPUIS L'ÉCRAN DE FACTURATION.
//
// Maxime, le 28/08 : « facturation devrait être possible depuis la fiche d'un client ». La demande
// est évidente une fois posée — on décide de facturer QUELQU'UN, en regardant ce qu'il a acheté,
// pas en ouvrant un écran comptable et en retapant son nom.
//
// ET C'EST CE RETAPAGE QUI ÉTAIT LE VRAI DÉFAUT. Le formulaire ne demandait qu'une **raison sociale
// en texte libre**. Le devis n'était donc rattaché à aucun client : deux orthographes du même nom
// faisaient deux destinataires, et la fiche d'un client ne pouvait pas montrer ses propres devis.
//
// Or `DestinataireFacturation` porte `clientRef` — une référence libre vers le client du CRM —
// exposée en écriture dans le groupe `destinataire:write` depuis le début, et que personne
// n'envoyait. Le serveur savait faire ; il manquait le champ.
//
// UN SEUL COMPOSANT POUR LES DEUX PORTES. Même raisonnement que pour SEPA, recouvrement et
// cautions : l'écran Facturation et la fiche client ouvrent la même modale. Deux endroits d'où
// partir, une seule implémentation, et le jour où le formulaire gagne un champ il le gagne aux deux
// endroits.
// UN DEVIS ET UNE FACTURE DIRECTE SE REDIGENT DE LA MEME FACON, ET LE SERVEUR LE CONFIRME.
//
// `POST /billing/documents` et `POST /factures` attendent le meme couple : un destinataire et des
// lignes { designation, quantite, prixUnitaireHT, tauxTva }. Ecrire un second formulaire pour la
// facture aurait donne deux verites sur ce qu'est une ligne -- et la seconde aurait pris du retard
// sur la premiere au premier ajustement.
//
// `cible` vaut donc 'devis' ou 'facture'. Ce qui change : la route appelee, le titre, deux champs
// propres a la facture (echeance, conditions de reglement), et l'avertissement de non-rattachement
// qui ne concerne QUE le devis -- le constructeur de facture directe lit `clientRef`, celui du
// document commercial ne le lit pas.
// `existante` ouvre le formulaire SUR un brouillon deja cree, pour le corriger avant emission.
//
// Sans ca, un brouillon errone etait definitif : `Facture` n'expose aucune suppression -- et c'est
// voulu, la serie des numeros ne se troue pas -- donc une facture mal saisie serait restee dans la
// liste pour toujours. `PATCH /factures/{id}` accepte la modification libre TANT QUE brouillon, et
// refuse explicitement une facture emise (elle est inalterable).
export default function DevisModal({ open, client, onClose, onCree, cible = 'devis', existante = null }) {
  const facture = cible === 'facture'
  const [echeance, setEcheance] = useState('')
  const [tauxTva, setTauxTva] = useState([])
  const [categories, setCategories] = useState([])
  const [mappings, setMappings] = useState([])
  // Seul l'axe comptable impute. Les trois axes de M1 (marketing, comptable, rayon) sont
  // indépendants : proposer une catégorie marketing ici donnerait un choix sans effet.
  const categoriesComptables = categories.filter((c) => c.axe === 'comptable')
  const [choisi, setChoisi] = useState(null)
  const [pickerOuvert, setPickerOuvert] = useState(false)
  const [raisonSociale, setRaisonSociale] = useState('')
  // ⚠ TROIS ÉTATS, PAS DEUX. `null` = pas encore lu · `[]` = lu et vraiment vide · `'refus'` =
  //    la lecture a échoué. « Aucun produit au catalogue » et « je n'ai pas pu demander le
  //    catalogue » sont des affirmations opposées, et la seconde ne doit jamais s'afficher comme
  //    la première : elle rassurerait à tort quelqu'un qui cherche pourquoi sa liste est vide.
  const [produits, setProduits] = useState(null)
  const [filtreProduit, setFiltreProduit] = useState('')

  const [lignes, setLignes] = useState([{ ...LIGNE_VIDE }])
  const [envoi, setEnvoi] = useState(false)
  const [erreur, setErreur] = useState(null)
  const [nonRattache, setNonRattache] = useState(false)
  // La piece creee est conservee : l appelant en a besoin meme quand le rattachement n a pas pris
  // (le pipeline la lie a l affaire par son propre champ, qui lui fonctionne).
  const [creeSansLien, setCreeSansLien] = useState(null)

  // Le client imposé par l'appelant (fiche client) prime ; sinon on laisse choisir.
  const destinataire = client || choisi

  useEffect(() => {
    if (!open) return
    setChoisi(null)
    setErreur(null)
    setNonRattache(false)
    setCreeSansLien(null)
    if (existante) {
      const d = existante.destinataire || {}
      setRaisonSociale(d.raisonSociale || [d.prenom, d.nom].filter(Boolean).join(' ').trim() || '')
      setLignes(
        (existante.lignes || []).map((l) => ({
          designation: l.designation || '',
          quantite: l.quantite ?? 1,
          prixUnitaireHT: String(l.prixUnitaireHT ?? ''),
          tauxTva: idDe(l.tauxTva),
        })),
      )
      setEcheance((existante.dateEcheance || '').slice(0, 10))
    } else {
      setRaisonSociale('')
      setLignes([{ ...LIGNE_VIDE }])
      setEcheance('')
    }
    // Deux lectures de confort : leur absence ne doit pas empêcher d'établir une pièce.
    Promise.allSettled([api.categories(), api.mappingsComptables()]).then(([c, m]) => {
      setCategories(c.status === 'fulfilled' ? membres(c.value) : [])
      setMappings(m.status === 'fulfilled' ? membres(m.value) : [])
    })

    api.tauxTvas()
      // UN TAUX MASQUE RESTAIT PROPOSE, ET C'EST L'INVERSE DE CE QUE << masque >> VEUT DIRE.
      //
      // Vu a l'ecran : le referentiel affiche << Taux reduit 2025 (a valider fiscaliste) >> en
      // `actif: false`, et cette liste l'offrait quand meme. Le sens du drapeau est precisement
      // << ne plus le proposer sur une nouvelle piece >> -- le desactiver ne servait donc a rien
      // ici, alors que c'est le seul moyen de retirer un taux (l'entite n'expose aucune
      // suppression, et c'est voulu : un taux cite par des ventes passees ne se supprime pas).
      .then((r) => setTauxTva(membres(r).filter((t) => t.actif !== false)))
      // Les taux absents n'empêchent pas d'ouvrir la modale : le champ restera vide et le formulaire
      // refusera la validation, ce qui est plus clair qu'une modale qui ne s'ouvre pas.
      .catch(() => setTauxTva([]))

    // LE CATALOGUE EST UN CONFORT : son absence n'empêche pas de saisir une ligne à la main, et la
    // modale s'ouvre quand même. Mais elle doit se VOIR — voir plus bas, où le refus s'affiche.
    api.produits({ itemsPerPage: 200 })
      .then((r) => setProduits(membres(r)))
      .catch(() => setProduits('refus'))
  }, [open])

  function majLigne(i, champ, valeur) {
    setLignes((precedent) => precedent.map((l, j) => (i === j ? { ...l, [champ]: valeur } : l)))
  }

  function majLignes(i, champs) {
    setLignes((precedent) => precedent.map((l, j) => (i === j ? { ...l, ...champs } : l)))
  }

  // ── REPRENDRE UN PRODUIT DU CATALOGUE ───────────────────────────────────────────────────────
  //
  // ⚠ CE QUE CETTE FONCTION NE FAIT PAS EST PLUS IMPORTANT QUE CE QU'ELLE FAIT. Trois valeurs du
  //    catalogue ne se versent PAS telles quelles dans une ligne de facture, et chacune pour une
  //    raison mesurée le 31/08 contre la préprod :
  //
  //   1. LE PRIX DU CATALOGUE EST TTC. `GrilleTarifaire::$prix` porte le commentaire « Prix TTC.
  //      null = non commercialisé (≠ gratuit) », et `LigneFacture::$prixUnitaireHT` attend du HT.
  //      Le verser directement fausserait la facture du montant de la TVA, en silence.
  //
  //   2. LE TAUX DU PRODUIT EST UNE VALEUR, PAS UNE RÉFÉRENCE. `Produit::$tauxTva` est un décimal
  //      (« 10.00 ») ; `FactureDirecteBuilder` exige l'UUID d'un `TauxTva` appartenant à
  //      l'exploitant (RG-M6-05, puis RG-SOCLE-05 sur le cloisonnement). Il faut donc APPARIER —
  //      et deux taux de l'exploitant peuvent porter la même valeur. Mesuré : « Taux réduit 5,5 % »
  //      et « Taux réduit 2025 » valent tous deux 5.50. Le second est inactif aujourd'hui, donc
  //      l'appariement est unique — aujourd'hui. On ne construit pas sur cette chance.
  //
  //   3. LE PRODUIT NE PORTE PAS DE CATÉGORIE COMPTABLE. Il porte `compteComptable`, une CHAÎNE de
  //      32 caractères ; la ligne attend l'UUID d'une `Categorie` d'axe comptable. Ce ne sont pas
  //      les mêmes objets et rien ne les relie. Aucune reprise possible, et l'écran le dit.
  //
  // On remplit donc la désignation, et on POSE SOUS LA LIGNE ce que le catalogue sait, avec un
  // geste explicite pour le reprendre. Jamais de valeur glissée sans que personne ne la regarde :
  // c'est une facture.
  //
  // ⚠ ET IL FAUT EFFACER CE QUE LE PRÉCÉDENT PRODUIT AVAIT REMPLI. Vu à l'écran le 31/08 : on
  //    reprend « Audioguide » (3,64 € HT, TVA 10 %), on change pour « Test » — la désignation
  //    devient « Test », et le prix comme le taux d'Audioguide RESTENT. La ligne facturait alors
  //    un produit au prix d'un autre, sans que rien ne le dise. Exactement le nombre faux
  //    silencieux que cet écran existe pour empêcher.
  //
  //    On n'efface QUE ce que le catalogue avait posé : `prixRepris` et `tauxRepris` gardent la
  //    valeur qu'on a écrite, et si l'utilisateur l'a changée depuis, elle ne correspond plus et
  //    on n'y touche pas. Effacer une saisie manuelle serait le défaut symétrique.
  async function reprendreProduit(i, produitId) {
    setLignes((precedent) => precedent.map((l, j) => {
      if (j !== i) return l
      const efface = {}
      if (l.prixRepris && l.prixUnitaireHT === l.prixRepris) {
        efface.prixUnitaireHT = ''
        efface.prixRepris = ''
      }
      if (l.tauxRepris && l.tauxTva === l.tauxRepris) {
        efface.tauxTva = ''
        efface.tauxRepris = ''
      }
      return {
        ...l,
        ...efface,
        produit: produitId,
        catalogue: produitId ? { chargement: true } : null,
      }
    }))
    if (!produitId) return
    let p
    try {
      // ⚠ L'ITEM, PAS LA COLLECTION. `GET /api/produits` sérialise `produit:read` + `produit:list`
      //    et ne publie NI `tauxTva` NI `compteComptable` ; seul `GET /api/produits/{id}` ajoute
      //    le groupe `produit:compta`. Mesuré : la collection n'a jamais ces clés, le détail oui.
      p = await api.produit(produitId)
    } catch {
      majLignes(i, { catalogue: { refus: true } })
      return
    }

    const grilles = (p.grilles || [])
      .filter((g) => g && typeof g === 'object')
      .filter((g) => g.prix !== null && g.prix !== undefined && g.prix !== '')

    // Appariement par VALEUR, sur les seuls taux déjà retenus (les inactifs sont écartés plus haut).
    const tauxProduit = p.tauxTva === null || p.tauxTva === undefined ? null : Number(p.tauxTva)
    const candidats = tauxProduit === null
      ? []
      : tauxTva.filter((t) => Number(t.taux) === tauxProduit)

    majLignes(i, {
      designation: libelleProduit(p),
      // Un seul candidat : on présélectionne, et on le DIT sous la ligne. Plusieurs : on ne touche
      // à rien — choisir pour l'utilisateur entre deux taux fiscaux serait le pire des deux mondes.
      ...(candidats.length === 1 ? { tauxTva: idDe(candidats[0]), tauxRepris: idDe(candidats[0]) } : {}),
      catalogue: {
        nom: libelleProduit(p),
        tauxProduit,
        candidats: candidats.map((t) => ({ id: idDe(t), libelle: t.libelle, taux: t.taux })),
        grilles: grilles.map((g) => ({
          id: idDe(g),
          prixTtc: g.prix,
          tarif: (g.typeTarif || {}).nom || null,
          saison: (g.saison || {}).nom || null,
        })),
        compteComptable: p.compteComptable || null,
      },
    })
  }

  async function soumettre(e) {
    e.preventDefault()
    setEnvoi(true)
    setErreur(null)
    try {
      const corps = {
        destinataire: corpsDestinataire(destinataire, raisonSociale),
        lignes: lignes.map((l) => ({
          designation: l.designation,
          // `FactureDirecteBuilder` ne retient la catégorie que si c'est un UUID valide ; une
          // chaîne vide est ignorée sans erreur, ce qui est le comportement voulu ici.
          ...(l.categorieComptable ? { categorieComptable: l.categorieComptable } : {}),
          quantite: Number(l.quantite) || 1,
          prixUnitaireHT: String(l.prixUnitaireHT || '0'),
          tauxTva: l.tauxTva,
        })),
      }
      if (facture && echeance) corps.dateEcheance = echeance
      const cree = existante
        ? await api.majFactureDirecte(existante.id, corps)
        : facture
          ? await api.creerFactureDirecte(corps)
          : await api.creerDevis(corps)

      // La facture directe RETIENT le rattachement au client : `FactureDirecteBuilder` lit
      // `clientRef`. L'avertissement ci-dessous ne vaut donc que pour le devis.
      if (facture) {
        onCree?.(cree)
        return
      }

      // ON VÉRIFIE CE QUE LE SERVEUR A RETENU, PLUTÔT QUE CE QU'ON LUI A ENVOYÉ.
      //
      // `CreateDocumentProcessor::destinataire()` compose le destinataire à la main et lit
      // `raisonSociale`, `nom`, `prenom`, `siret`, `adresse` — **pas `clientRef`**. Le devis part
      // donc en 201 avec un destinataire correct et AUCUN rattachement au dossier du client, sans
      // que rien ne le signale. Mesuré contre la préprod le 28/08 : le champ n'est pas dans la
      // réponse alors qu'il appartient bien au groupe de lecture `facture:read`.
      //
      // (Le chemin des factures directes, lui, le lit : `FactureDirecteBuilder::appliquerDestinataire`.
      // La capacité existe, elle manque à ce processor-ci. Signalé côté serveur.)
      //
      // On ne peut pas corriger ça d'ici. Ce qu'on peut faire, c'est ne pas laisser croire que le
      // devis figure au dossier du client alors qu'il n'y figure pas — et se taire tout seul le jour
      // où le serveur l'acceptera.
      if (destinataire && !cree?.destinataire?.clientRef) {
        setCreeSansLien(cree)
        setNonRattache(true)
        return
      }
      onCree?.(cree)
    } catch (err) {
      setErreur(err instanceof ApiError ? err.message : 'Création impossible.')
    } finally {
      setEnvoi(false)
    }
  }

  const nomAffiche = destinataire ? nomDe(destinataire) : ''
  const pretAEnvoyer = (destinataire || raisonSociale.trim())
    && lignes.every((l) => l.designation.trim() && l.prixUnitaireHT && l.tauxTva)

  return (
    <>
      <Modal open={open} onClose={onClose} titre={existante ? 'Corriger le brouillon' : facture ? 'Facturer un client' : 'Nouveau devis'}>
        <form onSubmit={soumettre}>
          {erreur && <div className="banner banner-error">{erreur}</div>}

          {/* LE DEVIS EST CRÉÉ, ET IL N'EST PAS AU DOSSIER — les deux sont vrais, on dit les deux.
              Annoncer seulement « devis créé » laisserait chercher en vain dans l'historique du
              client ; annoncer une erreur laisserait le recréer, et il y en aurait deux. */}
          {nonRattache && (
            <div className="banner banner-warn">
              <b>Devis créé, mais pas rattaché à la fiche de ce client.</b> Le serveur ne conserve
              pas encore le lien vers le dossier client sur les devis : la pièce existe et porte le
              bon nom, mais elle n&rsquo;apparaîtra pas dans l&rsquo;historique de{' '}
              {nomAffiche || 'ce client'}. Vous la retrouverez dans l&rsquo;écran{' '}
              <b>Facturation</b>. C&rsquo;est signalé et sera corrigé côté serveur.
              <div style={{ marginTop: 8 }}>
                <button className="btn sm" type="button" onClick={() => { setNonRattache(false); onCree?.(creeSansLien) }}>
                  J&rsquo;ai compris
                </button>
              </div>
            </div>
          )}

          <div className="field">
            <label>Client *</label>
            {destinataire ? (
              <div style={{ display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap' }}>
                <span className="nm">{nomAffiche}</span>
                {/* Le client venu de sa propre fiche ne se change pas ici : on est parti de LUI.
                    Proposer d'en changer inviterait à créer, depuis la fiche de Dupont, un devis
                    au nom de Martin — et personne ne s'en apercevrait. */}
                {!client && (
                  <button className="btn ghost sm" type="button" onClick={() => setPickerOuvert(true)}>
                    Changer
                  </button>
                )}
              </div>
            ) : (
              <>
                <button className="btn" type="button" onClick={() => setPickerOuvert(true)}>
                  Choisir un client…
                </button>
                <div className="field" style={{ marginTop: 10 }}>
                  <label htmlFor="devis-raison">…ou saisir une raison sociale</label>
                  <input
                    id="devis-raison"
                    className="input"
                    value={raisonSociale}
                    onChange={(e) => setRaisonSociale(e.target.value)}
                    placeholder="Collectivité, entreprise non enregistrée…"
                  />
                  {/* ON GARDE LA SAISIE LIBRE, ET ON DIT CE QU'ELLE COÛTE.
                      Un devis à une collectivité qui n'est pas dans le fichier clients doit rester
                      possible — le supprimer transformerait un cas courant en impasse. Mais un
                      destinataire saisi à la main n'est rattaché à personne : il ne remontera pas
                      sur une fiche, et deux orthographes feront deux destinataires. */}
                  <div className="hint">
                    Un destinataire saisi à la main n&rsquo;est rattaché à aucune fiche client :{' '}
                    {facture ? 'cette facture' : 'ce devis'} n&rsquo;apparaîtra pas dans son
                    historique, et une autre orthographe créera un second destinataire.
                  </div>
                </div>
              </>
            )}
          </div>

          {/* L'ÉTAT DU CATALOGUE SE DIT UNE FOIS, PAS PAR LIGNE — sinon le même avertissement
              se répète autant de fois qu'il y a de lignes et on cesse de le lire. */}
          {produits === 'refus' && (
            <div className="banner banner-warn">
              <b>Le catalogue n’a pas pu être lu.</b> Vous pouvez saisir les lignes à la main —
              c’est le seul effet. N’en concluez pas qu’il est vide : cet écran n’a pas eu de
              réponse, ce qui n’est pas la même chose qu’une réponse vide.
            </div>
          )}
          {Array.isArray(produits) && produits.length === 0 && (
            <p className="hint">
              Aucun produit au catalogue pour cet établissement. Les lignes se saisissent à la main.
            </p>
          )}
          {Array.isArray(produits) && produits.length > 12 && (
            <div className="field">
              <label htmlFor="dm-filtre-produit">Filtrer le catalogue</label>
              <input
                id="dm-filtre-produit"
                className="input"
                value={filtreProduit}
                onChange={(e) => setFiltreProduit(e.target.value)}
                placeholder="Nom du produit"
              />
            </div>
          )}

          {lignes.map((ligne, i) => (
            <div className="field" key={i}>
              <label htmlFor={`dm-ligne-${i}`}>Ligne {i + 1}</label>
              {Array.isArray(produits) && produits.length > 0 && (
                <select
                  className="input"
                  value={ligne.produit || ''}
                  onChange={(e) => reprendreProduit(i, e.target.value)}
                  aria-label={`Produit du catalogue pour la ligne ${i + 1}`}
                >
                  <option value="">Reprendre un produit du catalogue…</option>
                  {produits
                    .filter((p) => !filtreProduit
                      || libelleProduit(p).toLowerCase().includes(filtreProduit.toLowerCase()))
                    .map((p) => (
                      <option key={p.id} value={p.id}>{libelleProduit(p)}</option>
                    ))}
                </select>
              )}
              <input
                id={`dm-ligne-${i}`}
                className="input"
                value={ligne.designation}
                onChange={(e) => majLigne(i, 'designation', e.target.value)}
                placeholder="Désignation — ce que le client lira"
                required
              />
              <div className="row">
                <input
                  className="input"
                  type="number"
                  min="1"
                  value={ligne.quantite}
                  onChange={(e) => majLigne(i, 'quantite', e.target.value)}
                  aria-label={`Quantité de la ligne ${i + 1}`}
                />
                <input
                  className="input"
                  type="text"
                  inputMode="decimal"
                  value={ligne.prixUnitaireHT}
                  onChange={(e) => majLigne(i, 'prixUnitaireHT', e.target.value)}
                  placeholder="Prix unitaire HT"
                  aria-label={`Prix unitaire HT de la ligne ${i + 1}`}
                  required
                />
                <select
                  className="input"
                  value={ligne.tauxTva}
                  onChange={(e) => majLigne(i, 'tauxTva', e.target.value)}
                  aria-label={`Taux de TVA de la ligne ${i + 1}`}
                  required
                >
                  <option value="">Taux de TVA…</option>
                  {tauxTva.map((t) => (
                    <option key={idDe(t)} value={idDe(t)}>{t.libelle}</option>
                  ))}
                </select>
                {/* LA CATÉGORIE COMPTABLE DÉCIDE SUR QUEL COMPTE LA LIGNE S'IMPUTE, et la ligne
                    partait sans elle. `FactureDirecteBuilder` l'accepte depuis le début ; aucun
                    écran ne l'envoyait, si bien que tout le chiffre d'affaires facturé à la main
                    tombait dans un seul compte — ou faisait refuser l'émission en 422 quand aucun
                    compte par défaut n'est paramétré.
                    Facultative à dessein : une pièce doit pouvoir s'établir sans que le comptable
                    soit là. */}
                {categoriesComptables.length > 0 && (
                  <select
                    className="input"
                    value={ligne.categorieComptable || ''}
                    onChange={(e) => majLigne(i, 'categorieComptable', e.target.value)}
                    aria-label={`Catégorie comptable de la ligne ${i + 1}`}
                  >
                    <option value="">Catégorie comptable…</option>
                    {categoriesComptables.map((c) => (
                      <option key={idDe(c)} value={idDe(c)}>{c.libelle || c.nom}</option>
                    ))}
                  </select>
                )}
              </div>
              {ligne.catalogue && (
                <RepriseCatalogue
                  info={ligne.catalogue}
                  tauxChoisi={tauxTva.find((t) => idDe(t) === ligne.tauxTva) || null}
                  onPrix={(v) => majLignes(i, { prixUnitaireHT: v, prixRepris: v })}
                  onTaux={(v) => majLignes(i, { tauxTva: v, tauxRepris: v })}
                />
              )}
            </div>
          ))}

          {/* ⚠ CE QUE LA CATÉGORIE CHANGE — ET AUJOURD'HUI, SUR CET ÉTABLISSEMENT, RIEN.
              `EmettreFactureDirecteHandler` demande à `ResolveurComptesFacturation` le compte de
              chaque ligne ; sans correspondance il se replie sur le compte de produit par défaut,
              et n'échoue (422) que si ce compte n'est pas paramétré non plus. Un champ qu'on
              remplit consciencieusement et qui n'a aucun effet est exactement ce qu'on retire
              ailleurs : ici on le garde — il enregistre l'intention et deviendra effectif — mais
              on dit son état.

              ⚠⚠ NE PAS CONFONDRE AVEC L'AUTRE CHEMIN COMPTABLE, ET LA CONFUSION A DÉJÀ EU LIEU.
              Les VENTES ne passent pas par ici : `GenerateurEcrituresHandler` demande ses
              anomalies à `MappingComptableGuard` et, si la catégorie n'a pas de correspondance,
              la vente est **sautée** — aucune écriture, un signalement en anomalie à la clôture.
              Aucun repli sur un compte par défaut de ce côté-là.
              Deux modules, deux comportements opposés sur la même donnée manquante : facture =
              repli silencieux, vente = non comptabilisée et signalée tard. L'affirmation « le
              résolveur se replie » a circulé sur trois relais avant que quelqu'un n'ouvre le
              second fichier, et elle a gagné en crédibilité à chaque passage. */}
          {categoriesComptables.length > 0 && mappings.length === 0 && (
            <p className="hint">
              Aucune correspondance comptable n’est déclarée pour l’instant : quelle que soit la
              catégorie choisie, la ligne s’imputera au <b>compte de produit par défaut</b>. La
              catégorie est enregistrée et deviendra effective dès qu’une correspondance sera posée
              dans le paramétrage comptable.
            </p>
          )}

          <button
            type="button"
            className="btn ghost sm"
            onClick={() => setLignes((p) => [...p, { ...LIGNE_VIDE }])}
          >
            + Ajouter une ligne
          </button>

          {facture && (
            <div className="field">
              <label htmlFor="dm-echeance">Date d&rsquo;échéance</label>
              <input
                id="dm-echeance"
                className="input"
                type="date"
                value={echeance}
                onChange={(e) => setEcheance(e.target.value)}
              />
              <div className="hint">
                La date au-delà de laquelle la facture est en retard. Laissée vide, elle prend les
                conditions de règlement du profil comptable — et sans échéance, aucun retard ne peut
                être constaté.
              </div>
            </div>
          )}

          <p className="hint">
            {existante
              ? 'Cette facture n’est pas encore émise : elle se corrige librement. Une fois émise, elle sera inaltérable et ne pourra plus être annulée que par un avoir.'
              : facture
              ? 'La facture part en BROUILLON : aucun numéro n’est consommé tant que vous ne l’avez pas émise. C’est ce qui permet de se tromper sans trouer la série légale des numéros.'
              : 'Le devis part en brouillon : rien ne sort tant que vous ne l’avez pas émis, et un numéro n’est consommé qu’à l’émission — un numéro pris par une pièce qu’on jette laisse un trou dans la série.'}
          </p>

          <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 12 }}>
            <button className="btn" type="button" onClick={onClose}>Annuler</button>
            <button className="btn primary" type="submit" disabled={envoi || !pretAEnvoyer}>
              {envoi ? 'Enregistrement…' : existante ? 'Enregistrer' : facture ? 'Créer le brouillon' : 'Créer le devis'}
            </button>
          </div>
        </form>
      </Modal>

      <ClientPicker
        open={pickerOuvert}
        onClose={() => setPickerOuvert(false)}
        onSelect={(c) => { setChoisi(c); setPickerOuvert(false) }}
      />
    </>
  )
}

const LIGNE_VIDE = {
  designation: '', quantite: 1, prixUnitaireHT: '', tauxTva: '', categorieComptable: '',
  // `produit` et `catalogue` ne partent PAS au serveur : `soumettre` compose son corps avec des
  // clés explicites, donc tout le reste est ignoré. Ils vivent sur la ligne parce qu'une ligne
  // s'ajoute et se retire, et qu'un état indexé à côté se désynchroniserait au premier retrait.
  produit: '', catalogue: null, prixRepris: '', tauxRepris: '',
}

function nomDe(client) {
  if (client.raisonSociale) return client.raisonSociale
  return [client.prenom, client.nom].filter(Boolean).join(' ').trim() || 'Client'
}

// LE CORPS ENVOYÉ AU SERVEUR, ET POURQUOI `clientRef` COMPTE PLUS QUE LE RESTE.
//
// `raisonSociale` s'imprime sur le devis ; `clientRef` le RATTACHE. Sans elle, `MesFacturesProvider`
// ne peut pas retrouver les pièces d'un client (il filtre justement sur `d.clientRef`), et la fiche
// du client ne montrera jamais ses devis. C'est le champ qui transforme un document en pièce d'un
// dossier.
function corpsDestinataire(client, raisonSocialeLibre) {
  if (!client) return { raisonSociale: raisonSocialeLibre.trim() }

  const morale = client.type === 'morale' || Boolean(client.raisonSociale)
  return {
    type: morale ? 'personne_morale' : 'particulier',
    clientRef: client.id,
    ...(morale
      ? { raisonSociale: client.raisonSociale || nomDe(client) }
      : { nom: client.nom || nomDe(client), prenom: client.prenom || null }),
  }
}

/** `{ fr: 'Audioguide' }` — le libellé est multilingue, et `fr` n'est pas garanti. */
function libelleProduit(p) {
  const l = p && p.libelle
  if (typeof l === 'string') return l
  if (l && typeof l === 'object') return l.fr || Object.values(l)[0] || '(sans libellé)'
  return p && p.code ? String(p.code) : '(sans libellé)'
}

function euros(v) {
  const n = Number(v)
  return Number.isNaN(n) ? '—' : n.toLocaleString('fr-FR', { style: 'currency', currency: 'EUR' })
}

// ⚠ LE DIVISEUR AFFICHÉ DOIT ÊTRE CELUI QU'ON DIVISE. Écrit `.toFixed(2)`, un taux à 5,5 %
// s'affichait « ÷ 1,06 » alors que le calcul emploie 1,055 : l'arithmétique montrée ne
// retombait pas sur le résultat montré, et c'est pire que de ne rien montrer.
const pourcent = (v) => `${Number(v).toLocaleString('fr-FR')} %`

const diviseur = (taux) => Number((1 + Number(taux) / 100).toFixed(4)).toLocaleString('fr-FR')

const htDepuisTtc = (ttc, taux) => (Number(ttc) / (1 + Number(taux) / 100)).toFixed(2)
const ttcDepuisHt = (ht, taux) => (Number(ht) * (1 + Number(taux) / 100)).toFixed(2)

// ── CE QUE LE CATALOGUE SAIT, POSÉ SOUS LA LIGNE — ET JAMAIS DEDANS TOUT SEUL ──────────────────
//
// Chaque valeur reprise l'est par un GESTE. C'est une facture : un montant qui apparaît sans que
// personne ne l'ait regardé est exactement ce qu'on ne veut pas, même quand il est juste.
function RepriseCatalogue({ info, tauxChoisi, onPrix, onTaux }) {
  if (info.chargement) return <p className="hint">Lecture du produit…</p>
  const choisi = tauxChoisi ? info.candidats.find((c) => c.id === idDe(tauxChoisi)) || null : null

  if (info.refus) {
    return (
      <div className="banner banner-warn">
        Le détail de ce produit n’a pas pu être lu : ni son tarif ni son taux ne sont proposés.
        La ligne reste saisissable à la main.
      </div>
    )
  }

  return (
    <div className="hint">
      {/* ── LE TARIF ─────────────────────────────────────────────────────────────────────── */}
      {info.grilles.length === 0 ? (
        <p>
          <b>{info.nom}</b> n’a aucun tarif au catalogue. ⚠ « Non commercialisé » n’est pas
          « gratuit » : si vous facturez ce produit, le prix se décide ici.
        </p>
      ) : (
        info.grilles.map((g) => {
          // ⚠ LE TARIF CATALOGUE EST TTC, LA LIGNE DE FACTURE EST HT. Le reprendre tel quel
          //    fausserait la facture du montant de la TVA — et rien ne l'aurait signalé.
          const ht = tauxChoisi ? htDepuisTtc(g.prixTtc, tauxChoisi.taux) : null
          const retour = ht === null ? null : ttcDepuisHt(ht, tauxChoisi.taux)
          const ecart = retour !== null && Number(retour) !== Number(g.prixTtc)
          return (
            <p key={g.id}>
              Tarif catalogue{[g.tarif, g.saison].filter(Boolean).length > 0
                ? ` (${[g.tarif, g.saison].filter(Boolean).join(' · ')})`
                : ''} : <b>{euros(g.prixTtc)} TTC</b>.{' '}
              {ht === null ? (
                <>Choisissez le taux de TVA pour pouvoir le convertir en HT.</>
              ) : (
                <>
                  <button type="button" className="btn ghost xs" onClick={() => onPrix(ht)}>
                    Reprendre {euros(ht)} HT
                  </button>{' '}
                  ({euros(g.prixTtc)} ÷ {diviseur(tauxChoisi.taux)}).
                  {ecart && (
                    <>
                      {' '}⚠ Ce HT redonne <b>{euros(retour)} TTC</b>, pas {euros(g.prixTtc)} :
                      l’arrondi ne retombe pas juste. Le serveur recalcule le TTC depuis le HT,
                      donc c’est ce montant-là que le client verra.
                    </>
                  )}
                </>
              )}
            </p>
          )
        })
      )}

      {/* ── LE TAUX ──────────────────────────────────────────────────────────────────────── */}
      {info.tauxProduit === null ? (
        <p>Ce produit ne porte pas de taux de TVA au catalogue : le taux se choisit ici.</p>
      ) : info.candidats.length === 0 ? (
        <p>
          ⚠ Le produit porte <b>{pourcent(info.tauxProduit)}</b>, et <b>aucun taux actif de l’exploitant
          ne correspond</b>. Le taux se choisit donc à la main — la facture ne peut pas citer un
          taux qui n’est pas le vôtre.
        </p>
      ) : info.candidats.length === 1 ? (
        <p>Taux repris du produit : <b>{info.candidats[0].libelle}</b> ({pourcent(info.tauxProduit)}).</p>
      ) : (
        <p>
          ⚠ <b>{info.candidats.length} taux de l’exploitant valent {pourcent(info.tauxProduit)}</b>
          {' '}— le produit ne dit pas lequel, il ne porte qu’une valeur.{' '}
          {/* ⚠ CETTE PHRASE DEVENAIT FAUSSE AU CLIC. Elle disait « rien n’a été choisi » et le
              restait après qu’on avait choisi, juste au-dessus du taux sélectionné. Une phrase
              qui décrit un état doit se calculer sur l’état, pas sur la raison de l’afficher. */}
          {choisi
            ? <>Vous avez retenu <b>{choisi.libelle}</b>.</>
            : <>Rien n’a été choisi.</>}
          {info.candidats.map((c) => (
            <span key={c.id}>
              {' '}
              <button
                type="button"
                className="btn ghost xs"
                onClick={() => onTaux(c.id)}
                disabled={choisi ? choisi.id === c.id : false}
              >
                {c.libelle}
              </button>
            </span>
          ))}
        </p>
      )}

      {/* ── LA CATÉGORIE COMPTABLE, QUI NE SE REPREND PAS ────────────────────────────────── */}
      {info.compteComptable && (
        <p>
          Le produit porte le compte comptable <b>{info.compteComptable}</b>, mais la ligne attend
          une <b>catégorie</b> comptable — deux objets différents, que rien ne relie dans le
          modèle. La catégorie se choisit donc à la main, ci-dessus.
        </p>
      )}
    </div>
  )
}

function idDe(v) {
  if (!v) return ''
  if (typeof v === 'string') return v.split('/').pop()
  return v.id || String(v['@id'] || '').split('/').pop()
}
