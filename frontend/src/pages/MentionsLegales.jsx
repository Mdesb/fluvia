import { useCallback, useEffect, useState } from 'react'
import { api, membres } from '../api/client.js'
import { aLeDroit } from '../api/droits.js'
import Modal from '../components/Modal.jsx'
import Tabs from '../components/Tabs.jsx'

/**
 * MENTIONS LÉGALES — l'écran qui remplit à la place du client ce qu'il ne sait pas rédiger.
 *
 * **Ce que cet écran n'est pas.** Ce n'est pas un éditeur de texte avec six pages vides. Maxime :
 * *« le client va devoir remplir donc il faut l'aider là-dessus »*. Un gabarit à trous produit ce qu'on
 * voit sur la moitié des sites français — un texte publié où subsiste `[NOM DE LA SOCIÉTÉ]`, que
 * personne n'a relu parce que personne ne savait quoi y mettre.
 *
 * L'écran demande donc **des faits**, pas de la rédaction : raison sociale, SIRET, hébergeur, médiateur,
 * et surtout **ce qui est vendu**. Le serveur en compose six documents.
 *
 * **Le champ décisif est « ce que vous vendez », et il ne ressemble pas à un champ décisif.** Le droit
 * de rétractation n'est pas le même pour un billet daté (aucune rétractation, art. L221-28 12°) et pour
 * un mug de la boutique (quatorze jours, formulaire type obligatoire). Un exploitant qui vend les deux
 * et publie des CGV de billetterie refuse un remboursement légalement dû.
 *
 * > **Une clause fausse ne se distingue pas d'une clause juste tant que personne ne la conteste.**
 *
 * D'où l'ordre des onglets : **la fiche d'abord, les textes ensuite.** On ne peut pas générer avant de
 * savoir, et laisser l'exploitant commencer par les textes l'amènerait à écrire ce que le générateur
 * allait écrire pour lui.
 */

const ACTIVITES = [
  ['dated_leisure', 'Billets et créneaux à date déterminée', 'Entrées, séances, réservations de terrain, visites. Pas de droit de rétractation.'],
  ['accommodation', 'Hébergement (nuitées, séjours)', 'Même régime : pas de rétractation sur une date déterminée.'],
  ['physical_goods', 'Marchandises physiques', 'Boutique, click and collect. Quatorze jours de rétractation, formulaire type obligatoire.'],
  ['subscription', 'Abonnements et cartes', 'Quatorze jours, sauf exécution commencée à la demande du client.'],
  ['course_or_rental', 'Cours, stages et locations', 'Quatorze jours par défaut — un stage à dates fixées peut en être exempté, à vérifier.'],
  ['catering', 'Restauration', 'Consommations sur place : pas de rétractation.'],
]

const CHAMPS = [
  ['legalName', 'Dénomination sociale', 'text', 'Ex. Régie municipale des sports'],
  ['legalForm', 'Forme juridique', 'text', 'SARL, SAS, régie, EPIC, commune…'],
  ['shareCapital', 'Capital social', 'text', 'Sans objet pour une régie ou une commune'],
  ['siret', 'SIRET', 'text', '14 chiffres'],
  ['tradeRegister', 'RCS ou immatriculation', 'text', 'Ex. RCS Lyon 123 456 789'],
  ['vatNumber', 'TVA intracommunautaire', 'text', 'Ex. FR12345678901'],
  ['registeredAddress', 'Adresse du siège', 'textarea', ''],
  ['contactEmail', 'Courriel de contact', 'email', ''],
  ['contactPhone', 'Téléphone', 'text', ''],
  ['publicationDirector', 'Directeur de la publication', 'text', 'Une personne nommée, pas un service'],
]

const CHAMPS_HEBERGEUR = [
  ['hostName', 'Nom de l’hébergeur', 'text', ''],
  ['hostAddress', 'Adresse de l’hébergeur', 'textarea', ''],
  ['hostPhone', 'Téléphone de l’hébergeur', 'text', ''],
]

const CHAMPS_MEDIATEUR = [
  ['mediatorName', 'Nom du médiateur', 'text', ''],
  ['mediatorAddress', 'Adresse du médiateur', 'textarea', ''],
  ['mediatorUrl', 'Site du médiateur', 'text', 'https://…'],
]

const STATUTS = {
  draft: { libelle: 'Brouillon', cls: 'warn' },
  published: { libelle: 'Publié', cls: 'good' },
  superseded: { libelle: 'Version remplacée', cls: 'mut' },
}

