(()=>{
  const endpoint='/analytics.php';
  const cookieName='fd_analytics';
  const hex=()=>{
    if(window.crypto&&crypto.getRandomValues){
      const bytes=new Uint8Array(16);crypto.getRandomValues(bytes);
      return Array.from(bytes,b=>b.toString(16).padStart(2,'0')).join('');
    }
    return '';
  };
  const cookie=()=>{
    const found=document.cookie.split('; ').find(v=>v.startsWith(cookieName+'='));
    const existing=found?found.slice(cookieName.length+1):'';
    if(/^[a-f0-9]{32}$/.test(existing))return existing;
    const id=hex();if(!id)return '';
    const secure=location.protocol==='https:'?'; Secure':'';
    document.cookie=cookieName+'='+id+'; Path=/; Max-Age=2592000; SameSite=Lax'+secure;
    return id;
  };
  const event=()=>{
    const p=location.pathname;
    if(p==='/builder.php'||p==='/builder-review.php')return 'builder_view';
    if(p==='/cart.php')return 'cart_view';
    if(p==='/checkout.php'||p==='/checkout-review.php')return 'checkout_view';
    return 'page_view';
  };
  const params=new URLSearchParams(location.search);
  let ref='';
  try{if(document.referrer)ref=new URL(document.referrer).hostname;}catch(_){}
  const payload={
    visitor_id:cookie(),event:event(),path:location.pathname,referrer_host:ref,
    utm_source:params.get('utm_source')||'',utm_medium:params.get('utm_medium')||'',
    utm_campaign:params.get('utm_campaign')||'',utm_content:params.get('utm_content')||'',
    utm_term:params.get('utm_term')||''
  };
  if(!payload.visitor_id)return;
  fetch(endpoint,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload),keepalive:true,credentials:'same-origin'}).catch(()=>{});
})();