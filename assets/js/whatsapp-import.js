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
function matchingSponsorOptions(select,name) {
 if(!select||!name)return [];
 const wanted=normalizeName(name);
 return [...select.options].filter(option=>option.value&&!option.disabled&&normalizeName(option.textContent.replace(/—.*$/,'').trim())===wanted);
}
function resolveDepartureSponsor(select,sponsorName,fileNumber) {
 const matches=matchingSponsorOptions(select,sponsorName),wantedFile=normalizeName(fileNumber);
 const fileMatches=wantedFile?[...select.options].filter(option=>option.value&&!option.disabled&&normalizeName(option.dataset.file)===wantedFile):[];
 const nameFileMatches=wantedFile?matches.filter(option=>normalizeName(option.dataset.file)===wantedFile):[];
 if(matches.length===1)return {id:matches[0].value,ambiguous:false,message:''};
 if(matches.length>1&&nameFileMatches.length===1)return {id:nameFileMatches[0].value,ambiguous:false,message:''};
 if(matches.length>1)return {id:'',ambiguous:true,message:'الاسم موجود لأكثر من كفيل؛ عدّل رقم الملف ليطابق السجل الصحيح'};
 if(fileMatches.length===1)return {id:fileMatches[0].value,ambiguous:false,message:''};
 return {id:'',ambiguous:false,message:matches.length===0?'لم يتم العثور على سجل مطابق؛ راجع الاسم ورقم الملف':'رقم الملف لا يحدد سجلاً واحداً؛ راجع البيانات'};
}
window.MDEWhatsAppImportHelpers={matchingSponsorOptions,resolveDepartureSponsor,normalizePersonnelName};
function departureSponsorMatches(name) {
 const select=$('#departureSponsor');
 const matches=matchingSponsorOptions(select,name);
 return {active:matches.filter(option=>option.dataset.active!=='0'),inactive:matches.filter(option=>option.dataset.active==='0')};
}
function normalizePersonnelName(name) { const normalized=normalizeName(name); return normalized==='هناء خلف الله'?'هناء':normalized; }
function choose(select,name) {
 if (!select || !name) return false;
 const wanted=normalizePersonnelName(name);
 if (select.matches('[data-person-dropdown-value]')) {
  const root=select.closest('[data-person-dropdown]');
  if(!root){
   const matches=[...select.options].filter(option=>option.value&&normalizeName(option.value)===wanted);
   if(matches.length!==1)return false;
   select.value=matches[0].value;
   select.dispatchEvent(new Event('change',{bubbles:true}));
   return true;
  }
  const matches=[...root.querySelectorAll('[data-person-dropdown-option]')].filter(option=>option.dataset.value&&normalizeName(option.dataset.value)===wanted);
  if(matches.length!==1)return false;
  select.value=matches[0].dataset.value;
  root.querySelector('[data-person-dropdown-toggle]').textContent=matches[0].textContent.trim();
  root.querySelectorAll('[data-person-dropdown-option]').forEach(option=>{const active=option===matches[0];option.classList.toggle('active',active);option.setAttribute('aria-checked',active?'true':'false');});
  select.dispatchEvent(new Event('change',{bubbles:true}));
  return true;
 }
 const matches=matchingSponsorOptions(select,name);
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
    success=set($('#sponsorName'),value);
   } else if(field==='file') {
    const fileInput=$('#entryForm').elements.file_number;
    if(value!=='')success=set(fileInput,value);
    else {clear(fileInput);success=true;}
   }
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
function applyDeparture(record,options={}) {
 const payload=Parser.buildDepartureFormPayload(record),payloadFields=payload.fields,existingMode=$('#modeExisting'),newMode=$('#modeNew'),select=$('#departureSponsor'),sponsorNameInput=$('#departureSponsorName'),sponsorIdInput=$('#departureSponsorId');
 const resolution=resolveDepartureSponsor(select,payloadFields.sponsor,payloadFields.file),ambiguousSponsor=!!options.ambiguousSponsor||resolution.ambiguous,matched=!!resolution.id,useExistingMode=matched||ambiguousSponsor;
 if(useExistingMode){existingMode.checked=true;existingMode.dispatchEvent(new Event('change',{bubbles:true}));if(sponsorNameInput)set(sponsorNameInput,payloadFields.sponsor);if(sponsorIdInput)sponsorIdInput.value=resolution.id;const hint=$('#departureSponsorHint');if(hint){hint.textContent=resolution.message||'يمكن تعديل الاسم ورقم الملف لهذا السجل فقط؛ لن يتغير اسم الكفيل في القائمة الرئيسية.';hint.classList.toggle('text-danger',!!resolution.message);hint.classList.toggle('text-muted',!resolution.message);}}
 else{newMode.checked=true;newMode.dispatchEvent(new Event('change',{bubbles:true}));}
 const matchedOption=matched?[...select.options].find(option=>option.value===resolution.id):null,fileValue=payloadFields.file||(matched?(matchedOption?.dataset.file||''):'');
 const fields=[['اسم الكفيل','#departureSponsorName',useExistingMode?payloadFields.sponsor:''],['رقم الملف',useExistingMode?'#existingFileNumber':'#newFileNumber',fileValue],['تاريخ المغادرة','#departureDate',payloadFields.date],['آخر سداد','#lastPaidMonth',payloadFields.lastPaid],['عدد الأيتام','#orphanCount',payloadFields.count],['الكفالة','#sponsorshipAmount',payloadFields.sponsorship],['الخدمات','#servicesAmount',payloadFields.services],['ملاحظات','#departureNotes',payload.notes]];
 if(!useExistingMode)fields.push(['اسم الكفيل','#newSponsorName',payloadFields.sponsor],['نوع الكفيل','#newSponsorType',payloadFields.type],['الهاتف','#newSponsorPhone',payloadFields.phone],['العنوان','#newSponsorAddress',payloadFields.address],['ملاحظات الكفيل','#newSponsorNotes',payloadFields.sponsorNotes]);
 const failed=[],populated=[];fields.forEach(([label,selector,value])=>{const el=$(selector);if(value===undefined||value===null||value===''){if(el)clear(el);return;}if(!set(el,String(value))||String(el.value)!==String(value))failed.push(label);else populated.push(label);});
 const unresolved=[];if(!record.entered||!choose($('#enteredBy'),record.entered)){clearPerson($('#enteredBy'));if(record.entered)unresolved.push('بواسطة: '+record.entered);}if(!record.supervisor||!choose($('#supervisorName'),record.supervisor)){clearPerson($('#supervisorName'));if(record.supervisor)unresolved.push('المشرف: '+record.supervisor);}
 set($('#departureNotes'),[payload.notes,...unresolved].filter(Boolean).join(' | '));return {matched,ambiguousSponsor,populated,failed,unresolved,missingDate:!record.date,missingLastPaid:!record.lastPaid};
}

