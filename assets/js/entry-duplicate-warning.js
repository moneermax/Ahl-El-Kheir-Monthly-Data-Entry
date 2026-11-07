(() => {
 'use strict';
 const form=document.getElementById('entryForm');
 const warning=document.getElementById('entryDuplicateWarning');
 if(!form||!warning)return;

 const sponsorSelect=form.querySelector('#existingSponsor');
 const sponsorName=form.querySelector('#sponsorName');
 const entryDate=form.querySelector('#entryDate');
 const csrf=form.querySelector('input[name="_csrf"]');
 let timer=null,requestNo=0;

 const normalize=value=>String(value||'')
  .replace(/[٠-٩]/g,d=>String('٠١٢٣٤٥٦٧٨٩'.indexOf(d)))
  .replace(/[۰-۹]/g,d=>String('۰۱۲۳۴۵۶۷۸۹'.indexOf(d)))
  .replace(/[أإآ]/g,'ا').replace(/ى/g,'ي').replace(/ة/g,'ه')
  .replace(/\u0640/g,'').replace(/\s+/g,' ').trim().toLowerCase();

 function appendNotice(message,isError=false){
  const note=document.createElement('div');
  note.className='small fw-bold mt-2 '+(isError?'text-danger':'text-success');
  note.textContent=message;
  warning.append(note);
  warning.hidden=false;
 }

 async function deleteEntry(item,button){
  const details=[
   'الكفيل: '+item.sponsor_name,
   'الأم: '+item.mother_name,
   'اليتيم: '+item.orphan_name,
   'تاريخ الإدخال: '+item.entry_date,
   'الكفالة: '+Number(item.sponsorship_amount).toLocaleString('en-US'),
   'الخدمات: '+Number(item.services_amount).toLocaleString('en-US')
  ].join('\n');
  const message='هل تريد حذف هذا الإدخال الشهري السابق فقط؟\n\n'+details+
   '\n\nسيُحذف هذا الإدخال الشهري المحدد فقط. ستبقى علاقة الكفالة والكفيل والأم واليتيم وجميع التبرعات الأخرى كما هي؛ فالتبرعات سجلات مالية مستقلة ولا تُحذف تلقائياً مع إدخال الكفالة.';
  if(!window.confirm(message))return;

  button.disabled=true;
  const originalText=button.textContent;
  button.textContent='جارٍ الحذف…';
  try{
   const body=new URLSearchParams({
    _csrf:csrf?.value||'',
    entry_id:String(item.entry_id||''),
    sponsor_id:String(item.sponsor_id||''),
    entry_date:entryDate?.value||''
   });
   const response=await fetch('api/delete_entry_duplicate.php',{
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},
    body,
    credentials:'same-origin'
   });
   const result=await response.json();
   if(!response.ok||!result.success)throw new Error(result.error||'تعذر حذف الإدخال المحدد.');
   await check();
   appendNotice('تم حذف الإدخال الشهري رقم '+result.entry_id+' فقط، مع الاحتفاظ بعلاقة الكفالة والتبرعات، وتم تحديث قائمة التكرارات.');
  }catch(error){
   button.disabled=false;
   button.textContent=originalText;
   appendNotice(error.message||'تعذر حذف الإدخال. حدّث الصفحة وحاول مرة أخرى.',true);
   if(error.message&&error.message.includes('تغير هذا السجل'))schedule();
  }
 }

 function render(entries){
  warning.replaceChildren();
  if(!entries.length){warning.hidden=true;return;}

  const heading=document.createElement('div');
  heading.className='fw-bold mb-2';
  heading.textContent='تنبيه: لهذا الكفيل إدخال سابق في شهر التقرير نفسه. هذا تحذير للمراجعة فقط؛ يمكنك الحفظ إذا كان الإدخال الجديد مقصوداً.';
  warning.append(heading);

  const tableWrap=document.createElement('div');
  tableWrap.className='table-responsive';
  const table=document.createElement('table');
  table.className='table table-sm table-bordered table-warning align-middle mb-0';
  const thead=document.createElement('thead');
  const headRow=document.createElement('tr');
  ['الكفيل','الأم / اليتيم','تاريخ الإدخال','الكفالة','الخدمات','المطابقة','إجراء'].forEach(label=>{
   const th=document.createElement('th');th.textContent=label;headRow.append(th);
  });
  thead.append(headRow);table.append(thead);

  const submitted=new Set([...form.querySelectorAll('.orphan-row')].map(row=>{
   const familyId=row.dataset.family;
   const mother=form.querySelector('.mother-span-cell[data-family="'+familyId+'"] .mother-name');
   return normalize(mother?.value)+'|'+normalize(row.querySelector('.orphan-name')?.value);
  }));
  const tbody=document.createElement('tbody');
  entries.forEach(item=>{
   const tr=document.createElement('tr');
   const exact=submitted.has(normalize(item.mother_name)+'|'+normalize(item.orphan_name));
   const values=[
    item.sponsor_name,
    item.mother_name+' / '+item.orphan_name,
    item.entry_date,
    Number(item.sponsorship_amount).toLocaleString('en-US'),
    Number(item.services_amount).toLocaleString('en-US'),
    exact?'نفس الأم واليتيم موجودان':'إدخال سابق للكفيل'
   ];
   values.forEach((value,index)=>{
    const td=document.createElement('td');
    td.textContent=String(value??'');
    if(index===5&&exact)td.className='fw-bold text-danger';
    tr.append(td);
   });

   const actionCell=document.createElement('td');
   actionCell.className='text-nowrap';
   const deleteButton=document.createElement('button');
   deleteButton.type='button';
   deleteButton.className='btn btn-outline-danger btn-sm';
   deleteButton.textContent='حذف الإدخال';
   deleteButton.setAttribute('aria-label','حذف الإدخال السابق رقم '+item.entry_id);
   deleteButton.addEventListener('click',()=>deleteEntry(item,deleteButton));
   actionCell.append(deleteButton);
   tr.append(actionCell);
   tbody.append(tr);
  });
  table.append(tbody);tableWrap.append(table);warning.append(tableWrap);
  warning.hidden=false;
 }

 async function check(){
  const current=++requestNo;
  const id=sponsorSelect?.value||'';
  const name=id?'':(sponsorName?.value||'').trim();
  const date=entryDate?.value||'';
  if((!id&&!name)||!date){render([]);return;}

  const body=new URLSearchParams({
   _csrf:csrf?.value||'',
   sponsor_id:id,
   sponsor_name:name,
   entry_date:date
  });
  try{
   const response=await fetch('api/check_entry_duplicates.php',{
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},
    body,
    credentials:'same-origin'
   });
   if(!response.ok)throw new Error('Duplicate check failed');
   const data=await response.json();
   if(current!==requestNo)return;
   render(Array.isArray(data.entries)?data.entries:[]);
  }catch(error){
   if(current!==requestNo)return;
   warning.replaceChildren();
   const note=document.createElement('div');
   note.className='small';
   note.textContent='تعذر التحقق من الإدخالات السابقة حالياً. سيظل الحفظ متاحاً؛ راجع تقرير الشهر إذا كنت تشك في التكرار.';
   warning.append(note);warning.hidden=false;
  }
 }

 function schedule(){clearTimeout(timer);timer=setTimeout(check,250);}
 sponsorSelect?.addEventListener('change',schedule);
 sponsorName?.addEventListener('input',schedule);
 entryDate?.addEventListener('change',schedule);
 form.addEventListener('input',event=>{
  if(event.target.matches('.mother-name,.orphan-name'))schedule();
 });
 form.addEventListener('submit',schedule);
 schedule();
})();
