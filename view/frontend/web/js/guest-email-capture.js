/** Serialize guest email saves and withdrawals; acknowledge saves and retry transient failures. */
define([], function () {
    'use strict';

    var EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;

    function valid(email) {
        return email.length <= 255 && EMAIL.test(email);
    }

    return function (transport) {
        var wanted = null, saved = null, pending = null, clears = [], revision = 0,
            timer = null, retryKey = null, attempts = 0, blocked = null, destroyed = false;

        function key(request) {
            return JSON.stringify([request.cartId, request.email, request.revision]);
        }

        function clearCart(cartId) {
            if (cartId && clears.indexOf(cartId) === -1) {
                clears.push(cartId);
            }
        }

        function next() {
            if (clears.length) {
                return {cartId: clears[0], email: '', revision: 0};
            }
            if (!wanted || !wanted.cartId || !wanted.allowed || !valid(wanted.email)) {
                return null;
            }
            if (saved && key(saved) === key(wanted)) {
                return null;
            }
            return wanted;
        }

        function pump() {
            var request = next(), requestKey;
            if (destroyed || pending || timer !== null || !request) {
                return;
            }
            requestKey = key(request);
            if (blocked === requestKey) {
                return;
            }
            if (retryKey !== requestKey) {
                retryKey = requestKey;
                attempts = 0;
            }
            pending = request;

            function complete(stored, error) {
                var latest, transient;
                if (destroyed) {
                    return;
                }
                pending = null;
                if (!error && (request.email === '' || stored === true)) {
                    if (request.email === '') {
                        clears.shift();
                        if (saved && saved.cartId === request.cartId) {
                            saved = null;
                        }
                    } else {
                        saved = request;
                    }
                    attempts = 0;
                    blocked = null;
                    pump();
                    return;
                }
                latest = next();
                if (!latest || key(latest) !== requestKey) {
                    pump(); // A newer email or withdrawal takes priority over retrying an old save.
                    return;
                }
                transient = !error || !error.status || error.status === 408 || error.status === 429 || error.status >= 500;
                if (!transient || ++attempts >= 5) {
                    blocked = requestKey;
                    return;
                }
                timer = setTimeout(function () {
                    timer = null;
                    pump();
                }, Math.min(1000 * Math.pow(2, attempts - 1), 30000));
            }

            try {
                transport(request.cartId, request.email)
                    .done(function (stored) { complete(stored, null); })
                    .fail(function (error) { complete(false, error || {}); });
            } catch (error) {
                complete(false, {});
            }
        }

        function wake() {
            clearTimeout(timer);
            timer = null;
            attempts = 0;
            blocked = null;
            pump();
        }

        return {
            update: function (state, choiceChanged) {
                var email = (state.email || '').trim(), allowed = state.allowed && !state.loggedIn,
                    changed = !wanted || wanted.cartId !== state.cartId || wanted.email !== email || wanted.allowed !== allowed;
                if (destroyed) {
                    return;
                }
                if (choiceChanged) {
                    revision++;
                }
                wanted = {cartId: state.cartId, email: email, allowed: allowed, revision: revision};
                if (!allowed || !valid(email)) {
                    if (saved) {
                        clearCart(saved.cartId);
                    }
                    if (pending && pending.email !== '') {
                        clearCart(pending.cartId);
                    }
                }
                if (changed || choiceChanged) {
                    wake();
                } else {
                    pump();
                }
            },
            online: wake,
            destroy: function () {
                destroyed = true;
                clearTimeout(timer);
            }
        };
    };
});