function renderDepartureBatch(batchRecords) {
 const panel=$('#departureBatchPanel'),body=$('#departureBatchRows'),fieldset=$('#singleDepartureFields'),flag=$('#departureBatchFlag'),sourceSelect=$('#departureSponsor');
 if(!panel||!body||!fieldset||!flag||!sourceSelect)return false;
 body.replaceChildren();const name=(index,field)=>'departures['+index+']['+field+']';
 const makeField=(tag,type,fieldName,value,placeholder='')=>{const el=document.createElement(tag);if(tag==='input')el.type=type;el.name=fieldName;el.className='form-control form-control-sm';if(value!==undefined&&value!==null)el.value=String(value);if(placeholder)el.placeholder=placeholder;if(type==='number'){el.min='0';el.step='1';}return el;};
 batchRecords.forEach((record,index)=>{const payload=Parser.buildDepartureFormPayload(record),f=payload.fields,tr=document.createElement('tr'),cell=()=>{const td=document.createElement('td');tr.append(td);return td;};
  let td=cell();td.className='text-center fw-bold';td.textContent=String(index+1);td=cell();td.style.minWidth='220px';
  const mode=makeField('input','hidden',name(index,'sponsor_mode'),'existing'),sponsorId=makeField('input','hidden',name(index,'sponsor_id'),'');
  const sponsorName=makeField('input','text',name(index,'sponsor_name_snapshot'),f.sponsor,'اسم الكفيل كما ورد في الرسالة');sponsorName.maxLength=255;const hint=document.createElement('small');hint.className='text-muted d-block mt-1';td.append(mode,sponsorId,sponsorName,hint);
  const add=(value,field,kind='text')=>{const c=cell(),el=makeField('input',kind,name(index,field),value);c.append(el);return el;},fileInput=add(f.file,'file_number');
  const refresh=()=>{const result=resolveDepartureSponsor(sourceSelect,sponsorName.value,fileInput.value);sponsorId.value=result.id;hint.textContent=result.message||'';hint.hidden=!result.message;hint.classList.toggle('text-danger',!!result.message);hint.classList.toggle('text-muted',!result.message);};
  sponsorName.addEventListener('input',refresh);fileInput.addEventListener('input',refresh);refresh();
  add(f.date,'departure_date','date');add(f.lastPaid,'last_paid_month','month');add(f.count,'orphan_count','number');add(f.sponsorship,'sponsorship_amount','number');add(f.services,'services_amount','number');add(f.entered,'entered_by');add(f.supervisor,'supervisor_name');
  td=cell();td.style.minWidth='190px';const notes=makeField('textarea','',name(index,'notes'),payload.notes);notes.rows=2;td.append(notes);td=cell();const remove=document.createElement('button');remove.type='button';remove.className='btn btn-outline-danger btn-sm';remove.textContent='حذف';remove.addEventListener('click',()=>{tr.remove();[...body.rows].forEach((row,i)=>row.cells[0].textContent=String(i+1));});td.append(remove);body.append(tr);
 });
 if(!body.rows.length)return false;fieldset.disabled=true;fieldset.classList.add('d-none');panel.classList.remove('d-none');flag.value='1';const submit=$('#departureForm button[type="submit"]');if(submit)submit.textContent='حفظ جميع سجلات المغادرة';return true;
}

