(() => {
    /* ------------------------------------------------------------
     *  Vaihda näkyvää välilehteä
     * ---------------------------------------------------------- */
    const switchTab = id => {
        document.querySelectorAll('.tapo-nav a').forEach(a =>
            a.classList.toggle('active', a.dataset.target === id));

        document.querySelectorAll('.tapo-pane').forEach(p =>
            p.classList.toggle('active', p.id === id));

        localStorage.setItem('tapoActiveTab', id);
    };

    /* ------------------------------------------------------------
     * 1) Navigointipalkin linkit
     * ---------------------------------------------------------- */
    document.addEventListener('click', e => {
        if (!e.target.matches('.tapo-nav a')) return;
        e.preventDefault();
        switchTab(e.target.dataset.target);
    });

    /* ------------------------------------------------------------
     * 2) “Tilaa uusi tästä” -linkki ilmoituksessa (class="tapo-open-emp")
     * ---------------------------------------------------------- */
    document.addEventListener('click', e => {
        if (!e.target.matches('.tapo-open-emp')) return;
        e.preventDefault();
        switchTab('tapo-emp');
    });

    /* ------------------------------------------------------------
     * 3) Kun DOM on valmis
     * ---------------------------------------------------------- */
    document.addEventListener('DOMContentLoaded', () => {
        // 3.1 – Jos osoitteessa on #tapo-emp → näytä suoraan se
        if (location.hash === '#tapo-emp') {
            switchTab('tapo-emp');
        }

        // 3.2 – Magic-linkki-viesti aktivoi automaattisesti Emp-välilehden
        const activeMagic = document.querySelector('#tapo-emp .magic-login-message');
        if (activeMagic) {
            switchTab('tapo-emp');

            // 3.3 – retry=1 tai virheellinen salasana → oikea tab
        } else if (location.search.includes('retry=1')) {
            switchTab('tapo-emp');

        } else if (location.search.includes('login=failed')) {
            switchTab('tapo-mgr');

            // 3.4 – Muuten viimeksi käytetty tai oletus (mgr)
        } else {
            switchTab(localStorage.getItem('tapoActiveTab') || 'tapo-mgr');
        }

        // 3.5 – Siivotaan query-parametrit pois URL-palkista
        if (location.search.includes('login=failed') || location.search.includes('retry=1')) {
            history.replaceState(null, '',
                location.origin + location.pathname + location.hash);
        }

        // 3.6 – Kuuntelija hash-muutoksille (#tapo-emp jne.)
        window.addEventListener('hashchange', () => {
            if (location.hash === '#tapo-emp') {
                switchTab('tapo-emp');
            }
        });

        /* --------------------------------------------------------
         * 4) Domain-tarkistus Työntekijä-lomakkeessa
         * ------------------------------------------------------ */
        const magicRoot = document.querySelector('#tapo-emp');
        if (!magicRoot) return;

        // Magic Login Pron peruslomake
        const form = magicRoot.querySelector('form');
        if (!form) return;

        const emailInput = form.querySelector('input[type="email"], input[name="email"], input[name="user_login"], input[name="log"]');
        if (!emailInput) return;

        // Luodaan pieni virhebox tarvittaessa
        let errorBox = magicRoot.querySelector('.tapo-domain-error');
        if (!errorBox) {
            errorBox = document.createElement('div');
            errorBox.className = 'tapo-domain-error';
            errorBox.style.cssText = 'display:none;margin-bottom:10px;padding:10px;border:1px solid #e2401c;background:#fef8f7;color:#e2401c;border-radius:4px;';
            emailInput.closest('p,div,form').prepend(errorBox);
        }

        const showError = (msg) => {
            errorBox.textContent = msg;
            errorBox.style.display = 'block';
        };

        // Hyväksytyt domainit
        const domainRE = /@tapojarvi\.(fi|com)$/i;

        emailInput.addEventListener('input', () => {
            errorBox.style.display = 'none';
        });

        // 4.2 – Hyväksy vain Tapojärvi-sähköpostit
        form.addEventListener('submit', e => {
            if (!domainRE.test(emailInput.value.trim())) {
                e.preventDefault();
                const msg = (window.TLT_i18n && TLT_i18n.domainError)
                    ? TLT_i18n.domainError
                    : 'Please use a @tapojarvi.fi or @tapojarvi.com address.';
                showError(msg);
            }
        });
    });
})();