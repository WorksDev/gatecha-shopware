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

    if (event.defaultPrevented && typeof form.checkValidity === 'function' && !form.checkValidity()) {
        return;
    }

    form.querySelectorAll(WIDGET_SELECTOR).forEach((widget) => {
        if (typeof widget.getState !== 'function' || widget.getState() !== 'verified') {
            return;
        }

        window.setTimeout(() => {
            if (typeof widget.reset === 'function') {
                widget.reset();
            }
        }, 0);
    });
}

document.addEventListener('submit', resetAfterSubmit);

function watchForWidgets() {
    loadHisCollector();
    if (window.gatechaHIS || document.querySelector('script[data-gatecha-his-collector]')) {
        return;
    }

    const observer = new MutationObserver(() => {
        if (!document.querySelector(`${WIDGET_SELECTOR}[${HIS_ATTRIBUTE}]`)) {
            return;
        }

        loadHisCollector();
        observer.disconnect();
    });

    observer.observe(document.documentElement, { childList: true, subtree: true });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', watchForWidgets, { once: true });
} else {
    watchForWidgets();
}
