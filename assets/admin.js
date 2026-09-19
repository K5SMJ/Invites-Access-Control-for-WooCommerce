(function(){
  const rows = () => Array.from(document.querySelectorAll('.pm-acc'));
  const firstRow = document.querySelector('.pm-acc');
  if(!firstRow) return;
  const parent = firstRow.parentElement;

  const storageKey = 'inviacco_invites_view_state_v2';

  function loadState(){
    try{ return JSON.parse(localStorage.getItem(storageKey) || '{}'); }
    catch(e){ return {}; }
  }
  function saveState(state){
    try{ localStorage.setItem(storageKey, JSON.stringify(state)); }
    catch(e){}
  }

  const state = Object.assign({
    filter: 'all',
    search: '',
    sortKey: 'created',
    sortDir: 'desc'
  }, loadState());

  /* ---------- FILTER ---------- */
  const filterBtns = Array.from(document.querySelectorAll('.pm-filter'));
  filterBtns.forEach(btn=>{
    btn.addEventListener('click', ()=>{
      filterBtns.forEach(b=>b.classList.remove('is-active'));
      btn.classList.add('is-active');
      state.filter = btn.dataset.filter || 'all';
      saveState(state);
      applyAll();
    });
  });

  /* ---------- SEARCH ---------- */
  const searchInput = document.querySelector('.pm-search');
  const clearBtn = document.querySelector('.pm-clear');

  if(searchInput){
    searchInput.addEventListener('input', ()=>{
      state.search = (searchInput.value || '').trim().toLowerCase();
      saveState(state);
      applyAll();
    });
  }

  if(clearBtn){
    clearBtn.addEventListener('click', ()=>{
      state.search = '';
      saveState(state);
      if(searchInput) searchInput.value = '';
      applyAll();
    });
  }

  /* ---------- SORT ---------- */
  const sortBtns = Array.from(document.querySelectorAll('.pm-sortbtn'));

  function normalizeArrow(btn){
    const dir = btn.dataset.dir || 'asc';
    const base = btn.textContent.replace('▲','').replace('▼','').trim();
    btn.textContent = base + (dir === 'asc' ? ' ▲' : ' ▼');
  }

  function compare(a, b, key, dir){
    const va = a.dataset[key] ?? '';
    const vb = b.dataset[key] ?? '';

    if(key === 'expires' || key === 'created'){
      const na = parseInt(va || '0', 10);
      const nb = parseInt(vb || '0', 10);
      return dir === 'asc' ? (na - nb) : (nb - na);
    }

    if(key === 'status'){
      const order = { ISSUED:1, REDEEMED:2, EXPIRED:3 };
      return dir === 'asc'
        ? (order[va] || 99) - (order[vb] || 99)
        : (order[vb] || 99) - (order[va] || 99);
    }

    return dir === 'asc'
      ? String(va).localeCompare(String(vb))
      : String(vb).localeCompare(String(va));
  }

  function applySort(key, dir){
    rows()
      .map((r,i)=>({r,i}))
      .sort((A,B)=>{
        const c = compare(A.r, B.r, key, dir);
        return c !== 0 ? c : A.i - B.i;
      })
      .forEach(o=>parent.appendChild(o.r));
  }

  sortBtns.forEach(btn=>{
    normalizeArrow(btn);
    btn.addEventListener('click', ()=>{
      btn.dataset.dir = btn.dataset.dir === 'asc' ? 'desc' : 'asc';
      sortBtns.forEach(b=>b.classList.remove('is-active'));
      btn.classList.add('is-active');
      state.sortKey = btn.dataset.sort;
      state.sortDir = btn.dataset.dir;
      saveState(state);
      applyAll();
    });
  });

  /* ---------- FILTER + COUNT ---------- */
  function applyFilterAndSearch(){
    const f = state.filter || 'all';
    const q = (state.search || '').trim().toLowerCase();
    let visible = 0;

    rows().forEach(r=>{
      const status = r.dataset.status;
      const purchased = r.dataset.purchased === '1';

      const matchesFilter =
        (f === 'all') ||
        (f === 'PURCHASED' ? purchased : status === f);

      const matchesSearch =
        !q || (r.dataset.search || '').toLowerCase().includes(q);

      const show = matchesFilter && matchesSearch;
      r.style.display = show ? '' : 'none';
      if(show) visible++;
    });

    const countEl = document.querySelector('.pm-count-num');
    if(countEl) countEl.textContent = visible;
  }

  function applyAll(){
    applySort(state.sortKey, state.sortDir);
    applyFilterAndSearch();
    sortBtns.forEach(normalizeArrow);
  }

  /* ---------- INIT ---------- */
  filterBtns.forEach(b=>{
    b.classList.toggle('is-active', b.dataset.filter === state.filter);
  });
  if(searchInput) searchInput.value = state.search || '';
  applyAll();
})();
