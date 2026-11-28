'use strict';

(() => {
    const $ = id => document.getElementById(id);
    const adapter = (() => {
        let csrf = '';

        async function request(url, method = 'GET') {
            const response = await fetch(url, {
                method,
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                },
                ...(method === 'POST' ? { body: '{}' } : {}),
            });
            const data = await response.json();
            if (data.csrf) csrf = data.csrf;
            if (!response.ok) {
                throw new Error(data.message || 'Unable to load raffle information.');
            }

            return data;
        }

        async function getStatus() {
            return request('/portal/promotions/korea-raffle');
        }

        async function claim() {
            return request('/portal/promotions/korea-raffle/claim', 'POST');
        }

        return { getStatus, claim };
    })();

    let state = null;
    let busy = false;

    function render() {
        if (!state) return;

        const tickets = state.tickets || [];
        $('ticket-count').textContent = `${tickets.length} ${tickets.length === 1 ? 'ticket' : 'tickets'}`;
        $('empty-tickets').hidden = tickets.length > 0;
        $('ticket-list').hidden = tickets.length === 0;
        $('ticket-list').replaceChildren();
        [...tickets].reverse().forEach(ticket => {
            const item = document.createElement('li');
            const date = document.createElement('span');
            const code = document.createElement('code');
            date.textContent = new Intl.DateTimeFormat('en-PH', {
                day: 'numeric',
                month: 'short',
                year: 'numeric',
                timeZone: 'Asia/Manila',
            }).format(new Date(`${ticket.date}T00:00:00+08:00`));
            code.textContent = ticket.id;
            item.append(date, code);
            $('ticket-list').append(item);
        });

        const button = $('claim-button');
        const loginLink = $('raffle-login-link');
        loginLink.hidden = state.authenticated !== false;
        let label = 'Claim today’s ticket';
        let caption = 'One ticket per day · Philippine time';

        if (state.authenticated === false) {
            label = 'Sign in to claim';
            caption = state.message;
        } else if (state.phase === 'before') {
            label = 'Claims open November 27';
            caption = 'Your daily claims begin on November 27, 2026.';
        } else if (state.phase === 'closed') {
            label = 'Ticket claiming has ended';
            caption = 'The raffle promotion has ended.';
        } else if (!state.eligible) {
            label = 'Investment verification required';
            caption = state.message || 'Verify your investment to enable daily ticket claims.';
        } else if (state.claimedToday) {
            label = '✓ Today’s ticket claimed';
            caption = 'Come back on the next Philippine calendar day.';
        }

        button.textContent = busy ? 'Claiming your ticket…' : label;
        button.disabled = busy || state.authenticated === false || state.phase !== 'open'
            || !state.eligible || state.claimedToday;
        $('claim-caption').textContent = caption;
        $('period-status').textContent = state.phase === 'before'
            ? 'Coming soon · Philippine time'
            : state.phase === 'closed' ? 'Promo ended · Philippine time' : 'Promo is open · Philippine time';
        $('preview-badge').hidden = true;
        $('preview-note').textContent = 'Your tickets are recorded in your member account.';
    }

    async function refresh() {
        try {
            state = await adapter.getStatus();
            render();
        } catch (error) {
            console.error('Korea raffle status could not be loaded.', error);
            $('claim-button').disabled = true;
            $('claim-button').textContent = 'Unable to check availability';
            $('claim-caption').textContent = 'Please refresh the page to try again.';
            $('raffle-login-link').hidden = true;
        }
    }

    async function claim() {
        if (busy) return;
        busy = true;
        render();

        try {
            state = await adapter.getStatus();
            if (!state.authenticated || !state.eligible || state.phase !== 'open' || state.claimedToday) {
                render();
                return;
            }

            const result = await adapter.claim();
            await refresh();
            $('success-ticket').textContent = result.ticket.id;
            $('success-message').textContent = 'Your raffle ticket has been recorded in your member account.';
            $('success-dialog').showModal();
        } catch (error) {
            $('toast').textContent = error.message || 'Could not complete your claim. Please try again.';
            clearTimeout($('toast').timer);
            $('toast').timer = setTimeout(() => { $('toast').textContent = ''; }, 4500);
            await refresh();
        } finally {
            busy = false;
            render();
        }
    }

    $('claim-button').addEventListener('click', claim);
    $('close-dialog').addEventListener('click', () => $('success-dialog').close());
    $('raffle-login-link').href = '/#/login?return=korea-raffle';
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) refresh();
    });
    setInterval(() => {
        if (!busy) refresh();
    }, 60000);

    window.MomsTouchRaffle = { refresh };
    refresh();
})();
