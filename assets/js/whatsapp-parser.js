(() => {
'use strict';
const norm = v => String(v ?? '').replace(/[٠-٩]/g,d=>String('٠١٢٣٤٥٦٧٨٩'.indexOf(d))).replace(/[۰-۹]/g,d=>String('۰۱۲۳۴۵۶۷۸۹'.indexOf(d))).replace(/[أإآ]/g,'ا').replace(/ى/g,'ي').replace(/ة/g,'ه').replace(/\u0640/g,'').replace(/\s+/g,' ').trim().toLowerCase();
const clean = v => String(v ?? '').replace(/^[\s:：=؛;,،\-|]+|[\s:：=؛;,،\-|]+$/g,'').trim();
const stripEmoji = v => String(v ?? '').replace(/[\p{Extended_Pictographic}\u200d\ufe0f]/gu,'').replace(/\s+/g,' ').trim();
const esc = s => s.replace(/[.*+?^$()|[\]\\]/g,'\\$&');
const L = {
 sponsor:['اسم الكفيل','اسم الكفيلة','اسم الكافل','اسم الكافلة','الكفيل','الكفيلة','الكافل','الكافلة'],
 file:['رقم الملف','رقم ملف','ملف الكفيل','ملف'],
 mother:['اسم الأم','اسم الام','الأم','الام','اسم الوالدة','اسم الوالده','الوالدة','الوالده','والدة','والده'],
 orphan:['اسم اليتيم','اسم اليتيمة','اليتيم','اليتيمة','اسم الطفل','اسم الطفلة','الطفل','الطفلة','الأطفال','اطفال','الأيتام','الايتام','اليتامى','أيتام','ايتام'],
 sponsorship:['إجمالي الكفالة','اجمالي الكفالة','اجمالي الكفاله','مبلغ الكفالة','مبلغ الكفاله','قيمة الكفالة','قيمة الكفاله','الكفالة','الكفاله','كفالة','كفاله','المبلغ'],
 services:['إجمالي الخدمات','اجمالي الخدمات','مبلغ الخدمات','قيمة الخدمات','الخدمات','خدمات'],
 date:['تاريخ الانضمام','تاريخ البداية','بتاريخ','التاريخ'],
 departureDate:['تاريخ المغادرة','تاريخ الانسحاب','تاريخ التوقف','بتاريخ','التاريخ'],
 entered:['إدخال بواسطة','ادخال بواسطة','بواسطة','عن طريق'],
 supervisor:['مشرفه ح','مشرفة ح','المشرف','مشرفه','مشرفة','مشرف'],
 each:['لكل يتيمه','لكل يتيمة','لكل يتيم'],
 notes:['ملاحظات عن الكفيل','ملاحظات الكفيل','ملاحظات','ملاحظة'],
 extra:['أي مبلغ إضافي','اي مبلغ اضافي'], contact:['التواصل عن طريق'],
 lastPaid:['آخر شهر سداد','اخر شهر سداد','آخر سداد','اخر سداد','آخر شهر'],
 count:['عدد الأيتام','عدد الايتام','عدد اليتامى','عدد يتيم'],
 reason:['سبب المغادرة','سبب الانسحاب','السبب'], phone:['رقم الهاتف','الهاتف','جوال','موبايل'], address:['العنوان','السكن']
};
const SPECIAL=[['مقدم كفالة سنة','مقدم كفالة سنه'],['كرتونه رمضان','كرتونة رمضان'],['فرحة عيدين','فرحه عيدين'],['متبقي المبلغ']];
const ALL=[...new Set(Object.values(L).flat().concat(SPECIAL.flat()))].sort((a,b)=>b.length-a.length);
const stop=ALL.map(esc).join('|');
const end='(?=\\s+(?:\\d+\\s*[/._)-]\\s*)?(?:'+stop+')\\s*(?:[:=：-]|\\s)|[؛;\\n|]|$)';
function value(t,labels){const alts=labels.map(x=>x.includes('\\s')?x:esc(x)).join('|');const m=String(t??'').match(new RegExp('(?:^|[؛;\\n|\\s])\\s*(?:'+alts+')(?:\\s*[:=：-]\\s*|\\s+|(?=[0-9٠-٩۰-۹]))([^؛;\\n|]+?)'+end,'iu'));return m?clean(m[1]):'';}
function values(t,labels){const alts=labels.map(x=>x.includes('\\s')?x:esc(x)).join('|');const re=new RegExp('(?:^|[؛;\\n|\\s])\\s*(?:\\d+\\s*[/._)-]\\s*)?(?:'+alts+')(?:\\s*[:=：-]\\s*|\\s+)([^؛;\\n|]+?)'+end,'giu');return [...String(t??'').matchAll(re)].map(m=>clean(m[1])).filter(Boolean);}
function noteValue(t,labels){const alts=labels.map(esc).join('|');const m=String(t??'').match(new RegExp('(?:^|[؛;\\n|\\s])\\s*(?:'+alts+')[ \\t]*(?:[:=：-][ \\t]*)?([^؛;\\n|]+?)'+end,'iu'));return m?clean(m[1]):'';}
function fileValue(t){return value(t,L.file)||((norm(t).match(/(?:رجعت\s+ل)?ملف(?:ها)?\s*[:=：-]?\s*(\d+)/u)||[])[1]||'');}
function num(v){const s=norm(v).replace(/[٬,،.\s]/g,'').replace(/[^\d]/g,'');return s?String(Number(s)):'';}
function leadingNum(t,label){for(const line of String(t??'').split(/\r?\n/)){const m=norm(line).match(new RegExp('(?:^|[\\s؛;|])((?:\\d{1,3}(?:[ ,،٬.]+\\d{3})+)|\\d+)\\s*(?:'+label+')(?![\\u0621-\\u064a])','u'));if(m)return num(m[1]);}return'';}
function isField(line){const n=norm(line);return !n||ALL.some(x=>n===norm(x)||n.startsWith(norm(x)+' ')||n.startsWith(norm(x)+':'));}
function looksName(line){const s=clean(stripEmoji(line));return !/[\d٠-٩۰-۹،,؛;:|]/u.test(s)&&/^[\u0600-\u06ff\u0750-\u077f\s]+$/u.test(s)&&s.split(/\s+/).filter(Boolean).length>=2&&s.split(/\s+/).length<=6;}
function orphanValues(t,label){const out=[];let continuing=false;for(const raw of String(t??'').split(/\r?\n/)){const line=raw.trim();if(!line)continue;const found=values(line,[label]);if(found.length){out.push(...found.flatMap(v=>v.split(/\s*(?:،|,)\s*/).map(clean).filter(Boolean)));continuing=true;continue;}if(continuing&&!isField(line)&&looksName(line)){out.push(clean(stripEmoji(line)));continue;}continuing=false;}return out;}
function families(t){const ml='اسم\\s+الأم|اسم\\s+الام|الأم|الام|اسم\\s+الوالدة|اسم\\s+الوالده|الوالدة|الوالده|والدة|والده';const ol='اسم\\s+اليتيم(?:ة)?|اليتيم(?:ة)?|اسم\\s+الطفل(?:ة)?|الطفل(?:ة)?|الأطفال|اطفال|الأيتام|الايتام|اليتامى|أيتام|ايتام';const re=new RegExp('(?:^|[؛;\\n|\\s])\\s*(?:'+ml+')(?:\\s*[:=：-]\\s*|\\s+)','giu');const ms=[...String(t??'').matchAll(re)];if(!ms.length)return[{mother:'',orphans:orphanValues(t,ol)}];const before=orphanValues(String(t).slice(0,ms[0].index),ol);const after=orphanValues(String(t).slice(ms[ms.length-1].index),ol);const orphanFirst=before.length>0&&after.length===0;return ms.map((m,i)=>{const own=String(t).slice(m.index,i+1<ms.length?ms[i+1].index:String(t).length);const source=orphanFirst?String(t).slice(i===0?0:ms[i-1].index,m.index):String(t).slice(i===0?0:m.index,i+1<ms.length?ms[i+1].index:String(t).length);return{mother:value(own,L.mother),orphans:orphanValues(source,ol)};});}
function sponsor(t){const labelled=value(t,L.sponsor);if(labelled)return stripEmoji(labelled);for(const raw of String(t??'').split(/\r?\n/)){const line=clean(stripEmoji(raw));if(!line)continue;const n=norm(line);if(/^(السلام عليكم|السلام عليكم ورحمة الله وبركاته|مرحبا|تحية طيبة|صباح الخير|مساء الخير)$/u.test(n))continue;if(isField(line)||/^(الايتام|الأيتام|الام|الأم|اليتيم|الطفل)\b/u.test(n))return'';return looksName(line)?line:'';}return'';}
function unrecognizedLines(t){return String(t??'').split(/\r?\n/).map(clean).filter(line=>{if(!line||!stripEmoji(line))return false;const n=norm(line);if(looksName(line))return false;if(/^(السلام عليكم|السلام عليكم ورحمة الله وبركاته|مرحبا|تحية طيبة|صباح الخير|مساء الخير)$/u.test(n))return false;if(ALL.some(label=>n.includes(norm(label))))return false;if(/^\d[\d,،٬. ]*\s*خدمات/u.test(n))return false;return true;});}
function contextYear(c){const m=String(c??'').match(/^(\d{4})-\d{2}-\d{2}$/);return m?Number(m[1]):0;}
function parseDate(v,c){if(!v)return'';const s=norm(v).replace(/[.]/g,'/').replace(/\s+/g,'');let d,m,y,x=s.match(/^(\d{1,2})[/-](\d{1,2})(?:[/-](\d{4}))?$/);if(x){d=+x[1];m=+x[2];y=x[3]?+x[3]:contextYear(c);}else if((x=s.match(/^(\d{4})-(\d{1,2})-(\d{1,2})$/))){y=+x[1];m=+x[2];d=+x[3];}else if((x=s.match(/^(\d{1,2})(يناير|فبراير|مارس|ابريل|مايو|يونيو|يوليو|اغسطس|سبتمبر|اكتوبر|نوفمبر|ديسمبر)(\d{4})?$/u))){d=+x[1];m={يناير:1,فبراير:2,مارس:3,ابريل:4,مايو:5,يونيو:6,يوليو:7,اغسطس:8,سبتمبر:9,اكتوبر:10,نوفمبر:11,ديسمبر:12}[x[2]];y=x[3]?+x[3]:contextYear(c);}else return'';if(!y||m<1||m>12||d<1||d>31)return'';const dt=new Date(Date.UTC(y,m-1,d));return dt.getUTCFullYear()===y&&dt.getUTCMonth()===m-1&&dt.getUTCDate()===d?String(y).padStart(4,'0')+'-'+String(m).padStart(2,'0')+'-'+String(d).padStart(2,'0'):'';}
function month(v){if(!v)return'';const s=norm(v).replace(/\s+/g,'');let m=s.match(/^(\d{1,2})[/-](\d{4})$/);if(m)return+m[1]>=1&&+m[1]<=12?m[2]+'-'+String(+m[1]).padStart(2,'0'):'';m=s.match(/^(\d{4})-(\d{1,2})$/);if(m)return+m[2]>=1&&+m[2]<=12?m[1]+'-'+String(+m[2]).padStart(2,'0'):'';const months={يناير:'01',فبراير:'02',مارس:'03',ابريل:'04',مايو:'05',يونيو:'06',يوليو:'07',اغسطس:'08',سبتمبر:'09',اكتوبر:'10',نوفمبر:'11',ديسمبر:'12'};const found=Object.entries(months).filter(([name])=>s.includes(name));if(found.length===1){const y=s.match(/(20\d{2})/);return y?y[1]+'-'+found[0][1]:'';}return'';}
function monthNameDate(t,c){const s=norm(t).replace(/\s+/g,'');const m=s.match(/(?:بتاريخ)?(\d{1,2})(يناير|فبراير|مارس|ابريل|مايو|يونيو|يوليو|اغسطس|سبتمبر|اكتوبر|نوفمبر|ديسمبر)(\d{4})?/u);return m?parseDate(m[1]+m[2]+(m[3]||''),c):'';}
function supervisors(t){return values(t,['مشرفه\\s+ح','مشرفة\\s+ح','المشرف','مشرفه','مشرفة','مشرف']).map(v=>clean(v.replace(/^ح\s+/u,''))).filter(Boolean);}
function supervisor(t){const a=supervisors(t);return a.length?a[a.length-1]:'';}
function split(t){const re=/(?:^|[؛;\n|])\s*(?:الكفيل(?:ة)?|الكافل(?:ة)?|اسم\s+الكفيل(?:ة)?|اسم\s+الكافل(?:ة)?)(?:\s*[:=：-]\s*|\s+)/giu;const ms=[...String(t??'').matchAll(re)];if(ms.length<2)return[String(t??'').trim()];return ms.map((m,i)=>String(t).slice(m.index,i+1<ms.length?ms[i+1].index:String(t).length).replace(/^[؛;\n|\s]+|[؛;\n|\s]+$/g,'').trim()).filter(Boolean);}
function allocateEvenly(total,count){if(total===''||count<1)return null;const n=Number(total);return Number.isSafeInteger(n)&&n>=0&&n%count===0?Array(count).fill(String(n/count)):null;}
function entry(t,i,c){const fs=families(t),methods=values(t,L.entered),contact=value(t,L.contact),extra=value(t,L.extra),notes=[value(t,L.notes),((String(t).match(/(?:^|\n)[ \t]*(تواصل[ \t][^\n]+)/)||[])[1]||'').trim(),contact?'التواصل عن طريق: '+contact:'',extra?'أي مبلغ إضافي '+extra:'',...SPECIAL.map(([a,...b])=>{const v=noteValue(t,[a,...b]);return v?a+': '+v:([a,...b].some(x=>norm(t).includes(norm(x)))?a:'');})].filter(Boolean);const dt=parseDate(value(t,L.date),c)||monthNameDate(t,c);const r={i,sponsor:sponsor(t),file:fileValue(t),families:fs,mother:fs[0]?.mother||'',orphans:fs.flatMap(f=>f.orphans),sponsorship:num(value(t,L.sponsorship)),services:num(value(t,L.services))||leadingNum(t,'خدمات'),each:num(value(t,L.each)),date:dt,entered:methods.at(-1)||'',supervisor:supervisor(t),notes:notes.join(' | '),type:/🌳|شجرة/i.test(t)?'tree':'',raw:String(t??''),unrecognizedLines:unrecognizedLines(t)};r.warnings=[];if(!r.sponsor)r.warnings.push('اسم الكفيل غير واضح');if(!fs.length||fs.some(f=>!f.mother))r.warnings.push('اسم الأم غير واضح لإحدى الأسر');if(!r.orphans.length)r.warnings.push('لم يتم استخراج أسماء الأيتام');if(!r.date)r.warnings.push('التاريخ غير محسوم؛ يلزم سياق سنة صالح أو تاريخ كامل');if(r.unrecognizedLines.length)r.warnings.push('أسطر غير مصنفة وتحتاج مراجعة: '+r.unrecognizedLines.join(' / '));return r;}

function departureHeader(line){
 const n=norm(line).replace(/[⛔*_]/gu,'').trim();
 return !n||/^(?:قائمة\s+)?(?:الغاء|إلغاء)\s+(?:الكفاله|الكفالة|كفاله|كفالة)(?:\s+.*)?$/u.test(n)||/^(?:قائمة\s+)?كفلاء\s+(?:طلعو|خرجوا|غادروا)(?:\s+.*)?$/u.test(n)||/^(?:السلام عليكم|السلام عليكم ورحمه الله وبركاته)$/u.test(n)||/^قائمه\s+الغاء\s+.+$/u.test(n);
}
function departureFileHeading(line){
 const n=norm(line);
 if(/ملف\s*(?:الشجره|شجره)/u.test(n))return'🌳';
 if(/^ملف\s+حرف\b/u.test(n))return'__group__';
 const m=n.match(/^(?:رقم\s+)?ملف(?:\s+رقم)?\s*[:：=-]?\s*(\d{1,4})$/u);
 return m?m[1]:null;
}
function departureNoteLine(line){return /^(?:ماموجوده?\s+في\s+القروب|ما?موجوده?\s+ف\s+القروب|طلعت\s+من\s+القروب|طلعت\s+بدون\s+عجز|مسدده\s+(?:الى|الي)\s+شهر)/u.test(norm(line));}
function departureNameLine(line){
 if(departureNoteLine(line))return'';
 const cleanLine=clean(stripEmoji(line).replace(/^\s*\d+\s*[/／•.)_-]\s*/u,''));
 if(!looksName(cleanLine)||departureHeader(cleanLine))return'';
 if(/^(?:ملف|عدد|قيمة|مبلغ|الكفاله|الكفالة|كفاله|كفالة|الخدمات|خدمات|اخر سداد|آخر سداد|بتاريخ|تاريخ|سبب|ملاحظات|عن طريق|بواسطه|مشرف)/u.test(norm(cleanLine)))return'';
 return cleanLine;
}
function splitDepartures(raw,contextDate){
 const lines=String(raw??'').replace(/\r/g,'').split('\n');
 const records=[];let currentFile='',currentLines=[],currentTree=false;
 const numberedPattern=/^\s*\d{1,3}\s*[/／•.)_-]\s*(.*?)\s*$/u;
 const nextMeaningful=index=>{for(let j=index+1;j<lines.length;j++){const value=lines[j].trim();if(value)return value;}return'';};
 const flush=()=>{const body=currentLines.join('\n').trim();if(!body)return;const record=departure(body,records.length,contextDate);if(!record.file)record.file=currentFile;if(currentTree||currentFile==='🌳'){record.type='tree';if(record.count==='')record.count='0';if(record.sponsorship===''){const amount=body.match(/(?:^|\n)\s*([\d٠-٩۰-۹][\d٠-٩۰-۹.,،٬ ]*)\s*(?:جنيه)?\s*(?:$|\n)/u);if(amount){record.sponsorship=num(amount[1]);record.notes=String(record.notes||'').split(' | ').filter(note=>note!==amount[1].trim()).join(' | ');record.unrecognizedLines=(record.unrecognizedLines||[]).filter(line=>line!==amount[1].trim());record.warnings=(record.warnings||[]).filter(w=>!w.startsWith('أسطر غير مصنفة'));}record.warnings=(record.warnings||[]).filter(w=>!w.startsWith('عدد الأيتام غير واضح'));}if(record.services==='')record.services='0';}records.push(record);currentLines=[];};
 for(let i=0;i<lines.length;i++){
  const line=lines[i].trim();if(!line)continue;
  const heading=departureFileHeading(line);
  if(heading==='__group__')continue;
  if(heading!==null){
   const next=nextMeaningful(i),nextIsRecord=numberedPattern.test(next)||departureNameLine(next)!=='';
   if(currentLines.length&&nextIsRecord){flush();currentFile=heading;currentTree=heading==='🌳';}
   else if(currentLines.length){currentLines.push(line);}
   else{currentFile=heading;currentTree=heading==='🌳';}
   continue;
  }
  if(departureHeader(line))continue;
  const numbered=line.match(numberedPattern);
  if(numbered){flush();currentLines=[numbered[1]];continue;}
  if((currentTree||/🌳/u.test(line))&&departureNameLine(line)){flush();currentLines=[line];continue;}
  if(!currentLines.length){const name=departureNameLine(line);if(name){currentLines=[line];continue;}continue;}
  currentLines.push(line);
 }
 flush();
 if(!records.length){const fallback=lines.filter(line=>!departureHeader(line)&&departureFileHeading(line)===null).join('\n');if(fallback.trim()){const record=departure(fallback,0,contextDate);if(!record.file)record.file=currentFile;records.push(record);}}
 return records;
}

