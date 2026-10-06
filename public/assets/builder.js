(() => {
  const form = document.querySelector('#pack-form');
  if (!form) return;
  const max = Number(form.dataset.size);
  const base = Number(form.dataset.basePrice);
  const cards = [...form.querySelectorAll('.builder-card')];
  const count = document.querySelector('#count');
  const total = document.querySelector('#total');
  const status = document.querySelector('#builder-status');
  const submit = document.querySelector('#continue');

  const formatMoney = cents => new Intl.NumberFormat('en-US',{style:'currency',currency:'USD'}).format(cents/100);
  const selected = () => cards.reduce((n,card) => n + Number(card.querySelector('.qty').value || 0), 0);

  function update() {
    const used = selected();
    const surcharge = cards.reduce((n,card) => n + Number(card.dataset.surcharge) * Number(card.querySelector('.qty').value || 0), 0);
    count.textContent = String(used);
    total.textContent = formatMoney(base + surcharge);
    submit.disabled = used !== max;
    status.textContent = used === max ? 'Your box is ready' : used < max ? `${max-used} remaining` : `${used-max} too many`;
    form.classList.toggle('complete', used === max);
  }

  cards.forEach(card => {
    const input = card.querySelector('.qty');
    card.querySelector('.minus').addEventListener('click', () => {
      input.value = String(Math.max(0, Number(input.value)-1)); update();
    });
    card.querySelector('.plus').addEventListener('click', () => {
      if (selected() >= max) return;
      input.value = String(Math.min(max, Number(input.value)+1)); update();
    });
  });
  update();
})();
