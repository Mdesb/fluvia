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
  }, [open])

  function majLigne(i, champ, valeur) {
    setLignes((precedent) => precedent.map((l, j) => (i === j ? { ...l, [champ]: valeur } : l)))
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

          {lignes.map((ligne, i) => (
            <div className="field" key={i}>
              <label htmlFor={`dm-ligne-${i}`}>Ligne {i + 1}</label>
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
            </div>
          ))}

          {/* ⚠ CE QUE LA CATÉGORIE CHANGE — ET AUJOURD'HUI, SUR CET ÉTABLISSEMENT, RIEN.
              `ResolveurComptesFacturation` cherche une correspondance entre la catégorie et un
              compte de produit ; s'il n'en trouve pas, il se replie SANS RIEN DIRE sur le compte
              par défaut. Un champ qu'on remplit consciencieusement et qui n'a aucun effet est
              exactement ce qu'on retire ailleurs : ici on le garde — il enregistre l'intention et
              deviendra effectif — mais on dit son état. */}
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

const LIGNE_VIDE = { designation: '', quantite: 1, prixUnitaireHT: '', tauxTva: '' , categorieComptable: ''}

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

function idDe(v) {
  if (!v) return ''
  if (typeof v === 'string') return v.split('/').pop()
  return v.id || String(v['@id'] || '').split('/').pop()
}
