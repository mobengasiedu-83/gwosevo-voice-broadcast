(() => {
 const key='gwosevo-theme';
 function preferred(){try{const saved=localStorage.getItem(key);if(saved==='light'||saved==='dark')return saved;}catch(_){}return window.matchMedia?.('(prefers-color-scheme: light)').matches?'light':'dark';}
 function apply(mode){document.documentElement.dataset.theme=mode;document.documentElement.style.colorScheme=mode;document.querySelector('meta[name="theme-color"]')?.setAttribute('content',mode==='light'?'#f4f6fb':'#0d1119');document.querySelectorAll('[data-theme-toggle]').forEach(button=>{button.setAttribute('aria-pressed',String(mode==='light'));button.setAttribute('aria-label',mode==='light'?'Switch to dark mode':'Switch to light mode');button.textContent=mode==='light'?'☾ Dark':'☀ Light';});}
 apply(preferred());
 document.addEventListener('DOMContentLoaded',()=>{apply(preferred());document.querySelectorAll('[data-theme-toggle]').forEach(button=>{button.hidden=false;button.addEventListener('click',()=>{const mode=document.documentElement.dataset.theme==='dark'?'light':'dark';try{localStorage.setItem(key,mode);}catch(_){}apply(mode);});});});
 window.addEventListener('storage',event=>{if(event.key===key)apply(preferred());});
})();
