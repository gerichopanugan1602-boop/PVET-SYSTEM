/* Sidebar collapse (desktop). The saved state is restored by a small inline script in includes/sidebar.php. */
(function () {
    'use strict';

    var btn = document.getElementById('sidebarCollapse');
    if (!btn) return;

    function sync() {
        var collapsed = document.body.classList.contains('sidebar-collapsed');
        var label = collapsed ? 'Expand sidebar' : 'Collapse sidebar';
        btn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        btn.setAttribute('aria-label', label);
        btn.setAttribute('title', label);
    }

    btn.addEventListener('click', function () {
        var collapsed = document.body.classList.toggle('sidebar-collapsed');
        try { localStorage.setItem('sidebarCollapsed', collapsed ? '1' : '0'); } catch (e) {}
        sync();
    });

    sync();
})();