function leaveDepartureBatch(){
 const panel=$('#departureBatchPanel'),fieldset=$('#singleDepartureFields'),flag=$('#departureBatchFlag');
 if(!panel||!fieldset||!flag)return;
 panel.classList.add('d-none');fieldset.disabled=false;fieldset.classList.remove('d-none');flag.value='0';
 const submit=$('#departureForm button[type="submit"]');if(submit)submit.textContent='تسجيل المغادرة';
}

const singleDepartureName=$('#departureSponsorName'),singleDepartureId=$('#departureSponsorId'),singleDepartureFile=$('#existingFileNumber'),singleDepartureSource=$('#departureSponsor'),singleDepartureHint=$('#departureSponsorHint');
if(singleDepartureName&&singleDepartureId&&singleDepartureFile&&singleDepartureSource){const refresh=()=>{const result=resolveDepartureSponsor(singleDepartureSource,singleDepartureName.value,singleDepartureFile.value);singleDepartureId.value=result.id;if(singleDepartureHint){singleDepartureHint.textContent=result.message||'يمكن تعديل الاسم ورقم الملف لهذا السجل فقط؛ لن يتغير اسم الكفيل في القائمة الرئيسية.';singleDepartureHint.classList.toggle('text-danger',!!result.message);singleDepartureHint.classList.toggle('text-muted',!result.message);}};singleDepartureName.addEventListener('input',refresh);singleDepartureFile.addEventListener('input',refresh);refresh();}

const panel=$('[data-whatsapp-import]');
if(!panel)return;
const profile=panel.dataset.profile,input=$('#whatsappMessage',panel),analyze=$('#whatsappAnalyze',panel),select=$('#whatsappRecord',panel),apply=$('#whatsappApply',panel),applyAll=$('#whatsappApplyAll',panel),status=$('#whatsappStatus',panel),details=$('#whatsappDetails',panel);
let records=[],pendingIndex=null;
const storageKey='mdeWhatsappQueue:'+profile,pendingKey='mdeWhatsappPending:'+profile;
function hasRequiredFields(record) {
 if(profile==='entry')return !!record.sponsor&&Parser.hasMotherNames(record)&&record.orphans.length>0;
 return !!record.sponsor;
}
function render() {
 select.innerHTML='';
 records.forEach((record,index)=>{const option=document.createElement('option');option.value=String(index);option.textContent='سجل '+(index+1)+(record.sponsor?' — '+record.sponsor:' — اسم الكفيل غير واضح');select.append(option);});
 select.disabled=records.length<2;
 const record=records[Number(select.value)||0];
 if(!record){apply.disabled=true;details.textContent='';return;}
 const missing=profile==='entry'
  ?['اسم الكفيل','اسم أم واحدة على الأقل','اسم يتيم واحد على الأقل','تاريخ الانضمام (أدخله يدوياً بعد التحميل)'].filter((label,index)=>![record.sponsor,Parser.hasMotherNames(record),record.orphans.length,record.date][index])
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
 if(applyAll)applyAll.disabled=records.length<2;
 status.textContent=records.length>1?'تم العثور على '+records.length+' سجلات منفصلة. اختر سجلاً لتحميله في النموذج.':'';
});
select.addEventListener('change',render);

