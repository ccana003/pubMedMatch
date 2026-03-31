(function () {
    function hideNativeSurvey() {
        var form = document.getElementById('form');
        if (form) {
            form.style.display = 'none';
        }
    }

    function injectStyles() {
        var style = document.createElement('style');
        style.textContent = '#core-pubmatch-survey-root .cpm-wrap{max-width:980px;margin:12px auto;padding:12px;background:#fff;border:1px solid #ddd;border-radius:6px}#core-pubmatch-survey-root .cpm-card{border:1px solid #d6d6d6;background:#fafafa;border-radius:6px;padding:12px;margin:10px 0}#core-pubmatch-survey-root .cpm-title{font-size:18px;font-weight:600;margin:0 0 6px}#core-pubmatch-survey-root .cpm-sub{font-size:13px;color:#555}#core-pubmatch-survey-root .cpm-empty{padding:10px;border:1px solid #ffd591;background:#fff7e6;border-radius:6px}';
        document.head.appendChild(style);
    }

    function render(root, payload) {
        var wrap = document.createElement('div');
        wrap.className = 'cpm-wrap';

        var heading = document.createElement('h3');
        heading.textContent = 'Matched Publications';
        wrap.appendChild(heading);

        var sub = document.createElement('div');
        sub.className = 'cpm-sub';
        sub.textContent = 'Identifier: ' + (payload.identifier || '');
        wrap.appendChild(sub);

        var matches = Array.isArray(payload.matches) ? payload.matches : [];
        if (matches.length === 0) {
            var empty = document.createElement('div');
            empty.className = 'cpm-empty';
            empty.textContent = 'No matches found for this identifier.';
            wrap.appendChild(empty);
            root.appendChild(wrap);
            return;
        }

        matches.forEach(function (m, idx) {
            var card = document.createElement('section');
            card.className = 'cpm-card';
            card.innerHTML =
                '<div class="cpm-sub">Publication ' + (idx + 1) + '</div>' +
                '<div class="cpm-title"></div>' +
                '<div class="cpm-sub"></div>' +
                '<div class="cpm-sub"></div>';

            card.querySelector('.cpm-title').textContent = m.title || '(Untitled publication)';
            card.querySelectorAll('.cpm-sub')[1].textContent = m.authors || '';

            var line = '';
            if (m.journal) line += m.journal;
            if (m.pub_year) line += (line ? ' (' + m.pub_year + ')' : m.pub_year);
            if (m.pmid) line += (line ? ' • PMID: ' + m.pmid : 'PMID: ' + m.pmid);
            card.querySelectorAll('.cpm-sub')[2].textContent = line;

            wrap.appendChild(card);
        });

        root.appendChild(wrap);
    }

    async function init() {
        if (!window.CorePubMatchSurvey || !window.CorePubMatchSurvey.apiBase) {
            return;
        }

        hideNativeSurvey();
        injectStyles();

        var root = document.getElementById('core-pubmatch-survey-root');
        if (!root) {
            return;
        }

        try {
            var primary = new URL(window.CorePubMatchSurvey.apiBase, window.location.origin);
            primary.searchParams.set('cpm_action', 'survey_matches');
            primary.searchParams.set('pid', window.CorePubMatchSurvey.pid || '');
            primary.searchParams.set('core_pubmatch_identifier', window.CorePubMatchSurvey.identifier || '');
            primary.searchParams.set('s', window.CorePubMatchSurvey.surveyHash || '');
            primary.searchParams.set('cpm_sig', window.CorePubMatchSurvey.sig || '');

            var secondary = new URL(primary.toString());
            secondary.searchParams.delete('cpm_action');
            secondary.searchParams.set('page', 'pages/survey_matches.php');

            var urls = [primary.toString(), secondary.toString()];
            var payload = null;
            var lastError = null;

            for (var i = 0; i < urls.length; i++) {
                try {
                    var response = await fetch(urls[i], {
                        method: 'GET',
                        credentials: 'same-origin',
                        headers: { 'Accept': 'application/json' }
                    });
                    var rawText = await response.text();
                    try {
                        payload = JSON.parse(rawText);
                    } catch (parseError) {
                        throw new Error('Non-JSON response from survey endpoint: ' + rawText.slice(0, 200));
                    }

                    if (!response.ok || payload.error) {
                        throw new Error(payload.error || 'Failed to load matches.');
                    }

                    break;
                } catch (e) {
                    lastError = e;
                }
            }

            if (!payload) {
                throw lastError || new Error('Unable to load matches.');
            }

            render(root, payload);
        } catch (error) {
            root.innerHTML = '<div style="color:#b00020;">' + error.message + '</div>';
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