export default function MentionsLegales({ etabActif, droits = [] }) {
  const peutGerer = aLeDroit(droits, 'organisation.gerer')
  const [onglet, setOnglet] = useState('fiche')
  const [fiche, setFiche] = useState(null)
  const [documents, setDocuments] = useState([])
  const [chargement, setChargement] = useState(true)
  const [erreur, setErreur] = useState(null)
  const [succes, setSucces] = useState(null)

  const recharger = useCallback(async () => {
    setChargement(true)
    setErreur(null)
    try {
      const [f, d] = await Promise.all([
        api.identitesLegales().catch(() => null),
        api.documentsLegaux().catch(() => null),
      ])
      const fiches = f ? membres(f) : []
      setFiche(fiches[0] || null)
      setDocuments(d ? membres(d) : [])
    } catch (e) {
      setErreur(e.message || 'Les informations légales n’ont pas pu être chargées.')
    } finally {
      setChargement(false)
    }
  }, [])

  useEffect(() => {
    recharger()
  }, [recharger, etabActif])

  return (
    <div className="view">
      <div className="view-head">
        <div className="ttl">
          <h1>Mentions légales</h1>
          <div className="sub">Ce que votre boutique en ligne doit publier</div>
        </div>
      </div>

      {erreur && <div className="banner banner-error">{erreur}</div>}
      {succes && <div className="banner banner-ok">{succes}</div>}

      <Tabs
        onglets={[['fiche', 'Vos informations'], ['documents', `Documents${documents.length ? ` (${documents.length})` : ''}`]]}
        actif={onglet}
        onChange={setOnglet}
      />

      {chargement ? (
        <div className="center" style={{ minHeight: 160 }}><div className="spinner" /></div>
      ) : onglet === 'fiche' ? (
        <Fiche
          fiche={fiche}
          peutGerer={peutGerer}
          onErreur={setErreur}
          onSucces={(m) => { setSucces(m); setErreur(null) }}
          onChange={recharger}
        />
      ) : (
        <Documents
          documents={documents}
          onAllerFiche={() => setOnglet('fiche')}
          fiche={fiche}
          peutGerer={peutGerer}
          onErreur={setErreur}
          onSucces={(m) => { setSucces(m); setErreur(null) }}
          onChange={recharger}
        />
      )}
    </div>
  )
}