if(applyAll)applyAll.addEventListener('click',()=>{
 if(profile!=='departure'||records.length<2)return;
 if(records.some(record=>!record.sponsor)){status.textContent='تعذر تحميل الدفعة: يوجد سجل بلا اسم كفيل واضح. أصلح الرسالة قبل المتابعة.';return;}
 if(!window.confirm('سيتم تحميل '+records.length+' سجلات مغادرة في صفوف مستقلة قابلة للتعديل. لن يتم حفظها قبل ضغط زر الحفظ. هل تريد المتابعة؟'))return;
 if(renderDepartureBatch(records))status.textContent='تم تحميل '+records.length+' سجلات في جدول المراجعة. راجع كل صف ثم احفظ الدفعة مرة واحدة.';
});
const cancelBatch=$('#departureBatchCancel');
if(cancelBatch)cancelBatch.addEventListener('click',leaveDepartureBatch);

apply.addEventListener('click',()=>{
 const record=records[Number(select.value)||0];
 if(!record)return;
 if(!hasRequiredFields(record)){status.textContent='تعذر تحميل السجل بأمان. راجع البيانات الناقصة في التنبيهات وأصلح الرسالة قبل إعادة التحليل.';return;}
 let ambiguousDepartureSponsor=false;
 if(profile==='departure'){
  const matches=departureSponsorMatches(record.sponsor);
  ambiguousDepartureSponsor=matches.active.length+matches.inactive.length>1;
 }
 if(!window.confirm('سيتم تحميل البيانات المستخرجة في حقول النموذج. لن يتم حفظ أي بيانات. قد تُستبدل القيم الموجودة. هل تريد المتابعة؟'))return;
 let allocation,population=null;
 if(profile==='entry')allocation=applyEntry(record);
 else {population=applyDeparture(record,{ambiguousSponsor:ambiguousDepartureSponsor});allocation={sponsorshipManual:false,servicesManual:false};}
 pendingIndex=Number(select.value)||0;
 const manual=[];if(allocation.sponsorshipManual)manual.push('الكفالة');if(allocation.servicesManual)manual.push('الخدمات');
 if(profile==='departure'){
  const parts=[];
  if(population.populated.length)parts.push('تم تحميل: '+population.populated.join('، '));
  if(population.failed.length)parts.push('تعذر تعبئة: '+population.failed.join('، '));
  if(population.unresolved.length)parts.push('راجع الأسماء: '+population.unresolved.join('، '));
  if(population.missingDate)parts.push('تاريخ المغادرة غير موجود في الرسالة ويجب إدخاله يدوياً');
  if(population.missingLastPaid)parts.push('آخر سداد غير محسوم ويجب مراجعته يدوياً');
  if(population.ambiguousSponsor)parts.push('تم تحميل بقية البيانات؛ يوجد أكثر من سجل بهذا الاسم. اختر الكفيل الصحيح من القائمة قبل الحفظ، ولم يتم إنشاء كفيل جديد.');
  status.textContent=parts.join(' · ');
 } else {
  const parts=[];
  if(manual.length)parts.push('تعذر تقسيم إجمالي '+manual.join(' و')+' بالتساوي؛ أدخل التوزيع يدوياً وتأكد من مطابقة الإجمالي.');
  if(!record.date)parts.push('تاريخ الانضمام غير موجود في الرسالة؛ أدخله يدوياً قبل الحفظ.');
   status.textContent=parts.join(' · ');
 }
});
const targetForm=profile==='entry'?$('#entryForm'):document.querySelector('form.card');
if(targetForm)targetForm.addEventListener('submit',()=>{
 if(pendingIndex!==null&&records.length){try{sessionStorage.setItem(storageKey,JSON.stringify({records,raw:input.value}));sessionStorage.setItem(pendingKey,String(pendingIndex));}catch(error){status.textContent='تعذر الاحتفاظ ببقية السجلات في الجلسة؛ يمكنك إعادة لصق الرسالة بعد الحفظ.';}}
});
restoreQueue();
})();