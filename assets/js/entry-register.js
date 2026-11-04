(() => {
 // Excel-style register. Sponsor cells span every orphan row; each mother cell spans her own orphans.
 // layout() rebuilds all rowspans from the current rows after every structural change, so there is
 // no incremental cell-moving to get out of sync.
 const $ = id => document.getElementById(id);
 const families=$('families'),donationTpl=$('donationTemplate'),motherTpl=$('motherCellTemplate'),orphanTpl=$('orphanRowTemplate');
 const sponsorCells=[...$('sponsorCellsTemplate').content.querySelector('tr').children];
 const preCells=sponsorCells.slice(0,4),postCells=sponsorCells.slice(4);   // before the mother cell / after the amounts
 const q=sel=>sponsorCells.map(c=>c.querySelector(sel)).find(Boolean);
 const donations=q('#donations'),existing=q('#existingSponsor'),sponsorName=q('#sponsorName'),sponsorType=q('#sponsorType');
 const motherCells=new Map();
 const expectedSponsorship=$('expectedSponsorship'),expectedServices=$('expectedServices');
 const actualSponsorship=$('actualSponsorship'),actualServices=$('actualServices'),amountCheck=$('amountCheck'),entryForm=$('entryForm');
 let nextFamilyId=1;

 const allRows=()=>[...families.querySelectorAll('.orphan-row')];
 const rowsForFamily=id=>allRows().filter(r=>r.dataset.family===id);
 const familyIds=()=>[...new Set(allRows().map(r=>r.dataset.family))];

 function layout(){
  const rows=allRows();
  [...sponsorCells,...motherCells.values()].forEach(c=>c.remove());
  rows.forEach(r=>r.classList.remove('family-block'));
  const groups=new Map();
  rows.forEach(r=>{if(!groups.has(r.dataset.family))groups.set(r.dataset.family,[]);groups.get(r.dataset.family).push(r);});
  [...motherCells.keys()].forEach(id=>{if(!groups.has(id))motherCells.delete(id);});
  groups.forEach((group,id)=>{
   const first=group[0],cell=motherCells.get(id);
   first.classList.add('family-block');
   if(cell){cell.rowSpan=group.length;first.prepend(cell);}
  });
  if(rows.length){
   sponsorCells.forEach(c=>{c.rowSpan=rows.length;});
   rows[0].prepend(...preCells);
   rows[0].append(...postCells);
  }
 }
 function reindex(){
  const ids=familyIds();
  ids.forEach((id,fi)=>{
   const cell=motherCells.get(id),rows=rowsForFamily(id);
   if(cell){
    cell.querySelector('.family-title').textContent='الأم '+(fi+1);
    cell.querySelector('.remove-family').disabled=ids.length<=1;
    cell.querySelector('.mother-name').name='families['+fi+'][mother_name]';
   }
   rows.forEach((row,oi)=>{
    row.querySelector('.remove-orphan').disabled=rows.length<=1;
    row.querySelector('.orphan-name').name='families['+fi+'][orphans]['+oi+'][name]';
    row.querySelector('.orphan-amount').name='families['+fi+'][orphans]['+oi+'][amount]';
    row.querySelector('.orphan-services').name='families['+fi+'][orphans]['+oi+'][services]';
   });
  });
  [...donations.querySelectorAll('.donation-row')].forEach((row,i)=>{
   row.querySelector('.donation-type').name='donation_type['+i+']';
   row.querySelector('.donation-amount').name='donation_amount['+i+']';
  });
 }
 function updateAmountTotals(){
  let sponsorship=0,services=0;
  allRows().forEach(row=>{sponsorship+=Math.max(0,Number(row.querySelector('.orphan-amount').value)||0);services+=Math.max(0,Number(row.querySelector('.orphan-services').value)||0);});
  actualSponsorship.textContent=sponsorship.toLocaleString('en-US');actualServices.textContent=services.toLocaleString('en-US');
  const expectedS=expectedSponsorship.value===''?null:Number(expectedSponsorship.value),expectedV=expectedServices.value===''?null:Number(expectedServices.value),errors=[];
  if(expectedS!==null&&sponsorship!==expectedS)errors.push('إجمالي الكفالة الموزع لا يطابق الإجمالي الوارد');
  if(expectedV!==null&&services!==expectedV)errors.push('إجمالي الخدمات الموزع لا يطابق الإجمالي الوارد');
  amountCheck.textContent=errors.length?errors.join('، '):'الإجماليات متطابقة أو لم يتم إدخال إجمالي مرجعي.';
  amountCheck.className='totals-status'+(errors.length?' is-bad':'');
  return errors.length===0;
 }
 function refresh(){layout();reindex();updateAmountTotals();}
 function newOrphanRow(id){const row=orphanTpl.content.querySelector('tr').cloneNode(true);row.dataset.family=id;return row;}
 function addOrphan(id){
  const group=rowsForFamily(id),row=newOrphanRow(id);
  group[group.length-1].after(row);
  refresh();return row;
 }
 function addFamily(){
  const id=String(nextFamilyId++),cell=motherTpl.content.querySelector('td').cloneNode(true);
  cell.dataset.family=id;motherCells.set(id,cell);
  const row=newOrphanRow(id);families.append(row);
  refresh();return row;
 }
 function syncSponsorMode(){
  const isExisting=!!existing.value;sponsorName.required=!isExisting;sponsorName.disabled=isExisting;
  sponsorName.placeholder=isExisting?'من الكفيل المحدد':'اسم الكفيل الجديد';
  if(isExisting){sponsorName.value='';sponsorType.value=existing.selectedOptions[0].dataset.type||'individual';}
  else sponsorType.value='individual';
 }

 addFamily();syncSponsorMode();
 $('addFamily').addEventListener('click',addFamily);
 $('addFamilyBottom').addEventListener('click',addFamily);
 $('addDonation').addEventListener('click',()=>{donations.append(donationTpl.content.cloneNode(true));reindex();});

 document.addEventListener('click',e=>{
  const t=e.target.closest('button');if(!t)return;
  if(t.matches('.add-orphan')){addOrphan(t.closest('.mother-span-cell').dataset.family).querySelector('.orphan-name').focus();}
  else if(t.matches('.remove-family')){
   const id=t.closest('.mother-span-cell').dataset.family;
   if(familyIds().length>1){rowsForFamily(id).forEach(r=>r.remove());motherCells.delete(id);}
   refresh();
  }
  else if(t.matches('.remove-orphan')){
   const row=t.closest('.orphan-row');
   if(rowsForFamily(row.dataset.family).length>1)row.remove();
   refresh();
  }
  else if(t.matches('.remove-donation')){t.closest('.donation-row').remove();reindex();}
 });

 // Fast entry: typing replaces the default 0, and Enter moves to the next field instead of submitting.
 families.addEventListener('focusin',e=>{if(e.target.matches('.orphan-amount,.orphan-services'))e.target.select();});
 families.addEventListener('keydown',e=>{
  if(e.key!=='Enter'||e.isComposing||!e.target.matches('.orphan-name,.orphan-amount,.orphan-services'))return;
  e.preventDefault();
  const order=['.orphan-name','.orphan-amount','.orphan-services'],row=e.target.closest('.orphan-row'),idx=order.findIndex(sel=>e.target.matches(sel));
  if(idx<2){row.querySelector(order[idx+1]).focus();return;}
  const next=row.nextElementSibling;
  if(next&&next.classList.contains('orphan-row')&&next.dataset.family===row.dataset.family){next.querySelector('.orphan-name').focus();return;}
  addOrphan(row.dataset.family).querySelector('.orphan-name').focus();
 });

 families.addEventListener('input',updateAmountTotals);expectedSponsorship.addEventListener('input',updateAmountTotals);expectedServices.addEventListener('input',updateAmountTotals);
 entryForm.addEventListener('submit',event=>{if(!updateAmountTotals()){event.preventDefault();amountCheck.scrollIntoView({behavior:'smooth',block:'center'});}});
 existing.addEventListener('change',syncSponsorMode);
 window.mdeUpdateAmountTotals=updateAmountTotals;
})();
