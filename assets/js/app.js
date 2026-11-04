document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('[data-expires]').forEach(el => {
    const expires = new Date(el.dataset.expires).getTime();
    const update = () => {
      const diff = expires - Date.now();
      if (diff <= 0) { el.textContent = 'Expired — refresh page'; return; }
      const h = Math.floor(diff / 3600000);
      const m = Math.floor((diff % 3600000) / 60000);
      const s = Math.floor((diff % 60000) / 1000);
      el.textContent = `${h}h ${m}m ${s}s remaining`;
    };
    update(); setInterval(update, 1000);
  });
});
