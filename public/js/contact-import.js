(() => {
 const progress=document.getElementById('import-progress');if(!progress)return;let busy=false;
 async function update(){if(busy||document.hidden)return;busy=true;const controller=new AbortController(),timer=setTimeout(()=>controller.abort(),5000);try{const response=await fetch(progress.dataset.url,{headers:{Accept:'application/json'},cache:'no-store',signal:controller.signal});if(response.redirected){location.assign(response.url);return;}if(!response.ok)return;const data=await response.json();if(data.status!==progress.dataset.status||String(data.imported)!==progress.dataset.imported)location.reload();}catch(_){}finally{clearTimeout(timer);busy=false;}}
 setInterval(update,5000);document.addEventListener('visibilitychange',()=>{if(!document.hidden)update();});
})();