function Fiche({ fiche, peutGerer, onErreur, onSucces, onChange }) {
  const [valeurs, setValeurs] = useState({})
  const [activites, setActivites] = useState([])
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    setValeurs(fiche || {})
    setActivites(fiche?.activities || [])
  }, [fiche])

  const set = (cle) => (e) => setValeurs((v) => ({ ...v, [cle]: e.target.value }))

  function basculer(code) {
    setActivites((a) => (a.includes(code) ? a.filter((x) => x !== code) : [...a, code]))
  }

  async function enregistrer() {
    setBusy(true)
    try {
      const corps = {}
      for (const [cle] of [...CHAMPS, ...CHAMPS_HEBERGEUR, ...CHAMPS_MEDIATEUR]) {
        corps[cle] = valeurs[cle] ?? null
      }
      corps.dataProtectionOfficer = valeurs.dataProtectionOfficer ?? null
      corps.activities = activites

      if (fiche?.id) {
        await api.majIdentiteLegale(fiche.id, corps)
      } else {
        // L'ÉTABLISSEMENT N'EST PAS ENVOYÉ, ET CE N'EST PAS UN OUBLI (D41).
        //
        // Ma première version le transmettait — l'écran connaissait l'établissement actif, c'était
        // commode. Le garde-fou l'a refusé : l'appelant choisissait alors de qui sont les mentions
        // légales, donc pouvait publier sous le nom d'un autre exploitant le document qui l'engage.
        // Le serveur l'estampille depuis la session.
        await api.creerIdentiteLegale(corps)
      }
      onSucces('Fiche enregistrée.')
      onChange()
    } catch (e) {
      onErreur(e.message || 'La fiche n’a pas pu être enregistrée.')
    } finally {
      setBusy(false)
    }
  }

  async function generer() {
    setBusy(true)
    try {
      const r = await api.genererDocumentsLegaux(fiche.id)
      onSucces(
        `${(r.brouillonsEcrits || []).length} brouillon(s) écrit(s).`
        + ((r.publiesInchanges || []).length
          // On NOMME ce qui n'a pas bougé. Sans cette phrase, l'exploitant croit que ses textes
          // publiés viennent d'être mis à jour, et ne les republie jamais.
          ? ` Les textes déjà publiés n'ont pas été remplacés : republiez-les pour appliquer les changements.`
          : ''),
      )
      onChange()
    } catch (e) {
      onErreur(e.message || 'La génération a échoué.')
    } finally {
      setBusy(false)
    }
  }

  const champ = ([cle, libelle, type, aide]) => (
    <div key={cle}>
      <label htmlFor={`lg-${cle}`}>{libelle}</label>
      {type === 'textarea' ? (
        <textarea id={`lg-${cle}`} className="input" rows={2} value={valeurs[cle] ?? ''} onChange={set(cle)} disabled={!peutGerer} />
      ) : (
        <input id={`lg-${cle}`} className="input" type={type} value={valeurs[cle] ?? ''} onChange={set(cle)} disabled={!peutGerer} />
      )}
      {aide && <div className="hint" style={{ margin: '2px 0 0' }}>{aide}</div>}
    </div>
  )

  return (
    <div style={{ display: 'grid', gap: 16 }}>
      {/* CE BLOC EST EN PREMIER PARCE QU'IL DÉCIDE DU CONTENU DES CGV.
          Placé en bas comme un réglage accessoire, il serait coché au hasard — et une case cochée au
          hasard ici produit une clause de rétractation fausse, opposable au vendeur. */}
      <section className="card">
        <div className="card-h">
          <span>Ce que vous vendez</span>
          <span className="sub" style={{ marginLeft: 8 }}>décide des clauses de rétractation</span>
        </div>
        <div style={{ display: 'grid', gap: 10, padding: 14 }}>
          {ACTIVITES.map(([code, libelle, aide]) => (
            <label key={code} style={{ display: 'flex', gap: 10, alignItems: 'flex-start', cursor: peutGerer ? 'pointer' : 'default' }}>
              <input
                type="checkbox"
                checked={activites.includes(code)}
                onChange={() => basculer(code)}
                disabled={!peutGerer}
                style={{ marginTop: 3 }}
              />
              <span>
                <span className="nm">{libelle}</span>
                <div className="sub">{aide}</div>
              </span>
            </label>
          ))}
          {activites.length === 0 && (
            <div className="hint" style={{ margin: 0 }}>
              Tant qu&rsquo;aucune activité n&rsquo;est cochée, les CGV ne seront pas générées : mieux
              vaut aucun texte qu&rsquo;un régime de rétractation choisi au hasard.
            </div>
          )}
        </div>
      </section>

      <section className="card">
        <div className="card-h"><span>Éditeur du site</span></div>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(280px, 1fr))', gap: 14, padding: 14 }}>
          {CHAMPS.map(champ)}
        </div>
      </section>

      <section className="card">
        <div className="card-h">
          <span>Hébergeur</span>
          <span className="sub" style={{ marginLeft: 8 }}>exigé par la LCEN, et systématiquement oublié</span>
        </div>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(280px, 1fr))', gap: 14, padding: 14 }}>
          {CHAMPS_HEBERGEUR.map(champ)}
        </div>
      </section>

      <section className="card">
        <div className="card-h">
          <span>Médiateur de la consommation</span>
          <span className="sub" style={{ marginLeft: 8 }}>l&rsquo;adhésion est obligatoire, même sans litige</span>
        </div>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(280px, 1fr))', gap: 14, padding: 14 }}>
          {CHAMPS_MEDIATEUR.map(champ)}
          {champ(['dataProtectionOfficer', 'Délégué à la protection des données', 'text', 'Nom ou adresse de contact'])}
        </div>
      </section>

      {peutGerer && (
        <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end' }}>
          <button className="btn primary" type="button" disabled={busy} onClick={enregistrer}>
            Enregistrer la fiche
          </button>
          {fiche?.id && (
            <button className="btn" type="button" disabled={busy} onClick={generer}>
              Générer les six documents
            </button>
          )}
        </div>
      )}
    </div>
  )
}

