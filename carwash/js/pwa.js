// Register Service Worker (only on secure contexts and when supported)
(function(){
  try {
    var secure = (window.isSecureContext === true) || (location.protocol === 'https:') || location.hostname === 'localhost';
    if ('serviceWorker' in navigator && secure) {
      window.addEventListener('load', function(){
        navigator.serviceWorker.register('sw.js')
          .then(function(reg){ console.log('SW registered:', reg.scope); })
          .catch(function(err){ console.log('SW registration failed:', err); });
      });
    }
  } catch(e) { /* no-op */ }
})();

// Handle install prompt
let deferredPrompt;
window.addEventListener('beforeinstallprompt', (e) => {
    // Prevent Chrome 67 and earlier from automatically showing the prompt
    e.preventDefault();
    // Stash the event so it can be triggered later
    deferredPrompt = e;
    // Show install button if you have one
    // document.getElementById('installButton').style.display = 'block';
});

// Handle install button click
function installPWA() {
    if (deferredPrompt) {
        // Show the install prompt
        deferredPrompt.prompt();
        // Wait for the user to respond to the prompt
        deferredPrompt.userChoice.then((choiceResult) => {
            if (choiceResult.outcome === 'accepted') {
                console.log('User accepted the install prompt');
            } else {
                console.log('User dismissed the install prompt');
            }
            deferredPrompt = null;
        });
    }
}

// Detect if the app is running in standalone mode
window.addEventListener('load', () => {
    let isInStandaloneMode = window.matchMedia('(display-mode: standalone)').matches || 
                           (window.navigator.standalone) || 
                           document.referrer.includes('android-app://');
    
    if (isInStandaloneMode) {
        console.log('Running in standalone mode');
        // You can add standalone mode specific code here
    }
});
