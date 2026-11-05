(() => {
'use strict';
const $ = (selector, root=document) => root.querySelector(selector);
const Parser = window.MDEWhatsAppParser;
if (!Parser) throw new Error('WhatsApp parser module was not loaded.');

function set(el,value) {
 if (!el || value === undefined || value === null) return false;
 el.value=value;
 el.dispatchEvent(new Event('input',{bubbles:true}));
 el.dispatchEvent(new Event('change',{bubbles:true}));
 return true;
}
function clear(el) {
 if (!el) return;
 el.value='';
 el.dispatchEvent(new Event('input',{bubbles:true}));
 el.dispatchEvent(new Event('change',{bubbles:true}));
}
function clearPerson(select) { if(!select)return; select.value=''; const root=select.closest('[data-person-dropdown]'); if(root){const placeholder=root.querySelector('[data-person-dropdown-option][data-value=""]'); if(placeholder){root.querySelector('[data-person-dropdown-toggle]').textContent=placeholder.textContent.trim();root.querySelectorAll('[data-person-dropdown-option]').forEach(option=>{const active=option===placeholder;option.classList.toggle('active',active);option.setAttribute('aria-checked',active?'true':'false');});}} select.dispatchEvent(new Event('input',{bubbles:true}));select.dispatchEvent(new Event('change',{bubbles:true})); }
function normalizeName(value) {
 return String(value||'').replace(/[٠-٩]/g,d=>String('٠١٢٣٤٥٦٧٨٩'.indexOf(d))).replace(/[۰-۹]/g,d=>String('۰۱۲۳۴۵۶۷۸۹'.indexOf(d))).replace(/[أإآ]/g,'ا').replace(/ى/g,'ي').replace(/ة/g,'ه').replace(/\u0640/g,'').replace(/\s+/g,' ').trim().toLowerCase();
}
function departureSponsorMatches(name) {
 const select=$('#departureSponsor');
 if(!select||!name)return {active:[],inactive:[]};
 const wanted=normalizeName(name);
 const matches=[...select.options].filter(option=>option.value&&normalizeName(option.textContent.replace(/—.*$/,'').trim())===wanted);
 return {active:matches.filter(option=>option.dataset.active!=='0'),inactive:matches.filter(option=>option.dataset.active==='0')};
}
function choose(select,name) {
 if (!select || !name) return false;
 const wanted=normalizeName(name);
 if (select.matches('[data-person-dropdown-value]')) {
  const root=select.closest('[data-person-dropdown]');
  const matches=[...root.querySelectorAll('[data-person-dropdown-option]')].filter(option=>option.dataset.value&&normalizeName(option.dataset.value)===wanted);
  if(matches.length!==1)return false;
  select.value=matches[0].dataset.value;
  root.querySelector('[data-person-dropdown-toggle]').textContent=matches[0].textContent.trim();
  root.querySelectorAll('[data-person-dropdown-option]').forEach(option=>{const active=option===matches[0];option.classList.toggle('active',active);option.setAttribute('aria-checked',active?'true':'false');});
  select.dispatchEvent(new Event('change',{bubbles:true}));
  return true;
 }
 const matches=[...select.options].filter(option=>option.value&&normalizeName(option.textContent.replace(/—.*$/,'').trim())===wanted&&!option.disabled);
 if(matches.length!==1)return false;
 select.value=matches[0].value;
 select.dispatchEvent(new Event('change',{bubbles:true}));
 return true;
}
function applyEntry(record) {
 const payload=Parser.buildEntryFormPayload(record);
 const successfulFields={};
 const result=Parser.populateEntryForm(payload,{
  setField(field,value) {
   let success=false;
   if(field==='sponsor') {
    const existing=$('#existingSponsor');
    if(choose(existing,value)){clear($('#sponsorName'));success=true;}
    else {if(existing){existing.value='';existing.dispatchEvent(new Event('change',{bubbles:true}));}success=set($('#sponsorName'),value);}
   } else if(field==='file') success=set($('#entryForm').elements.file_number,value);
   else if(field==='type') success=value?set($('#sponsorType'),value):true;
   else if(field==='date') {success=set($('#entryDate'),value);if(!value)clear($('#entryDate'));}
   else if(field==='entered') {success=value?choose($('#enteredBy'),value):false;if(!success)clearPerson($('#enteredBy'));}
   else if(field==='supervisor') {success=value?choose($('#supervisorName'),value):false;if(!success)clearPerson($('#supervisorName'));}
   else if(field==='notes') success=set($('#entryNotes'),value);
   successfulFields[field]=success;
   return success;
  },
  fieldWasSet(field){return !!successfulFields[field];},
  setFamilies(families) {
   const container=$('#families');
   container.innerHTML='';
   const orphanRows=[];
   families.forEach((family,index)=>{
    $('#addFamily').click();
    const block=container.querySelectorAll('.family-block')[index];
    if(!block)return;
    set($('.mother-name',block),family.mother);
    const rows=container.querySelectorAll('.orphan-row[data-family="'+block.dataset.family+'"]');
    const first=rows[0];
    if(family.orphans.length)set($('.orphan-name',first),family.orphans[0]);
    orphanRows.push(first);
    for(let i=1;i<family.orphans.length;i++){
     $('.add-orphan',block).click();
     const current=container.querySelectorAll('.orphan-row[data-family="'+block.dataset.family+'"]');
     const row=current[current.length-1];
     set($('.orphan-name',row),family.orphans[i]);
     orphanRows.push(row);
    }
   });
   this.orphanRows=orphanRows;
  },
  setAmounts(data) {
   (this.orphanRows||[]).forEach((row,index)=>{
    row.querySelector('.orphan-amount').value=data.sponsorshipAllocation?data.sponsorshipAllocation[index]:(data.expectedSponsorship!==''?'':'');
    row.querySelector('.orphan-services').value=data.expectedServices!==''?(data.servicesAllocation?data.servicesAllocation[index]:''):'0';
   });
   set($('#expectedSponsorship'),data.expectedSponsorship);
   set($('#expectedServices'),data.expectedServices);
  },
  updateTotals(){window.mdeUpdateAmountTotals?.();}
 });
 return result;
}
function applyDeparture(record) {
 const payload=Parser.buildDepartureFormPayload(record);
 const fields=payload.fields;
 const existingMode=$('#modeExisting'),newMode=$('#modeNew'),select=$('#departureSponsor');
 const matched=choose(select,fields.sponsor);
 if(matched){
  existingMode.checked=true;
  existingMode.dispatchEvent(new Event('change',{bubbles:true}));
 } else {
  newMode.checked=true;
  newMode.dispatchEvent(new Event('change',{bubbles:true}));
 }
 const fields=[
  ['رقم الملف',matched?'#existingFileNumber':'#newFileNumber',fields.file],
  ['تاريخ المغادرة','#departureDate',fields.date],
  ['آخر سداد','#lastPaidMonth',fields.lastPaid],
  ['عدد الأيتام','#orphanCount',fields.count],
  ['الكفالة','#sponsorshipAmount',fields.sponsorship],
  ['الخدمات','#servicesAmount',fields.services],
  ['ملاحظات','#departureNotes',payload.notes]
 ];
 if(!matched){
  fields.push(
   ['اسم الكفيل','#newSponsorName',fields.sponsor],
   ['نوع الكفيل','#newSponsorType',fields.type],
   ['الهاتف','#newSponsorPhone',fields.phone],
   ['العنوان','#newSponsorAddress',fields.address],
   ['ملاحظات الكفيل','#newSponsorNotes',fields.sponsorNotes]
  );
 }
 const failed=[];
 const populated=[];
 fields.forEach(([label,selector,value])=>{
  const el=$(selector);
  if(value===undefined||value===null||value===''){
   if(el)clear(el);
   return;
  }
  if(!set(el,String(value))||String(el.value)!==String(value))failed.push(label);
  else populated.push(label);
 });
 const unresolved=[];
 if(!record.entered||!choose($('#enteredBy'),record.entered)){
  clearPerson($('#enteredBy'));
  if(record.entered)unresolved.push('بواسطة: '+record.entered);
 }
 if(!record.supervisor||!choose($('#supervisorName'),record.supervisor)){
  clearPerson($('#supervisorName'));
  if(record.supervisor)unresolved.push('المشرف: '+record.supervisor);
 }
 const notes=[payload.notes,...unresolved].filter(Boolean).join(' | ');
 set($('#departureNotes'),notes);
 return {
  matched,
  populated,
  failed,
  unresolved,
  missingDate:!record.date,
  missingLastPaid:!record.lastPaid
 };
}
const panel=$('[data-whatsapp-import]');
if(!panel)return;
const profile=panel.dataset.profile,input=$('#whatsappMessage',panel),analyze=$('#whatsappAnalyze',panel),select=$('#whatsappRecord',panel),apply=$('#whatsappApply',panel),status=$('#whatsappStatus',panel),details=$('#whatsappDetails',panel);
let records=[],pendingIndex=null;
const storageKey='mdeWhatsappQueue:'+profile,pendingKey='mdeWhatsappPending:'+profile;
function hasRequiredFields(record) {
 if(profile==='entry')return !!record.sponsor&&Parser.hasMotherNames(record)&&record.orphans.length>0&&!!record.date;
 return !!record.sponsor;
}
function render() {
 select.innerHTML='';
 records.forEach((record,index)=>{const option=document.createElement('option');option.value=String(index);option.textContent='سجل '+(index+1)+(record.sponsor?' — '+record.sponsor:' — اسم الكفيل غير واضح');select.append(option);});
 select.disabled=records.length<2;
 const record=records[Number(select.value)||0];
 if(!record){apply.disabled=true;details.textContent='';return;}
 const missing=profile==='entry'
  ?['اسم الكفيل','اسم أم واحدة على الأقل','اسم يتيم واحد على الأقل','تاريخ الانضمام'].filter((label,index)=>![record.sponsor,Parser.hasMotherNames(record),record.orphans.length,record.date][index])
  :['اسم الكفيل','تاريخ المغادرة',...(record.type==='tree'?[]:['عدد الأيتام'])].filter((label,index)=>![record.sponsor,record.date,...(record.type==='tree'?[]:[record.count])][index]);
 const warnings=[...(record.warnings||[])];
 details.textContent=[missing.length?'بيانات مطلوبة للمراجعة: '+missing.join('، '):'',warnings.length?'تنبيهات: '+[...new Set(warnings)].join('، '):''].filter(Boolean).join(' · ');
 apply.disabled=!hasRequiredFields(record);
}
function restoreQueue() {
 try {
  const saved=JSON.parse(sessionStorage.getItem(storageKey)||'null');
  if(!saved)return;
  const wasSaved=new URLSearchParams(location.search).get('whatsapp_saved')==='1';
  const pendingRaw=sessionStorage.getItem(pendingKey),pending=pendingRaw===null?NaN:Number(pendingRaw);
  if(wasSaved&&Number.isInteger(pending)){saved.records.splice(pending,1);sessionStorage.removeItem(pendingKey);if(!saved.records.length){sessionStorage.removeItem(storageKey);return;}}
  records=saved.records||[];input.value=saved.raw||'';render();
  status.textContent=wasSaved?'تم حفظ السجل السابق. اختر السجل التالي لتحميله ومراجعته.':'تم استعادة قائمة السجلات من هذه الجلسة.';
 } catch(error){sessionStorage.removeItem(storageKey);sessionStorage.removeItem(pendingKey);}
}
analyze.addEventListener('click',()=>{
 const raw=input.value.trim();
 if(!raw){status.textContent='الصق رسالة واتساب أولاً.';return;}
 pendingIndex=null;
 try{sessionStorage.removeItem(storageKey);sessionStorage.removeItem(pendingKey);}catch(error){}
 const contextDate=profile==='entry'?$('#entryDate')?.value:$('#departureDate')?.value;
 records=profile==='entry'?Parser.applyMessageLevelFields(Parser.split(raw).map((part,index)=>Parser.entry(part,index,contextDate)),raw):Parser.splitDepartures(raw,contextDate);
 render();
 status.textContent=records.length>1?'تم العثور على '+records.length+' سجلات منفصلة. اختر سجلاً لتحميله في النموذج.':'';
});
select.addEventListener('change',render);
apply.addEventListener('click',()=>{
 const record=records[Number(select.value)||0];
 if(!record)return;
 if(!hasRequiredFields(record)){status.textContent='تعذر تحميل السجل بأمان. راجع البيانات الناقصة في التنبيهات وأصلح الرسالة قبل إعادة التحليل.';return;}
 if(profile==='departure'){
  const matches=departureSponsorMatches(record.sponsor);
  if(matches.active.length+matches.inactive.length>1){
   status.textContent='يوجد أكثر من كفيل بالاسم المطابق «'+record.sponsor+'». لم يتم تحميل السجل لتجنب اختيار كفيل خاطئ أو إنشاء سجل مكرر.';
   return;
  }
 }
 if(!window.confirm('سيتم تحميل البيانات المستخرجة في حقول النموذج. لن يتم حفظ أي بيانات. قد تُستبدل القيم الموجودة. هل تريد المتابعة؟'))return;
 let allocation,population=null;
 if(profile==='entry')allocation=applyEntry(record);
 else {population=applyDeparture(record);allocation={sponsorshipManual:false,servicesManual:false};}
 pendingIndex=Number(select.value)||0;
 const manual=[];if(allocation.sponsorshipManual)manual.push('الكفالة');if(allocation.servicesManual)manual.push('الخدمات');
 if(profile==='departure'){
  const parts=[];
  if(population.populated.length)parts.push('تم تحميل: '+population.populated.join('، '));
  if(population.failed.length)parts.push('تعذر تعبئة: '+population.failed.join('، '));
  if(population.unresolved.length)parts.push('راجع الأسماء: '+population.unresolved.join('، '));
  if(population.missingDate)parts.push('تاريخ المغادرة غير موجود في الرسالة ويجب إدخاله يدوياً');
  if(population.missingLastPaid)parts.push('آخر سداد غير محسوم ويجب مراجعته يدوياً');
  status.textContent=parts.join(' · ');
 } else {
  status.textContent=manual.length?'تعذر تقسيم إجمالي '+manual.join(' و')+' بالتساوي؛ أدخل التوزيع يدوياً وتأكد من مطابقة الإجمالي. ':'';
 }
});
const targetForm=profile==='entry'?$('#entryForm'):document.querySelector('form.card');
if(targetForm)targetForm.addEventListener('submit',()=>{
 if(pendingIndex!==null&&records.length){try{sessionStorage.setItem(storageKey,JSON.stringify({records,raw:input.value}));sessionStorage.setItem(pendingKey,String(pendingIndex));}catch(error){status.textContent='تعذر الاحتفاظ ببقية السجلات في الجلسة؛ يمكنك إعادة لصق الرسالة بعد الحفظ.';}}
});
restoreQueue();
})();