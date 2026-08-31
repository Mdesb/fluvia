import { useEffect, useState } from 'react'
import { api } from '../api/client.js'
import Modal from './Modal.jsx'

// Modification d'une fiche client (D13 : la modale est le défaut).
//
// La fiche 360° était en lecture seule, et elle n'affichait qu'une partie de ce que le modèle porte
// déjà : la civilité, la date de naissance, la raison sociale et le SIRET existaient en base sans
// être ni montrés ni modifiables. Ce n'était donc pas un manque de modèle — c'était du branchement.
//
// POURQUOI LA MODALE CHARGE SA PROPRE COPIE. La fiche 360° est une vue d'ensemble : elle ne porte
// qu'un sous-ensemble des champs. Modifier depuis ce sous-ensemble aurait renvoyé au serveur une
// fiche amputée de ce qu'elle n'affiche pas. On relit donc le client entier avant d'ouvrir.
//
// LES CHAMPS SUIVENT LE TYPE. Un particulier n'a pas de raison sociale ; une entreprise n'a pas de
// date de naissance. Afficher les deux séries à tout le monde double la hauteur du formulaire et
// fait hésiter sur ce qu'il faut remplir. Le type commande donc ce qu'on voit.

const CIVILITES = ['', 'Mme', 'M.']

const VALEURS_VIDES = {
  type: 'physique',
  civilite: '',
  nom: '',
  prenom: '',
  raisonSociale: '',
  siret: '',
  dateNaissance: '',
  email: '',
  telephone: '',
  rue: '',
  complement: '',
  cp: '',
  ville: '',
  pays: '',
}

