/*
 * GateCHA for Shopware: small glue around the ALTCHA widget (loaded as a module, runs once per page).
 *
 * 1. Loads the GateCHA Human Interaction Signals collector (his.js) once, if a widget asks for it.
 *    his.js adds the hidden field "gatecha_his_signals" to protected forms on submit.
 * 2. Resets a solved widget after its form was sent. GateCHA accepts every solution only once,
 *    so after an AJAX submit (contact form, newsletter) that failed for another reason the
 *    customer would otherwise send an already used solution again.
 */

const HIS_ATTRIBUTE = 'data-gatecha-his';
const WIDGET_SELECTOR = 'altcha-widget';

function loadHisCollector() {
    if (window.gatechaHIS || document.querySelector('script[data-gatecha-his-collector]')) {
        return;
    }

    const widget = document.querySelector(`${WIDGET_SELECTOR}[${HIS_ATTRIBUTE}]`);
    const src = widget ? widget.getAttribute(HIS_ATTRIBUTE) : '';
    if (!src) {
        return;
    }

    const script = document.createElement('script');
    script.src = src;
    script.defer = true;
    script.setAttribute('data-gatecha-his-collector', '');
    document.head.appendChild(script);
}

function resetAfterSubmit(event) {
    const form = event.target;
    if (!(form instanceof HTMLFormElement)) {
        return;
    }

    // A submit stopped by client-side validation never reached the server: keep the solution.
    if (event.defaultPrevented && typeof form.checkValidity === 'function' && !form.checkValidity()) {
        return;
    }

    form.querySelectorAll(WIDGET_SELECTOR).forEach((widget) => {
        if (typeof widget.getState !== 'function' || widget.getState() !== 'verified') {
            return;
        }

        // Next task: AJAX handlers have already read the form data synchronously by then.
        window.setTimeout(() => {
            if (typeof widget.reset === 'function') {
                widget.reset();
            }
        }, 0);
    });
}

// Bubble phase on document: runs after the form's own submit handlers.
document.addEventListener('submit', resetAfterSubmit);

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', loadHisCollector, { once: true });
} else {
    loadHisCollector();
}
