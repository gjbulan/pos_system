function money(value, symbol = '₱') {
  return symbol + Number(value || 0).toLocaleString(undefined, {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2
  });
}

document.addEventListener('DOMContentLoaded', function () {
  const body = document.body;
  const sidebarToggle = document.getElementById('sidebarToggle');
  const sidebarClose = document.getElementById('sidebarClose');
  const mobileOverlay = document.getElementById('mobileOverlay');

  const openSidebar = () => body.classList.add('sidebar-open');
  const closeSidebar = () => body.classList.remove('sidebar-open');

  if (sidebarToggle) sidebarToggle.addEventListener('click', openSidebar);
  if (sidebarClose) sidebarClose.addEventListener('click', closeSidebar);
  if (mobileOverlay) mobileOverlay.addEventListener('click', closeSidebar);

  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') closeSidebar();
  });

  const currentPath = window.location.pathname;
  document.querySelectorAll('.sidebar .nav-link, .mobile-bottom-nav a').forEach(function (link) {
    const href = link.getAttribute('href');
    if (href && currentPath === href) {
      link.classList.add('active');
      link.setAttribute('aria-current', 'page');
    }
  });

  document.querySelectorAll('table').forEach(function (table) {
    if (!table.closest('.table-responsive')) {
      const wrapper = document.createElement('div');
      wrapper.className = 'table-responsive';
      table.parentNode.insertBefore(wrapper, table);
      wrapper.appendChild(table);
    }
  });
});