export default function ClientEditionModal({ open, clientId, onClose, onEnregistre }) {
  const [valeurs, setValeurs] = useState(null)
  const [chargement, setChargement] = useState(false)
  const [erreur, setErreur] = useState(null)
  const [enCours, setEnCours] = useState(false)

  useEffect(() => {
    if (!open) return undefined
    // CREER UN CLIENT PASSE PAR CE MEME FORMULAIRE, ET C'EST DELIBERE.
    //
    // `api.creerClient` existait et n'etait appele que par `ClientPicker`, dont le formulaire ne
    // sait creer qu'une personne PHYSIQUE avec quatre champs. Ecrire un second formulaire de
    // creation aurait donne deux verites sur ce qu'est un client : celui-ci gere les deux types,
    // l'adresse structuree, le SIRET et la date de naissance, et il connait deja les regles
    // (adresse entierement vide envoyee a `null`, champs de personne morale exclusifs).
    if (!clientId) {
      setValeurs({ ...VALEURS_VIDES })
      setErreur(null)
      setChargement(false)
      return undefined
    }
    let annule = false
    setValeurs(null)
    setErreur(null)
    setChargement(true)
    api
      .client(clientId)
      .then((c) => {
        if (annule) return
        const a = c.adresse || {}
        setValeurs({
          type: c.type || 'physique',
          civilite: c.civilite || '',
          nom: c.nom || '',
          prenom: c.prenom || '',
          raisonSociale: c.raisonSociale || '',
          siret: c.siret || '',
          dateNaissance: (c.dateNaissance || '').slice(0, 10),
          email: c.email || '',
          telephone: c.telephone || '',
          rue: a.rue || '',
          complement: a.complement || '',
          cp: a.cp || '',
          ville: a.ville || '',
          pays: a.pays || '',
        })
      })
      .catch((e) => {
        if (!annule) setErreur(e.message || 'Fiche indisponible.')
      })
      .finally(() => {
        if (!annule) setChargement(false)
      })
    return () => {
      annule = true
    }
  }, [open, clientId])

  function champ(nom, v) {
    setValeurs((s) => ({ ...s, [nom]: v }))
  }

  async function enregistrer(e) {
    e.preventDefault()
    setErreur(null)
    setEnCours(true)
    try {
      const morale = valeurs.type === 'morale'
      // Une adresse entièrement vide part à `null` plutôt qu'en objet de chaînes vides : sinon on
      // enregistre une adresse qui a l'air renseignée et qui ne l'est pas, et la fiche affiche des
      // séparateurs autour de rien.
      const adresse = ['rue', 'complement', 'cp', 'ville', 'pays'].some((k) => valeurs[k].trim() !== '')
        ? {
            rue: valeurs.rue.trim() || null,
            complement: valeurs.complement.trim() || null,
            cp: valeurs.cp.trim() || null,
            ville: valeurs.ville.trim() || null,
            pays: valeurs.pays.trim() || null,
          }
        : null

      const corps = {
        type: valeurs.type,
        civilite: morale ? null : valeurs.civilite || null,
        nom: valeurs.nom.trim() || null,
        prenom: morale ? null : valeurs.prenom.trim() || null,
        raisonSociale: morale ? valeurs.raisonSociale.trim() || null : null,
        siret: morale ? valeurs.siret.trim() || null : null,
        dateNaissance: morale ? null : valeurs.dateNaissance || null,
        email: valeurs.email.trim() || null,
        telephone: valeurs.telephone.trim() || null,
        adresse,
      }
      const enregistre = clientId ? await api.majClient(clientId, corps) : await api.creerClient(corps)
      onEnregistre?.(enregistre)
      onClose?.()
    } catch (err) {
      setErreur(err.message || "L'enregistrement n'a pas abouti.")
    } finally {
      setEnCours(false)
    }
  }

  const morale = valeurs?.type === 'morale'

  return (
    <Modal open={open} onClose={onClose} titre={clientId ? 'Modifier la fiche' : 'Ajouter un client'} taille="lg">
      {erreur && <div className="banner banner-error">{erreur}</div>}

      {chargement || !valeurs ? (
        <div className="center" style={{ minHeight: 120 }}><div className="spinner" /></div>
      ) : (
        <form onSubmit={enregistrer}>
          <div className="field">
            <label htmlFor="cl-type">Ce client est</label>
            <select id="cl-type" className="select" value={valeurs.type} onChange={(e) => champ('type', e.target.value)}>
              <option value="physique">Un particulier</option>
              <option value="morale">Une entreprise ou une association</option>
            </select>
          </div>

          <div className="grid g2" style={{ gap: 12 }}>
            {!morale && (
              <div className="field">
                <label htmlFor="cl-civ">Civilité</label>
                <select id="cl-civ" className="select" value={valeurs.civilite} onChange={(e) => champ('civilite', e.target.value)}>
                  {CIVILITES.map((c) => (
                    <option key={c || 'aucune'} value={c}>{c || '—'}</option>
                  ))}
                </select>
              </div>
            )}
            <div className="field">
              <label htmlFor="cl-nom">{morale ? 'Nom du contact' : 'Nom'}</label>
              <input id="cl-nom" className="input" value={valeurs.nom} onChange={(e) => champ('nom', e.target.value)} />
            </div>
            {!morale && (
              <div className="field">
                <label htmlFor="cl-prenom">Prénom</label>
                <input id="cl-prenom" className="input" value={valeurs.prenom} onChange={(e) => champ('prenom', e.target.value)} />
              </div>
            )}
            {!morale && (
              <div className="field">
                <label htmlFor="cl-naiss">Date de naissance</label>
                <input
                  id="cl-naiss"
                  className="input"
                  type="date"
                  value={valeurs.dateNaissance}
                  onChange={(e) => champ('dateNaissance', e.target.value)}
                />
                <div className="hint">Sert aux tarifs liés à l'âge, quand vous en proposez.</div>
              </div>
            )}
            {morale && (
              <div className="field">
                <label htmlFor="cl-rs">Raison sociale</label>
                <input id="cl-rs" className="input" value={valeurs.raisonSociale} onChange={(e) => champ('raisonSociale', e.target.value)} />
              </div>
            )}
            {morale && (
              <div className="field">
                <label htmlFor="cl-siret">SIRET</label>
                <input id="cl-siret" className="input" value={valeurs.siret} onChange={(e) => champ('siret', e.target.value)} />
                <div className="hint">Nécessaire pour facturer une entreprise.</div>
              </div>
            )}
          </div>

          <div className="fiche-sec" style={{ marginTop: 16 }}>Coordonnées</div>
          <div className="grid g2" style={{ gap: 12 }}>
            <div className="field">
              <label htmlFor="cl-mail">E-mail</label>
              <input id="cl-mail" className="input" type="email" value={valeurs.email} onChange={(e) => champ('email', e.target.value)} />
              <div className="hint">Sert à envoyer les billets et les reçus.</div>
            </div>
            <div className="field">
              <label htmlFor="cl-tel">Téléphone</label>
              <input id="cl-tel" className="input" value={valeurs.telephone} onChange={(e) => champ('telephone', e.target.value)} />
            </div>
          </div>

          <div className="fiche-sec" style={{ marginTop: 16 }}>Adresse</div>
          <div className="field">
            <label htmlFor="cl-rue">Rue</label>
            <input id="cl-rue" className="input" value={valeurs.rue} onChange={(e) => champ('rue', e.target.value)} />
          </div>
          <div className="field">
            <label htmlFor="cl-comp">Complément</label>
            <input id="cl-comp" className="input" value={valeurs.complement} onChange={(e) => champ('complement', e.target.value)} placeholder="Bâtiment, étage…" />
          </div>
          <div className="grid g3" style={{ gap: 12 }}>
            <div className="field">
              <label htmlFor="cl-cp">Code postal</label>
              <input id="cl-cp" className="input" value={valeurs.cp} onChange={(e) => champ('cp', e.target.value)} />
            </div>
            <div className="field">
              <label htmlFor="cl-ville">Ville</label>
              <input id="cl-ville" className="input" value={valeurs.ville} onChange={(e) => champ('ville', e.target.value)} />
            </div>
            <div className="field">
              <label htmlFor="cl-pays">Pays</label>
              <input id="cl-pays" className="input" value={valeurs.pays} onChange={(e) => champ('pays', e.target.value)} />
            </div>
          </div>

          <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 16 }}>
            <button className="btn" type="button" onClick={onClose}>Annuler</button>
            <button className="btn primary" type="submit" disabled={enCours}>
              {enCours ? 'Enregistrement…' : clientId ? 'Enregistrer' : 'Créer la fiche'}
            </button>
          </div>
        </form>
      )}
    </Modal>
  )
}
