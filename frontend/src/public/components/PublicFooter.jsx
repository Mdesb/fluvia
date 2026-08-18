// Pied de page public : mentions minimales + rappel service public / RGPD.
export default function PublicFooter() {
  return (
    <footer className="pub-footer">
      <div className="pub-footer-in">
        <p>Billetterie en ligne · Service public</p>
        <p className="pub-footer-sub">
          Paiement sécurisé · Vos données sont traitées conformément au RGPD.
        </p>
      </div>
    </footer>
  )
}
