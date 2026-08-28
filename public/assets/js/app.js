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

                runStep(button.dataset.analyze, box, {})
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
        if (data && typeof data.pages !== 'undefined') {
            return 'Lecture terminée — ' + data.pages + ' page(s), ' + data.services + ' prestation(s) relevée(s)';
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

    document.addEventListener('DOMContentLoaded', function () {
        bindAnalyze();
        bindCouleurs();
        bindGenerate();
        bindDevices();
        bindVariables();
        bindConfirm();
        bindCopy();
    });
})();