function departureFileValue(t){
 const text=String(t??'');
 const returned=text.match(/رجعت\s+لملفها\s*[:=：-]?\s*(\d{1,4})/u);
 if(returned)return returned[1];
 const labelled=value(text,L.file);if(labelled)return clean(labelled);
 for(const line of text.split(/\r?\n/)){const match=norm(line).match(/^(?:رقم\s+)?ملف(?:\s+رقم)?\s*[:：=-]?\s*(\d{1,4})$/u);if(match)return match[1];}
 return'';
}
function monthWithContext(v,c){
 const direct=month(v);if(direct)return direct;
 const s=norm(v).replace(/\s+/g,'');
 const names={يناير:1,فبراير:2,مارس:3,ابريل:4,مايو:5,يونيو:6,يوليو:7,اغسطس:8,سبتمبر:9,اكتوبر:10,نوفمبر:11,ديسمبر:12};
 const found=Object.entries(names).filter(([name])=>s.includes(name));
 if(found.length!==1)return'';
 const year=contextYear(c);return year?String(year)+'-'+String(found[0][1]).padStart(2,'0'):'';
}
function departureResidualNotes(t){
 const lines=String(t??'').split(/\r?\n/).map(clean).filter(Boolean);let sponsorRemoved=false;
 return lines.filter(line=>{
  if(departureHeader(line)||departureFileHeading(line)!==null)return false;
  if(/^\d{1,3}\s*[•.)/_-]/u.test(line))return false;
  if(!sponsorRemoved&&departureNameLine(line)){sponsorRemoved=true;return false;}
  const n=norm(line);
  if(ALL.some(label=>n.startsWith(norm(label)))||/^\d[\d,،٬. ]*\s*خدمات/u.test(n))return false;
  if(/^(?:🌳|شجره)$/u.test(stripEmoji(line)))return false;
  return true;
 }).join(' | ');
}
function departure(t,i,c){
 const sponsorshipLabel=value(t,L.sponsorship),servicesLabel=value(t,L.services);
 const pair=String(t??'').match(/(?:المبلغ|قيمة\s+الكفاله|قيمة\s+الكفالة|مبلغ\s+الكفاله|مبلغ\s+الكفالة)\s*[:=：-]?\s*([\d٠-٩۰-۹][\d٠-٩۰-۹.,،٬ ]*)\s*\/\s*([\d٠-٩۰-۹][\d٠-٩۰-۹.,،٬ ]*)(?!\s*\/)/u);
 const residual=departureResidualNotes(t),explicitNotes=value(t,L.notes);
 const r={i,sponsor:sponsor(t),file:departureFileValue(t),date:parseDate(value(t,L.departureDate),c)||monthNameDate(t,c),lastPaid:monthWithContext(value(t,L.lastPaid),c),count:num(value(t,L.count)),sponsorship:num(sponsorshipLabel||(pair?pair[1]:'')),services:num(servicesLabel||(pair?pair[2]:''))||leadingNum(t,'خدمات'),entered:values(t,L.entered).at(-1)||'',supervisor:supervisor(t),reason:value(t,L.reason),notes:[explicitNotes,residual].filter(Boolean).join(' | '),type:/🌳|شجرة/i.test(t)?'tree':'individual',phone:value(t,L.phone),address:value(t,L.address),sponsorNotes:value(t,['ملاحظات عن الكفيل','ملاحظات الكفيل']),raw:String(t??''),unrecognizedLines:unrecognizedLines(t)};
 r.warnings=[];if(!r.sponsor)r.warnings.push('اسم الكفيل غير واضح');if(!r.date)r.warnings.push('تاريخ المغادرة غير محسوم');if(r.count==='')r.warnings.push('عدد الأيتام غير واضح');if(!r.lastPaid&&/اخر\s+سداد|آخر\s+سداد/u.test(norm(t)))r.warnings.push('آخر سداد غير محسوم؛ راجع الشهر يدوياً');if(r.unrecognizedLines.length)r.warnings.push('أسطر غير مصنفة وتحتاج مراجعة: '+r.unrecognizedLines.join(' / '));return r;
}
function hasMotherNames(r){return!!(r&&Array.isArray(r.families)&&r.families.length&&r.families.every(f=>String(f.mother||'').trim()!==''));}
function applyMessageLevelFields(records,raw){if(split(raw).length<2)return records;const tail=String(raw??'').split(/\r?\n/).map(x=>x.trim()).filter(Boolean).slice(-3).join('\n');const allEntered=values(raw,L.entered),allSupervisors=supervisors(raw);const tailEntered=values(tail,L.entered),tailSupervisors=supervisors(tail);const e=allEntered.length===1&&tailEntered.length===1?allEntered[0]:'';const s=allSupervisors.length===1&&tailSupervisors.length===1?allSupervisors[0]:'';return records.map(r=>({...r,entered:e||r.entered,supervisor:s||r.supervisor}));}
function buildEntryFormPayload(r){const fs=(r.families||[]).map(f=>({mother:String(f.mother||''),orphans:Array.isArray(f.orphans)?f.orphans.map(String):[]}));const count=fs.reduce((n,f)=>n+f.orphans.length,0);const each=!!r.each&&count>0&&(r.sponsorship===''||Number(r.sponsorship)===Number(r.each)*count);const sa=each?Array(count).fill(String(r.each)):allocateEvenly(r.sponsorship,count),va=allocateEvenly(r.services,count);return{fields:{sponsor:String(r.sponsor||''),file:String(r.file||''),type:String(r.type||''),date:String(r.date||''),entered:String(r.entered||''),supervisor:String(r.supervisor||''),notes:String(r.notes||'')},families:fs,orphanCount:count,expectedSponsorship:String(r.sponsorship||''),expectedServices:String(r.services||''),sponsorshipAllocation:sa,servicesAllocation:va,sponsorshipManual:r.sponsorship!==''&&!sa,servicesManual:r.services!==''&&!va};}
function populateEntryForm(p,a){for(const [k,v]of Object.entries(p.fields)){if(k!=='notes')a.setField(k,v);}const notes=[p.fields.notes];if(p.fields.entered&&a.fieldWasSet&&!a.fieldWasSet('entered'))notes.push('بواسطة: '+p.fields.entered);if(p.fields.supervisor&&a.fieldWasSet&&!a.fieldWasSet('supervisor'))notes.push('المشرف: '+p.fields.supervisor);a.setField('notes',notes.filter(Boolean).join(' | '));a.setFamilies(p.families);a.setAmounts({sponsorshipAllocation:p.sponsorshipAllocation,servicesAllocation:p.servicesAllocation,orphanCount:p.orphanCount,expectedSponsorship:p.expectedSponsorship,expectedServices:p.expectedServices,sponsorshipManual:p.sponsorshipManual,servicesManual:p.servicesManual});if(a.updateTotals)a.updateTotals();return{sponsorshipManual:p.sponsorshipManual,servicesManual:p.servicesManual};}
window.MDEWhatsAppParser={split,splitDepartures,entry,departure,hasMotherNames,allocateEvenly,applyMessageLevelFields,parseDate,buildEntryFormPayload,populateEntryForm};
})();
