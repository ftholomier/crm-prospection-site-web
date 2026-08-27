/* ==========================================================================
   SOCLE DE MAQUETTAGE — le peu de JavaScript nécessaire
   --------------------------------------------------------------------------
   Deux comportements, pas un de plus. Écrit en ES5 tolérant : chaque bloc se
   désarme tout seul si sa cible est absente de la page, si bien qu'une
   maquette qui n'a pas d'en-tête ne produit pas d'erreur.
   ========================================================================== */
(function () {
  "use strict";

  /* --- 1. La barre collante -----------------------------------------------
     Une classe, posée au-delà de 40 px de défilement. Tout le reste — la
     réduction de hauteur, le fond translucide, l'allumage du faisceau — est
     affaire de CSS. Le seuil n'est pas à zéro : sinon la barre clignote au
     moindre rebond de défilement sur mobile. */
  var entete = document.querySelector(".entete");
  if (entete) {
    var enAttente = false;
    var majBarre = function () {
      entete.classList.toggle("entete--pleine", window.scrollY > 40);
      enAttente = false;
    };
    // On ne lit la position qu'une fois par image : lire scrollY à chaque
    // événement force le navigateur à recalculer la mise en page.
    window.addEventListener("scroll", function () {
      if (!enAttente) {
        enAttente = true;
        window.requestAnimationFrame(majBarre);
      }
    }, { passive: true });
    majBarre();
  }

  /* --- 2. La révélation au défilement --------------------------------------
     Chaque élément marqué .reveler apparaît lorsqu'il entre dans le cadre,
     puis on cesse de l'observer : une fois révélé, il n'a plus rien à dire.
     Sans IntersectionObserver, tout est montré d'emblée — dégradé, pas
     cassé. */
  var aReveler = document.querySelectorAll(".reveler");
  if (!aReveler.length) { return; }

  if (!("IntersectionObserver" in window)) {
    for (var i = 0; i < aReveler.length; i++) { aReveler[i].classList.add("visible"); }
    return;
  }

  var vigie = new IntersectionObserver(function (entrees) {
    entrees.forEach(function (entree) {
      if (entree.isIntersecting) {
        entree.target.classList.add("visible");
        vigie.unobserve(entree.target);
      }
    });
  }, { threshold: 0.12, rootMargin: "0px 0px -60px 0px" });

  for (var j = 0; j < aReveler.length; j++) { vigie.observe(aReveler[j]); }
}());