function Documents({ documents, fiche, peutGerer, onAllerFiche, onErreur, onSucces, onChange }) {
  const [edite, setEdite] = useState(null)
  const [texte, setTexte] = useState('')
  const [busy, setBusy] = useState(false)

  // Les versions remplacées restent en base pour la preuve, et n'ont rien à faire dans la liste de
  // travail : les afficher ferait chercher laquelle est en ligne.
  const courants = documents.filter((d) => d.status !== 'superseded')

  if (courants.length === 0) {
    return (
      // UN MESSAGE DE VIDE QUI DÉSIGNE UN BOUTON ABSENT DE L'ÉCRAN QU'ON REGARDE.
      //
      // Il disait « utilisez “Générer les six documents” » — et ce bouton est sur l'AUTRE onglet.
      // Constaté en ouvrant l'écran : on lit une consigne, on cherche le bouton, il n'y est pas.
      // Un état vide doit porter le geste qui le remplit, ou au minimum y conduire ; l'indiquer
      // sans y mener transforme une explication en devinette.
      <div className="card">
        <div className="sub" style={{ textAlign: 'center', padding: 28 }}>
          Aucun document pour l&rsquo;instant.{' '}
          {fiche?.id
            ? 'Les six textes se composent depuis votre fiche, en un geste.'
            : 'Renseignez d’abord vos informations : les textes en découlent.'}
          {onAllerFiche && (
            <div style={{ marginTop: 12 }}>
              <button className="btn primary sm" type="button" onClick={onAllerFiche}>
                {fiche?.id ? 'Aller générer les documents' : 'Renseigner la fiche'}
              </button>
            </div>
          )}
        </div>
      </div>
    )
  }

  async function publier(d) {
    setBusy(true)
    try {
      await api.publierDocumentLegal(d.id)
      onSucces(`« ${d.title} » est publié et visible sur la boutique.`)
      onChange()
    } catch (e) {
      onErreur(e.message || 'La publication a échoué.')
    } finally {
      setBusy(false)
    }
  }

  async function enregistrer() {
    setBusy(true)
    try {
      await api.majDocumentLegal(edite.id, { content: texte })
      setEdite(null)
      onSucces('Texte enregistré.')
      onChange()
    } catch (e) {
      onErreur(e.message || 'Le texte n’a pas pu être enregistré.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <div style={{ display: 'grid', gap: 12 }}>
      {courants.map((d) => (
        <section className="card" key={d.id}>
          <div className="card-h">
            <span>{d.title}</span>
            <span className={`badge ${STATUTS[d.status]?.cls || 'mut'}`} style={{ marginLeft: 8 }}>
              {STATUTS[d.status]?.libelle || d.status}
            </span>
            {d.version > 0 && <span className="sub" style={{ marginLeft: 8 }}>version {d.version}</span>}
            <div style={{ marginLeft: 'auto', display: 'flex', gap: 8 }}>
              <button className="btn ghost sm" type="button" onClick={() => { setEdite(d); setTexte(d.content || '') }}>
                {peutGerer ? 'Relire et modifier' : 'Lire'}
              </button>
              {peutGerer && d.status === 'draft' && (
                <button className="btn primary sm" type="button" disabled={busy} onClick={() => publier(d)}>
                  Publier
                </button>
              )}
            </div>
          </div>

          {/* CE QUI MANQUE EST NOMMÉ ICI, PAS LAISSÉ EN BLANC DANS LE TEXTE.
              Un trou anonyme se publie ; un trou nommé se comble. */}
          {(d.missingFields || []).length > 0 && (
            <div className="banner banner-warn" style={{ margin: 12 }}>
              <b>À compléter avant publication :</b> {d.missingFields.join(', ')}.
              <div className="sub" style={{ marginTop: 4 }}>
                Renseignez ces informations dans l&rsquo;onglet « Vos informations », puis régénérez.
              </div>
            </div>
          )}

          {d.status === 'draft' && (d.content || '').includes('Brouillon généré automatiquement') && (
            <div className="hint" style={{ margin: '0 12px 12px' }}>
              Le texte porte encore l&rsquo;avertissement de brouillon. La publication le refusera tant
              que ce bloc n&rsquo;est pas retiré — c&rsquo;est ce qui garantit qu&rsquo;un humain l&rsquo;a lu.
            </div>
          )}
        </section>
      ))}

      <Modal open={!!edite} onClose={() => setEdite(null)} titre={edite?.title || ''} taille="lg">
        <div style={{ display: 'grid', gap: 12 }}>
          <textarea
            className="input"
            style={{ minHeight: '52vh', fontFamily: 'ui-monospace, monospace', fontSize: 13, lineHeight: 1.6 }}
            value={texte}
            onChange={(e) => setTexte(e.target.value)}
            disabled={!peutGerer}
          />
          <div className="hint" style={{ margin: 0 }}>
            Rédigé en Markdown : <code>#</code> pour un titre, <code>-</code> pour une liste,
            <code>**gras**</code>. Le rendu se fait sur la boutique.
          </div>
          {peutGerer && (
            <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8 }}>
              <button className="btn ghost" type="button" onClick={() => setEdite(null)}>Annuler</button>
              <button className="btn primary" type="button" disabled={busy} onClick={enregistrer}>
                Enregistrer
              </button>
            </div>
          )}
        </div>
      </Modal>
    </div>
  )
}
