/* Keep the current section visible in the compact, horizontally scrolling navigation. */
(() => {
    const alignNavigation = () => {
        if (!window.matchMedia('(max-width: 720px)').matches) return;
        const nav = document.querySelector('.portal-sidebar nav');
        const active = nav?.querySelector('a[aria-current="page"]');
        if (!active) return;
        nav.scrollLeft = active.offsetLeft - nav.offsetLeft - (nav.clientWidth - active.offsetWidth) / 2;
    };
    alignNavigation();
    window.addEventListener('resize', alignNavigation, { passive: true });
})();
