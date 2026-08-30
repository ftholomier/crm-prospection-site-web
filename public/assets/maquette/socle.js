/* ==========================================================================
   SOCLE DE MAQUETTAGE — le peu de JavaScript nécessaire
   --------------------------------------------------------------------------
   Cinq comportements, pas un de plus : barre collante, diaporama du bandeau,
   panneau de navigation, bascule entre les deux dispositions de menu, et
   révélation au défilement. Écrit en ES5 tolérant : chaque bloc se désarme
   tout seul si sa cible est absente de la page, si bien qu'une maquette sans
   en-tête ou sans diaporama ne produit pas d'erreur.
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

  /* --- 2. Le diaporama du bandeau -------------------------------------------
     Repris du site Baron Paysage. Le fondu et le mouvement sont laissés au
     CSS ; ce bloc ne fait qu'ordonner les tours. Deux précautions contre
     l'à-coup : l'animation de la vue entrante est relancée avant qu'elle ne
     devienne visible, et celle de la vue sortante n'est coupée qu'une fois le
     fondu terminé. Le compte à rebours s'arrête quand l'onglet passe en
     arrière-plan, sinon le retour rattrape d'un coup toutes les vues
     manquées. */
  (function () {
    var scene = document.querySelector("[data-diaporama]");
    if (!scene) { return; }

    var vues = [].slice.call(scene.querySelectorAll("[data-vue]"));
    if (vues.length < 2) {
      if (vues.length === 1) { vues[0].classList.add("est-visible", "se-rapproche"); }
      return;
    }

    var trait = document.querySelector("[data-diaporama-trait]");
    var doux = window.matchMedia("(prefers-reduced-motion: reduce)");
    var lire = function (nom, defaut) {
      var brut = parseFloat(getComputedStyle(scene).getPropertyValue(nom));
      return (brut > 0 ? brut : defaut) * 1000;
    };
    var FONDU = lire("--fondu", 1.2);
    var pause = lire("--pause", 3);
    var courant = 0;
    var minuteur = null;

    var relancer = function (element, classe) {
      element.classList.remove(classe);
      void element.offsetWidth; // forcer la reprise de l'animation depuis son début
      element.classList.add(classe);
    };

    /* La vue entrante monte SEULE, posée au-dessus de la sortante qui reste
       pleinement opaque jusqu'au bout. Croiser les deux fondus ferait passer
       les deux par la moitié au même instant, et le fond de page se verrait
       entre elles — c'est le voile clair qu'on observait à chaque changement.
       La sortante n'est effacée qu'une fois le fondu terminé. */
    var rangZ = 1;
    var afficher = function (rang) {
      var sortante = vues[courant];
      var entrante = vues[rang];

      sortante.classList.add("sortante");
      entrante.style.zIndex = String(++rangZ);
      relancer(entrante, "se-rapproche");
      entrante.classList.add("est-visible");

      window.setTimeout(function () {
        // « courant » a pu changer si l'on a sauté une vue entre-temps
        if (sortante !== vues[courant]) {
          sortante.classList.remove("sortante", "est-visible", "se-rapproche");
          sortante.style.zIndex = "";
        }
      }, FONDU + 40);

      courant = rang;
      if (trait) { relancer(trait, "court"); }
    };

    var arreter = function () {
      if (minuteur) { window.clearInterval(minuteur); minuteur = null; }
    };
    var lancer = function () {
      arreter();
      if (doux.matches) { return; }
      // « pause » est le temps pendant lequel une vue reste pleinement
      // visible ; le fondu vient s'y ajouter. Compter le fondu dans la pause
      // reviendrait à ne montrer la photo nette qu'un peu plus d'une seconde.
      minuteur = window.setInterval(function () {
        afficher((courant + 1) % vues.length);
      }, pause + FONDU);
    };

    document.addEventListener("visibilitychange", function () {
      if (document.hidden) { arreter(); return; }
      if (trait) { relancer(trait, "court"); }
      lancer();
    });

    relancer(vues[0], "se-rapproche");
    if (trait) { relancer(trait, "court"); }
    lancer();
  }());

  /* --- 3. Le panneau de navigation ------------------------------------------
     Servi tel quel en disposition latérale, et comme menu du téléphone en
     disposition horizontale. Le panneau est rendu inerte au repos : sans
     cela il resterait atteignable au clavier une fois refermé. */
  (function () {
    var burger = document.querySelector(".burger");
    var panneau = document.querySelector(".panneau");
    if (!burger || !panneau) { return; }

    var voile = document.querySelector(".voile");
    var fermerBtn = panneau.querySelector(".panneau__fermer");
    var corps = document.body;

    var ouvrir = function () {
      corps.classList.add("menu-ouvert");
      burger.setAttribute("aria-expanded", "true");
      panneau.removeAttribute("inert");
      var premier = panneau.querySelector("a, button");
      if (premier) { premier.focus(); }
    };
    var fermer = function (rendreFocus) {
      corps.classList.remove("menu-ouvert");
      burger.setAttribute("aria-expanded", "false");
      panneau.setAttribute("inert", "");
      if (rendreFocus !== false) { burger.focus(); }
    };

    panneau.setAttribute("inert", "");
    burger.addEventListener("click", function () {
      if (corps.classList.contains("menu-ouvert")) { fermer(); } else { ouvrir(); }
    });
    if (fermerBtn) { fermerBtn.addEventListener("click", function () { fermer(); }); }
    if (voile) { voile.addEventListener("click", function () { fermer(); }); }
    document.addEventListener("keydown", function (e) {
      if (e.key === "Escape" && corps.classList.contains("menu-ouvert")) { fermer(); }
    });

    /* Un menu ouvert sur téléphone puis élargi en disposition horizontale
       laisserait le panneau visible et le défilement bloqué, sans plus aucun
       bouton pour le refermer : le burger a disparu. */
    var large = window.matchMedia("(min-width: 1000px)");
    var surveiller = function (e) {
      if (e.matches && corps.classList.contains("menu-horizontal")
          && corps.classList.contains("menu-ouvert")) {
        fermer(false);
      }
    };
    if (large.addEventListener) { large.addEventListener("change", surveiller); }
    else if (large.addListener) { large.addListener(surveiller); }

    /* --- 4. La bascule entre les deux dispositions -------------------------
       C'est une pièce de maquette, pas du site livré : elle existe pour que le
       prospect essaie les deux menus et tranche en regardant. Le choix est
       retenu d'une page à l'autre — sans cela, il faudrait rebasculer à chaque
       navigation et la comparaison n'aurait plus lieu. */
    var bascule = document.querySelector("[data-bascule-menu]");
    if (!bascule) { return; }

    var entete = document.querySelector(".entete");
    var etiquette = bascule.querySelector("[data-bascule-libelle]");
    var CLE = "maquette-menu";

    var appliquer = function (mode) {
      var horizontal = mode === "horizontal";
      corps.classList.toggle("menu-horizontal", horizontal);
      corps.classList.toggle("menu-lateral", !horizontal);
      if (entete) {
        entete.classList.toggle("entete--horizontal", horizontal);
        entete.classList.toggle("entete--lateral", !horizontal);
      }
      bascule.setAttribute("aria-pressed", horizontal ? "true" : "false");
      if (etiquette) { etiquette.textContent = horizontal ? "Menu horizontal" : "Menu latéral"; }
      if (horizontal && large.matches && corps.classList.contains("menu-ouvert")) { fermer(false); }
    };

    var lu = null;
    try { lu = window.localStorage.getItem(CLE); } catch (e) { lu = null; }
    // Le mode initial vient de la page elle-même : c'est celui que l'agence a
    // choisi de montrer en premier. Le visiteur peut ensuite en changer.
    appliquer(lu || (entete && entete.classList.contains("entete--horizontal") ? "horizontal" : "lateral"));

    bascule.addEventListener("click", function () {
      var suivant = corps.classList.contains("menu-horizontal") ? "lateral" : "horizontal";
      appliquer(suivant);
      try { window.localStorage.setItem(CLE, suivant); } catch (e) { /* navigation privée */ }
    });
  }());

  /* --- 5. La révélation au défilement --------------------------------------
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
