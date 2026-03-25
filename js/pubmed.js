(function () {
    function updateStatus(status, message, isError) {
        status.textContent = message;
        status.style.color = isError ? '#b00020' : '#555';
    }

    function setButtonDisabled(button, disabled) {
        button.disabled = disabled;
    }

    async function runSync(url, status, button) {
        updateStatus(status, 'Running...', false);
        setButtonDisabled(button, true);

        try {
            var response = await fetch(url, {
                method: 'GET',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json'
                }
            });

            var payload = await response.json();
            if (!response.ok || payload.error) {
                throw new Error(payload.error || 'PubMed sync failed.');
            }

            updateStatus(status, 'Done. ' + payload.new_records + ' new records (' + payload.total_found + ' total found).', false);
        } catch (error) {
            updateStatus(status, 'Error: ' + error.message, true);
        } finally {
            setButtonDisabled(button, false);
        }
    }

    function getRunUrl() {
        return window.CorePubMatch && window.CorePubMatch.runUrl;
    }

    function attachProjectSetupHandler() {
        var button = document.getElementById('core-pubmatch-run');
        var status = document.getElementById('core-pubmatch-status');

        if (!button || !status || button.dataset.corePubMatchBound === '1') {
            return;
        }

        button.dataset.corePubMatchBound = '1';

        button.addEventListener('click', function () {
            var url = getRunUrl();
            if (!url) {
                updateStatus(status, 'Error: run URL not available.', true);
                return;
            }

            runSync(url, status, button);
        });
    }

    function findConfigDialog() {
        var titles = document.querySelectorAll('.ui-dialog-title');
        for (var i = 0; i < titles.length; i++) {
            if (titles[i].textContent.indexOf('Configure Module: CorePubMatch') !== -1) {
                return titles[i].closest('.ui-dialog');
            }
        }

        return null;
    }

    function attachConfigModalHandler() {
        var dialog = findConfigDialog();
        if (!dialog || dialog.querySelector('#core-pubmatch-em-controls')) {
            return;
        }

        var footer = dialog.querySelector('.ui-dialog-buttonpane');
        if (!footer) {
            return;
        }

        var container = document.createElement('div');
        container.id = 'core-pubmatch-em-controls';
        container.style.cssText = 'float:left;display:flex;align-items:center;gap:8px;padding:8px 0;';

        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn btn-primary';
        button.textContent = 'Run PubMed Sync';

        var status = document.createElement('span');
        status.id = 'core-pubmatch-em-status';
        status.style.color = '#555';
        status.textContent = 'Idle.';

        container.appendChild(button);
        container.appendChild(status);
        footer.prepend(container);

        if (!button) {
            return;
        }

        button.addEventListener('click', function () {
            var url = getRunUrl();
            if (!url) {
                updateStatus(status, 'Error: run URL not available.', true);
                return;
            }

            runSync(url, status, button);
        });
    }

    function attachAllHandlers() {
        attachProjectSetupHandler();
        attachConfigModalHandler();
    }

    function initialize() {
        attachAllHandlers();

        var observer = new MutationObserver(function () {
            attachAllHandlers();
        });

        observer.observe(document.body, {
            childList: true,
            subtree: true
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialize);
    } else {
        initialize();
    }
})();
