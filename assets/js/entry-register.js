(() => {
 const families=document.getElementById('families');
 const familyTpl=document.getElementById('familyTemplate'),orphanTpl=document.getElementById('orphanTemplate'),donationTpl=document.getElementById('donationTemplate');
 const sponsorCells=[...document.getElementById('sponsorCellsTemplate').content.querySelector('tr').children];
 let nextFamilyId=1;
 function rowsForFamily(id){return [...families.querySelectorAll('.orphan-row[data-family="'+id+'"]')];}
 function syncMergedCells(){
  sponsorCells.forEach(cell=>cell.remove());
  const blocks=[...families.querySelectorAll('.family-block')];
  const allRows=[...families.querySelectorAll('.orphan-row')];
  blocks.forEach(block=>{const group=rowsForFamily(block.dataset.family),motherCell=block.querySelector('.mother-span-cell');if(motherCell)motherCell.rowSpan=group.length;});
  if(blocks.length&&allRows.length){
   const firstRow=allRows[0],motherCell=firstRow.querySelector('.mother-span-cell');
   sponsorCells.slice(0,3).forEach(cell=>{cell.rowSpan=allRows.length;firstRow.insertBefore(cell,motherCell);});
   let anchor=firstRow.querySelector('.orphan-services').closest('td');
   sponsorCells.slice(3).forEach(cell=>{cell.rowSpan=allRows.length;anchor.after(cell);anchor=cell;});
  }
 }
 function addOrphan(block){
  const id=block.dataset.family,group=rowsForFamily(id),last=group[group.length-1],row=orphanTpl.content.querySelector('tr').cloneNode(true);
  row.dataset.family=id;last.after(row);
 }
 function reindex(){
  const blocks=[...families.querySelectorAll('.family-block')];
  blocks.forEach((block,fi)=>{
   const id=block.dataset.family,title=block.querySelector('.family-title'),mother=block.querySelector('.mother-name');
   if(title)title.textContent='بيانات الأم '+(fi+1);
   const removeFamily=block.querySelector('.remove-family');if(removeFamily)removeFamily.disabled=blocks.length<=1;
   if(mother)mother.name='families['+fi+'][mother_name]';
   rowsForFamily(id).forEach(row=>{const removeOrphan=row.querySelector('.remove-orphan');if(removeOrphan)removeOrphan.disabled=rowsForFamily(id).length<=1;});
   rowsForFamily(id).forEach((row,oi)=>{
    row.querySelector('.orphan-name').name='families['+fi+'][orphans]['+oi+'][name]';
    row.querySelector('.orphan-amount').name='families['+fi+'][orphans]['+oi+'][amount]';
    row.querySelector('.orphan-services').name='families['+fi+'][orphans]['+oi+'][services]';
   });
  });
  const donations=document.getElementById('donations');
  [...donations.querySelectorAll('.donation-row')].forEach((row,i)=>{
   row.querySelector('.donation-type').name='donation_type['+i+']';
   row.querySelector('.donation-amount').name='donation_amount['+i+']';
  });
  syncMergedCells();
 }
 families.append(familyTpl.content.querySelector('tr').cloneNode(true));
 const firstBlock=families.querySelector('.family-block');firstBlock.dataset.family=String(nextFamilyId++);
 syncMergedCells();
 const donations=document.getElementById('donations');
 const expectedSponsorship=document.getElementById('expectedSponsorship'),expectedServices=document.getElementById('expectedServices');
 const actualSponsorship=document.getElementById('actualSponsorship'),actualServices=document.getElementById('actualServices'),amountCheck=document.getElementById('amountCheck');
 const entryForm=document.getElementById('entryForm');
 const existing=document.getElementById('existingSponsor'),sponsorName=document.getElementById('sponsorName'),sponsorType=document.getElementById('sponsorType');
 function updateAmountTotals(){
  let sponsorship=0,services=0;
  families.querySelectorAll('.orphan-row').forEach(row=>{sponsorship+=Math.max(0,Number(row.querySelector('.orphan-amount').value)||0);services+=Math.max(0,Number(row.querySelector('.orphan-services').value)||0);});
  actualSponsorship.textContent=sponsorship.toLocaleString('en-US');actualServices.textContent=services.toLocaleString('en-US');
  const expectedS=expectedSponsorship.value===''?null:Number(expectedSponsorship.value),expectedV=expectedServices.value===''?null:Number(expectedServices.value),errors=[];
  if(expectedS!==null&&sponsorship!==expectedS)errors.push('إجمالي الكفالة الموزع لا يطابق الإجمالي الوارد');
  if(expectedV!==null&&services!==expectedV)errors.push('إجمالي الخدمات الموزع لا يطابق الإجمالي الوارد');
  amountCheck.textContent=errors.length?errors.join('، '):'الإجماليات متطابقة أو لم يتم إدخال إجمالي مرجعي.';
  amountCheck.className=errors.length?'small mt-2 text-danger fw-bold':'small mt-2 text-success';
  return errors.length===0;
 }
 function addFamily(){
  const block=familyTpl.content.querySelector('tr').cloneNode(true);
  block.dataset.family=String(nextFamilyId++);families.append(block);reindex();updateAmountTotals();
 }
 function syncSponsorMode(){
  const isExisting=!!existing.value;sponsorName.required=!isExisting;sponsorName.disabled=isExisting;
  if(isExisting){sponsorName.value='';sponsorType.value=existing.selectedOptions[0].dataset.type||'individual';}
  else sponsorType.value='individual';
 }
 function reindexAndUpdate(){reindex();updateAmountTotals();}
 reindexAndUpdate();syncSponsorMode();
 document.getElementById('addFamily').addEventListener('click',addFamily);
 document.getElementById('addFamilyBottom').addEventListener('click',addFamily);
 document.getElementById('addDonation').addEventListener('click',()=>{donations.append(donationTpl.content.cloneNode(true));reindex();});
 document.addEventListener('click',e=>{
  if(e.target.matches('.add-orphan')){addOrphan(e.target.closest('.family-block'));reindexAndUpdate();}
  if(e.target.matches('.remove-family')){
   const block=e.target.closest('.family-block'),id=block.dataset.family;
   if(families.querySelectorAll('.family-block').length>1)rowsForFamily(id).forEach(row=>row.remove());
   reindexAndUpdate();
  }
  if(e.target.matches('.remove-orphan')){
   const row=e.target.closest('.orphan-row'),id=row.dataset.family;
   if(rowsForFamily(id).length>1){
    if(row.classList.contains('family-block')){const next=row.nextElementSibling,motherCell=row.querySelector('.mother-span-cell');next.insertBefore(motherCell,next.firstElementChild);next.classList.add('family-block');}
    row.remove();
   }
   reindexAndUpdate();
  }
  if(e.target.matches('.remove-donation')){e.target.closest('.donation-row').remove();reindex();}
 });
 families.addEventListener('input',updateAmountTotals);expectedSponsorship.addEventListener('input',updateAmountTotals);expectedServices.addEventListener('input',updateAmountTotals);
 entryForm.addEventListener('submit',event=>{if(!updateAmountTotals()){event.preventDefault();amountCheck.scrollIntoView({behavior:'smooth',block:'center'});}});
 existing.addEventListener('change',syncSponsorMode);
 window.mdeUpdateAmountTotals=updateAmountTotals;
})();