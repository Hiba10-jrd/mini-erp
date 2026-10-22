import './bootstrap';

if (!document.documentElement.dataset.erpMotionNavigationBound) {
    document.documentElement.dataset.erpMotionNavigationBound = 'true';

    let lastMotionUrl = null;
    let motionRun = 0;
    const activeMotionTargets = new Set();

    function animatePageEntry() {
        const url = window.location.href;
        if (url === lastMotionUrl) return;
        lastMotionUrl = url;
        const run = ++motionRun;

        activeMotionTargets.forEach((element) => {
            element.classList.remove('erp-motion-page-reveal');
            element.style.removeProperty('--erp-motion-delay');
        });
        activeMotionTargets.clear();

        const targets = new Set(document.querySelectorAll('.erp-page-heading > div'));
        const authCard = document.querySelector('.erp-auth-card');
        if (authCard) targets.add(authCard);

        const main = document.getElementById('main-content');
        if (main) {
            main.querySelectorAll(':scope > *').forEach((element) => targets.add(element));

            const motionSelectors = [
                '.dashboard-executive > *',
                '.dashboard-kpis > *',
                '.dashboard-alert-group',
                '.dashboard-feed > li:not(.dashboard-empty)',
                '.erp-panel',
                '.erp-panel-header',
                '.erp-table-shell',
                '.erp-detail-panel',
                '.erp-entity-card',
                '.erp-kpi',
            ];
            main.querySelectorAll(motionSelectors.join(', ')).forEach((element) => {
                if (targets.size < 28) targets.add(element);
            });
        }

        if (targets.size === 0) return;

        const orderedTargets = [...targets].slice(0, 28);
        orderedTargets.forEach((element, index) => {
            element.classList.remove('erp-motion-page-reveal');
            element.style.setProperty('--erp-motion-delay', `${Math.min(index, 9) * 80}ms`);
            activeMotionTargets.add(element);
        });

        main.querySelectorAll('.dashboard-kpis > *').forEach((element, index) => {
            if (activeMotionTargets.has(element)) {
                element.style.setProperty('--erp-motion-delay', `${index * 100}ms`);
            }
        });

        requestAnimationFrame(() => {
            if (run !== motionRun) return;
            orderedTargets.forEach((element) => element.classList.add('erp-motion-page-reveal'));
        });
    }

    document.addEventListener('livewire:navigated', animatePageEntry);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', animatePageEntry, { once: true });
    } else {
        animatePageEntry();
    }
}
