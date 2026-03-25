(function () {
    function updateStatus(message, isError) {
        var status = document.getElementById('core-pubmatch-status');
        if (!status) {
            return;
        }

        status.textContent = message;
        status.style.color = isError ? '#b00020' : '#555';
    }

    function setButtonDisabled(disabled) {
        var button = document.getElementById('core-pubmatch-run');
        if (!button) {
            return;
        }

        button.disabled = disabled;
    }

    async function runSync(url) {
        updateStatus('Running...', false);
        setButtonDisabled(true);

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

            updateStatus('Done. ' + payload.new_records + ' new records (' + payload.total_found + ' total found).', false);
        } catch (error) {
            updateStatus('Error: ' + error.message, true);
        } finally {
            setButtonDisabled(false);
        }
    }

    function attachHandler() {
        var button = document.getElementById('core-pubmatch-run');
        if (!button) {
            return;
        }

        button.addEventListener('click', function () {
            var url = window.CorePubMatch && window.CorePubMatch.runUrl;
            if (!url) {
                updateStatus('Error: run URL not available.', true);
                return;
            }

            runSync(url);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', attachHandler);
    } else {
        attachHandler();
    }
})();
