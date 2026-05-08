// Minimal admin JS to avoid 404s and hard failures on hosts without full asset pipeline.
(function(){
  // Safe helpers
  function onReady(fn){ if(document.readyState!=='loading'){ fn(); } else { document.addEventListener('DOMContentLoaded', fn); } }

  onReady(function(){
    // Sidebar toggle fallback
    window.toggleSidebar = window.toggleSidebar || function(){
      try {
        var body = document.body;
        body.classList.toggle('sidebar-toggled');
        var sidebar = document.getElementById('accordionSidebar');
        if (sidebar) sidebar.classList.toggle('toggled');
      } catch(e) {}
    };

    // Dismiss alerts auto
    setTimeout(function(){
      document.querySelectorAll('.alert.auto-dismiss').forEach(function(el){ el.style.display='none'; });
    }, 4000);
  });
})();
