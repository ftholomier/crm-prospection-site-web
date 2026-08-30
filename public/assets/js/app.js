/* Prospect Studio — interactions de l'administration.
   Le front reste volontairement minimal : pas de framework, pas de build. */

(function () {
    'use strict';

    /** Pilote la barre de progression et le chronomètre du panneau. */
    var suivi = {
        depart: 0,
        minuteur: null,
        total: 0,
        faits: 0,

        demarrer: function (titre, total) {
            var panneau = document.getElementById('run-panel');
            var barre = document.getElementById('run-progress');
            var titreEl = document.getElementById('run-title');
            if (panneau) panneau.hidden = false;
            if (titreEl && titre) titreEl.textContent = titre;

            this.depart = Date.now();
            this.total = total || 0;
            this.faits = 0;
            if (barre) {
                barre.classList.toggle('indeterminate', !this.total);
                var jauge = barre.firstElementChild;
                if (jauge) jauge.style.width = this.total ? '2%' : '';
            }

            var self = this;
            clearInterval(this.minuteur);
            this.minuteur = setInterval(function () {
                var horloge = document.getElementById('run-clock');
                if (!horloge) return;
                var s = Math.round((Date.now() - self.depart) / 1000);
                horloge.textContent = s < 60 ? s + ' s' : Math.floor(s / 60) + ' min ' + (s % 60) + ' s';
            }, 500);
        },

        avancer: function () {
            this.faits++;
            var barre = document.getElementById('run-progress');
            if (!barre || !this.total) return;
            var jauge = barre.firstElementChild;
            if (jauge) jauge.style.width = Math.min(100, Math.round(this.faits / this.total * 100)) + '%';
        },

        terminer: function (reussi) {
            clearInterval(this.minuteur);
            var barre = document.getElementById('run-progress');
            if (!barre) return;
            barre.classList.remove('indeterminate');
            var jauge = barre.firstElementChild;
            if (jauge) {
                jauge.style.width = '100%';
                if (!reussi) jauge.style.background = 'var(--danger)';
            }
        }
    };

    /** Écrit une ligne dans la console de progression. */
    function logLine(box, text, kind) {
        if (!box) return;
        var line = document.createElement('div');
        line.className = 'line' + (kind ? ' ' + kind : '');
        line.textContent = text;
        box.appendChild(line);
        box.scrollTop = box.scrollHeight;
        return line;
    }

    /**
     * Exécute une étape distante en Server-Sent Events.
     * Résout avec les données de l'événement « done ».
     */
    function runStep(url, box, handlers) {
        return new Promise(function (resolve, reject) {
            var source = new EventSource(url);
            var progressLine = null;
            var settled = false;

            function finish(fn, value) {
                if (settled) return;
                settled = true;
                source.close();
                fn(value);
            }

            var enCours = null;
            source.addEventListener('step', function (event) {
                var data = JSON.parse(event.data);
                progressLine = null;
                // L'étape précédente cesse de clignoter dès que la suivante arrive.
                if (enCours) enCours.classList.remove('pending');
                var kind = data.state === 'done' ? 'done' : (data.state === 'warn' ? 'warn' : 'pending');
                enCours = logLine(box, data.message, kind);
                if (data.state === 'done') { enCours.classList.remove('pending'); enCours = null; }
            });

            source.addEventListener('progress', function (event) {
                var data = JSON.parse(event.data);
                var text = '  … ' + data.chars.toLocaleString('fr-FR') + ' caractères générés';
                if (progressLine) {
                    progressLine.textContent = text;
                } else {
                    progressLine = logLine(box, text, 'progress');
                }
            });

            source.addEventListener('brief', function (event) {
                var data = JSON.parse(event.data);
                if (handlers && handlers.onBrief) handlers.onBrief(data);
                if (data.accroche) logLine(box, '  « ' + data.accroche + ' »');
            });

            source.addEventListener('error', function (event) {
                if (event.data) {
                    var data = JSON.parse(event.data);
                    logLine(box, 'Erreur : ' + data.message, 'err');
                    finish(reject, new Error(data.message));
                } else if (source.readyState === EventSource.CLOSED) {
                    logLine(box, 'Connexion interrompue par le serveur.', 'err');
                    finish(reject, new Error('Connexion interrompue.'));
                }
            });

            source.addEventListener('done', function (event) {
                finish(resolve, JSON.parse(event.data));
            });
        });
    }

    /**
     * Traitements en flux déclenchés depuis la fiche prospect : analyse directe
     * et lecture par l'IA. Chaque bouton porteur de data-analyze est câblé —
     * querySelector n'en renverrait qu'un seul et laisserait les autres inertes.
     */
    function bindAnalyze() {
        Array.prototype.forEach.call(document.querySelectorAll('[data-analyze]'), function (button) {
            var repos = button.textContent.trim();
            var occupe = button.dataset.busy || 'Traitement en cours…';

            function start() {
                var box = document.getElementById('console');
                if (box) box.innerHTML = '';
                suivi.demarrer(button.dataset.title || 'Traitement en cours', 0);

                // Les autres déclencheurs sont neutralisés le temps du traitement.
                Array.prototype.forEach.call(document.querySelectorAll('[data-analyze]'), function (other) {
                    other.disabled = true;
                });
                button.textContent = occupe;

                // Certains déclencheurs portent un champ de saisie : sa valeur
                // rejoint l'URL au moment du clic, pas à celui du rendu.
                var url = button.dataset.analyze;
                var source = button.dataset.candidatsSource
                    ? document.getElementById(button.dataset.candidatsSource)
                    : null;
                if (source && source.value.trim() !== '') {
                    url += (url.indexOf('?') === -1 ? '?' : '&')
                        + 'candidats=' + encodeURIComponent(source.value.trim());
                }

                runStep(url, box, {})
                    .then(function (data) {
                        suivi.terminer(true);
                        logLine(box, resume(data), 'done');
                        // Délai volontaire : le temps de lire la conclusion, qui
                        // reste ensuite consultable dans « dernier traitement ».
                        setTimeout(function () { window.location.reload(); }, 2200);
                    })
                    .catch(function () {
                        suivi.terminer(false);
                        Array.prototype.forEach.call(document.querySelectorAll('[data-analyze]'), function (other) {
                            other.disabled = false;
                        });
                        button.textContent = repos;
                    });
            }

            button.addEventListener('click', start);
            if (button.dataset.autorun === '1') start();
        });
    }

    /** Ligne de conclusion, selon ce que l'étape a renvoyé. */
    function resume(data) {
        if (data && typeof data.score !== 'undefined') {
            return 'Analyse terminée — score ' + data.score + '/100 (' + data.level + ')';
        }
        if (data && typeof data.pages !== 'undefined' && typeof data.services !== 'undefined') {
            return 'Lecture terminée — ' + data.pages + ' page(s), ' + data.services + ' prestation(s) relevée(s)';
        }
        if (data && data.page && typeof data.services === 'undefined') {
            return 'Comparaison terminée — résultats ci-dessous';
        }
        return 'Terminé';
    }

    /** Génération et retouche des maquettes, étape par étape. */
    function bindGenerate() {
        var forms = document.querySelectorAll('[data-generate]');
        if (!forms.length) return;

        Array.prototype.forEach.call(forms, function (form) {
            form.addEventListener('submit', function (event) {
                event.preventDefault();

                var base = form.dataset.generate;
                var mode = form.dataset.mode || 'new';
                var instruction = (form.querySelector('[name=instruction]') || {}).value || '';
                var checked = form.querySelectorAll('[name="pages[]"]:checked');
                var pages = checked.length
                    ? Array.prototype.map.call(checked, function (input) { return input.value; })
                    : (form.dataset.pages || '').split(',').filter(Boolean);

                var box = document.getElementById('console');
                var submit = form.querySelector('[type=submit]');

                if (box) box.innerHTML = '';
                // Brief + une étape par page : la progression est mesurable.
                suivi.demarrer(mode === 'revise' ? 'Retouche de la maquette' : 'Génération de la maquette', pages.length + 1);
                if (submit) { submit.disabled = true; submit.dataset.label = submit.textContent; submit.textContent = 'Génération en cours…'; }

                var query = function (extra) {
                    var params = new URLSearchParams(extra);
                    return base + '&' + params.toString();
                };

                runStep(query({ step: 'brief', mode: mode, instruction: instruction }), box, {})
                    .then(function (data) {
                        suivi.avancer();
                        var version = data.version;
                        // Les pages s'enchaînent séquentiellement : une requête
                        // par page reste sous les limites d'exécution des
                        // hébergements mutualisés.
                        return pages.reduce(function (chain, page) {
                            return chain.then(function () {
                                return runStep(query({
                                    step: page,
                                    mode: mode,
                                    version: version,
                                    instruction: instruction
                                }), box, {}).then(function (r) { suivi.avancer(); return r; });
                            });
                        }, Promise.resolve());
                    })
                    .then(function () {
                        suivi.terminer(true);
                        logLine(box, 'Maquette prête.', 'done');
                        setTimeout(function () { window.location.reload(); }, 2200);
                    })
                    .catch(function () {
                        suivi.terminer(false);
                        if (submit) { submit.disabled = false; submit.textContent = submit.dataset.label || 'Relancer'; }
                    });
            });
        });
    }

    /** Bascule de largeur pour prévisualiser la maquette en mobile ou tablette. */
    function bindDevices() {
        var bar = document.querySelector('[data-devices]');
        if (!bar) return;
        var frame = document.getElementById(bar.dataset.devices);
        if (!frame) return;

        bar.addEventListener('click', function (event) {
            var button = event.target.closest('button[data-width]');
            if (!button) return;
            Array.prototype.forEach.call(bar.querySelectorAll('button'), function (item) {
                item.classList.toggle('active', item === button);
            });
            frame.style.maxWidth = button.dataset.width;
            frame.style.margin = button.dataset.width === '100%' ? '0' : '0 auto';
        });
    }

    /** Insère une variable de modèle à la position du curseur. */
    function bindVariables() {
        document.addEventListener('click', function (event) {
            var chip = event.target.closest('[data-insert]');
            if (!chip) return;
            var target = document.querySelector(chip.dataset.target || 'textarea[name=body]');
            if (!target) return;

            var value = chip.dataset.insert;
            var start = target.selectionStart || 0;
            var end = target.selectionEnd || 0;
            target.value = target.value.slice(0, start) + value + target.value.slice(end);
            target.focus();
            target.selectionStart = target.selectionEnd = start + value.length;
        });
    }

    /** Confirmation avant les actions destructrices. */
    function bindConfirm() {
        document.addEventListener('submit', function (event) {
            var form = event.target;
            if (form.dataset.confirm && !window.confirm(form.dataset.confirm)) {
                event.preventDefault();
            }
        });
    }

    /** Copie dans le presse-papiers (lien de maquette, URL de cron…). */
    function bindCopy() {
        document.addEventListener('click', function (event) {
            var button = event.target.closest('[data-copy]');
            if (!button) return;
            event.preventDefault();

            var text = button.dataset.copy;
            var restore = button.textContent;
            var done = function () {
                button.textContent = 'Copié';
                setTimeout(function () { button.textContent = restore; }, 1600);
            };

            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text).then(done);
            } else {
                var helper = document.createElement('textarea');
                helper.value = text;
                helper.style.position = 'fixed';
                helper.style.opacity = '0';
                document.body.appendChild(helper);
                helper.select();
                try { document.execCommand('copy'); done(); } catch (error) { /* sans effet */ }
                document.body.removeChild(helper);
            }
        });
    }

    /**
     * Les deux façons de saisir une couleur restent d'accord entre elles : le
     * sélecteur pour choisir à l'œil, le champ texte pour coller un code de
     * charte. Seul le sélecteur est envoyé — le champ texte n'a pas de name.
     */
    function bindCouleurs() {
        var champs = document.querySelectorAll('[data-couleur]');
        Array.prototype.forEach.call(champs, function (pastille) {
            var cle = pastille.getAttribute('data-couleur');
            var texte = document.querySelector('[data-miroir="' + cle + '"]');
            if (!texte) { return; }

            pastille.addEventListener('input', function () {
                texte.value = pastille.value;
            });
            texte.addEventListener('input', function () {
                var valeur = texte.value.trim();
                if (valeur.charAt(0) !== '#') { valeur = '#' + valeur; }
                // La forme courte est acceptée : on la développe pour le sélecteur,
                // qui n'accepte que six caractères.
                if (/^#[0-9a-f]{3}$/i.test(valeur)) {
                    valeur = '#' + valeur[1] + valeur[1] + valeur[2] + valeur[2] + valeur[3] + valeur[3];
                }
                if (/^#[0-9a-f]{6}$/i.test(valeur)) {
                    pastille.value = valeur.toLowerCase();
                }
            });
            texte.addEventListener('blur', function () {
                texte.value = pastille.value;
            });
        });
    }

    /**
     * Éditeur de maquette.
     *
     * L'aperçu est servi depuis notre domaine : le script peut donc écrire
     * directement dans son document. Chaque frappe se voit immédiatement, sans
     * aller-retour avec le serveur — c'est ce qui rend la retouche utilisable.
     * Rien n'est enregistré tant qu'on ne le demande pas.
     */
    function bindEditeur() {
        var form = document.querySelector('[data-editeur]');
        var cadre = document.querySelector('[data-apercu]');
        if (!form || !cadre) { return; }

        function doc() {
            try { return cadre.contentDocument; } catch (e) { return null; }
        }

        /* Le chemin est une suite d'index d'éléments depuis <body>, exactement
           comme côté serveur : les nœuds de texte ne comptent pas. */
        function noeud(chemin) {
            var d = doc();
            if (!d || !d.body) { return null; }
            var courant = d.body;
            var parts = chemin.split('/');
            for (var i = 0; i < parts.length; i++) {
                var enfants = [];
                for (var j = 0; j < courant.children.length; j++) { enfants.push(courant.children[j]); }
                courant = enfants[parseInt(parts[i], 10)];
                if (!courant) { return null; }
            }
            return courant;
        }

        /* Le fichier enregistré porte « assets/photo.jpg » ; la page servie, elle,
           reçoit ce chemin réécrit vers une adresse de notre domaine. Poser le
           chemin brut dans l'aperçu donnerait une image cassée. */
        var motif = form.getAttribute('data-actifs') || '';
        function servie(valeur) {
            var m = /^\.?\/?assets\/([A-Za-z0-9._-]+)$/.exec(valeur.trim());
            return (m && motif) ? motif.replace('{f}', encodeURIComponent(m[1])) : valeur;
        }

        function appliquer(champ) {
            var cible = noeud(champ.getAttribute('data-champ'));
            if (!cible) { return; }
            var type = champ.getAttribute('data-type');
            if (type === 'texte') { cible.textContent = champ.value; }
            else if (type === 'lien') { cible.setAttribute('href', champ.value); }
            else if (type === 'image') { cible.setAttribute('src', servie(champ.value)); }
            else if (type === 'alt') { cible.setAttribute('alt', champ.value); }
        }

        form.addEventListener('input', function (event) {
            var champ = event.target;
            if (champ && champ.hasAttribute && champ.hasAttribute('data-champ')) { appliquer(champ); }
        });

        /* Une couleur de marque commande cinq jetons, pas un seul : les aplats,
           le petit texte et les accents en sont dérivés par un calcul de
           contraste. Ce calcul reste au serveur — le refaire en JavaScript
           donnerait deux vérités, dont une fausse le jour où l'autre change. */
        var attente = null;
        function rafraichirCharte() {
            var d = doc();
            if (!d || !d.documentElement) { return; }
            var params = new URLSearchParams({ r: 'palette_derive' });
            Array.prototype.forEach.call(form.querySelectorAll('[data-charte]'), function (p) {
                params.set(p.getAttribute('data-charte'), p.value);
            });
            fetch('index.php?' + params.toString(), { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data.ok) { return; }
                    var cible = doc();
                    if (!cible || !cible.documentElement) { return; }
                    Object.keys(data.jetons).forEach(function (jeton) {
                        cible.documentElement.style.setProperty(jeton, data.jetons[jeton]);
                    });
                })
                .catch(function () { /* l'aperçu garde ses couleurs, sans bruit */ });
        }
        function poserCouleur() {
            window.clearTimeout(attente);
            attente = window.setTimeout(rafraichirCharte, 180);
        }

        Array.prototype.forEach.call(form.querySelectorAll('[data-charte]'), function (pastille) {
            var cle = pastille.getAttribute('data-charte');
            var texte = form.querySelector('[data-charte-code="' + cle + '"]');
            pastille.addEventListener('input', function () {
                if (texte) { texte.value = pastille.value; }
                poserCouleur();
            });
            if (texte) {
                texte.addEventListener('input', function () {
                    var v = texte.value.trim();
                    if (v.charAt(0) !== '#') { v = '#' + v; }
                    if (/^#[0-9a-f]{3}$/i.test(v)) {
                        v = '#' + v[1] + v[1] + v[2] + v[2] + v[3] + v[3];
                    }
                    if (/^#[0-9a-f]{6}$/i.test(v)) {
                        pastille.value = v.toLowerCase();
                        poserCouleur();
                    }
                });
            }
        });

        /* Dépôt d'une image : le fichier part seul, et seul le champ concerné
           est mis à jour. Recharger la page perdrait les saisies en cours. */
        Array.prototype.forEach.call(form.querySelectorAll('[data-depot]'), function (entree) {
            entree.addEventListener('change', function () {
                var fichier = entree.files && entree.files[0];
                if (!fichier) { return; }
                var champ = document.getElementById(entree.getAttribute('data-depot'));
                var jeton = form.querySelector('input[name="_csrf"]');
                var corps = new FormData();
                corps.append('_csrf', jeton ? jeton.value : '');
                corps.append('id', form.getAttribute('data-id'));
                corps.append('media', fichier);

                entree.disabled = true;
                fetch(form.getAttribute('data-media'), { method: 'POST', body: corps, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (!data.ok) { window.alert(data.error || 'Dépôt impossible.'); return; }
                        if (champ) {
                            champ.value = data.src;
                            appliquer(champ);   // passe par servie(), donc par l'adresse réécrite
                            var bloc = champ.closest('.champ');
                            if (bloc) { bloc.classList.remove('champ--a-pourvoir'); }
                        }
                    })
                    .catch(function () { window.alert('Dépôt impossible : le serveur n\'a pas répondu.'); })
                    .finally(function () { entree.disabled = false; entree.value = ''; });
            });
        });

        /* Annuler : on recharge le cadre, ce qui remet la page telle qu'elle est
           enregistrée, puis les champs reprennent leur valeur d'origine. */
        var annuler = form.querySelector('[data-annuler]');
        if (annuler) {
            annuler.addEventListener('click', function () {
                window.setTimeout(function () { cadre.contentWindow.location.reload(); }, 0);
            });
        }

        /* Avec plus de cent champs, retrouver « le texte du troisième bloc »
           demande un filtre : il ouvre les sections qui contiennent le mot et
           masque les champs qui ne le portent pas. */
        var filtre = form.querySelector('[data-filtre]');
        if (filtre) {
            filtre.addEventListener('input', function () {
                var q = filtre.value.trim().toLowerCase();
                Array.prototype.forEach.call(form.querySelectorAll('details.bloc'), function (bloc) {
                    var trouves = 0;
                    Array.prototype.forEach.call(bloc.querySelectorAll('.champ'), function (champ) {
                        var visible = q === '' || champ.textContent.toLowerCase().indexOf(q) !== -1
                            || Array.prototype.some.call(champ.querySelectorAll('input, textarea'), function (e) {
                                return (e.value || '').toLowerCase().indexOf(q) !== -1;
                            });
                        champ.hidden = !visible;
                        if (visible) { trouves++; }
                    });
                    bloc.hidden = q !== '' && trouves === 0;
                    if (q !== '' && trouves > 0) { bloc.open = true; }
                });
            });
        }

        Array.prototype.forEach.call(form.querySelectorAll('[data-largeur]'), function (bouton) {
            bouton.addEventListener('click', function () {
                cadre.style.width = bouton.getAttribute('data-largeur');
                Array.prototype.forEach.call(form.querySelectorAll('[data-largeur]'), function (b) {
                    b.classList.toggle('primary', b === bouton);
                    b.classList.toggle('ghost', b !== bouton);
                });
            });
        });

        /* Au premier chargement du cadre, on repose les saisies déjà faites :
           un rechargement de l'aperçu ne doit pas effacer le travail en cours. */
        cadre.addEventListener('load', function () {
            Array.prototype.forEach.call(form.querySelectorAll('[data-champ]'), appliquer);
            rafraichirCharte();
        });
    }

    /* Les réglages du fournisseur non retenu sont masqués : afficher deux clés
       d'API côte à côte laisse toujours penser qu'il faut renseigner les deux. */
    function bindFournisseur() {
        var choix = document.querySelector('[data-fournisseur]');
        if (!choix) { return; }
        function afficher() {
            Array.prototype.forEach.call(document.querySelectorAll('[data-bloc-fournisseur]'), function (bloc) {
                bloc.hidden = bloc.getAttribute('data-bloc-fournisseur') !== choix.value;
            });
        }
        choix.addEventListener('change', afficher);
        afficher();
    }

    /* Les aperçus de comparaison sont rendus en 1280 px puis réduits pour tenir
       dans leur volet ; le facteur se mesure, une valeur figée laisserait un
       bord vide ou déborderait selon le nombre de candidats. */
    function bindComparaison() {
        var cadres = document.querySelectorAll('[data-apercu-compare]');
        if (!cadres.length) { return; }
        function ajuster() {
            Array.prototype.forEach.call(cadres, function (cadre) {
                var largeur = cadre.clientWidth;
                if (largeur > 0) { cadre.style.setProperty('--zoom', (largeur / 1280).toFixed(4)); }
            });
        }
        ajuster();
        window.addEventListener('resize', ajuster, { passive: true });
    }

    document.addEventListener('DOMContentLoaded', function () {
        bindComparaison();
        bindFournisseur();
        bindAnalyze();
        bindCouleurs();
        bindEditeur();
        bindGenerate();
        bindDevices();
        bindVariables();
        bindConfirm();
        bindCopy();
    });
})();
