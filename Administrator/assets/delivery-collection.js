(function(){
'use strict';

// Shared page state and helpers.
const endpoint = '../Ajax/ajax_delivery_collection.php';
const id = (elementId) => document.getElementById(elementId);

let gps = window.collectionLocationRequired === false ? { latitude: 0, longitude: 0 } : null;
let searchTimer;
let selectedSearchCustomer = null;
let collectionInRange = false;
let collectionAccessGranted = false;
let locationConfirmedForDelivery = false;
let mapFitTarget = null;

// Resolved on demand (not once at script-parse time) so it never depends on
// whether this script tag happens to load before or after the #moduleName
// hidden input exists in the DOM.
function moduleContext() {
    // Prefer detecting the module from elements that only exist on their own
    // portal page (delivery_portal.php has #viewDeliveryDetails, collection_portal.php
    // has #viewCollectionDetails). This can't be fooled by a stale cached script or a
    // duplicate #moduleName element elsewhere on the page -- only the real markup counts.
    const module = document.getElementById('viewDeliveryDetails')
        ? 'delivery'
        : document.getElementById('viewCollectionDetails')
            ? 'collection'
            : (id('moduleName')?.value === 'delivery' ? 'delivery' : 'collection');
    return {
        module,
        rangeNoticeId: module === 'delivery' ? 'deliveryRangeNotice' : 'collectionRangeNotice',
        detailsPanelId: module === 'delivery' ? 'deliveryDetails' : 'collectionDetails',
        proceedButtonId: module === 'delivery' ? 'viewDeliveryDetails' : 'viewCollectionDetails',
    };
}

async function post(action, data) {
    const body = data instanceof FormData ? data : new URLSearchParams(data || {});
    body.set('action', action);
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
    if (csrfToken) body.set('csrf_token', csrfToken);

    const response = await fetch(endpoint, { method: 'POST', body, credentials: 'same-origin' });
    const json = await response.json();

    if (response.status === 401) {
        location.href = '../';
        throw Error('Please sign in again.');
    }

    if (!response.ok || !json.success) {
        throw Error(json.message || 'Request failed.');
    }

    return json;
}

function notice(text, type) {
    const box = id('portalMessage');
    if (!box) return;

    const isCollectionException = text === 'This customer is unlocked for collection viewing.'
        || text.includes('Location lock is disabled')
        || (window.collectionLocationRequired === false && text === 'Location access is optional for this customer.');
    if (isCollectionException) {
        const rangeNotice = id(moduleContext().rangeNoticeId);
        if (rangeNotice) {
            rangeNotice.textContent = window.collectionAccessMessage || text;
            rangeNotice.className = 'notice success';
            return;
        }
    }

    box.textContent = text;
    box.className = `notice ${type || 'info'}`;
    box.classList.remove('dc-hidden');
}

function openCustomer() {
    if (!selectedSearchCustomer) {
        return notice('Select a customer from the search results first.', 'error');
    }

    location.href = `?page=${encodeURIComponent(id('pageToken').value)}&customer=${encodeURIComponent(selectedSearchCustomer.code)}`;
}

function search(query) {
    const list = id('customerResults');
    const hint = id('customerSearchHint');
    clearTimeout(searchTimer);

    if (query.length < 2) {
        list.replaceChildren();
        list.classList.add('dc-hidden');
        hint.textContent = 'Type at least 2 characters to search.';
        selectedSearchCustomer = null;
        return;
    }

    searchTimer = setTimeout(async () => {
        try {
            const data = await post('customers', { q: query, module: moduleContext().module });
            list.replaceChildren(...data.items.map((customer) => customerResult(customer)));

            list.classList.toggle('dc-hidden', !data.items.length);
            hint.textContent = data.items.length
                ? `${data.items.length} matching customer(s). Select one.`
                : 'No matching Customer ID or Customer Name.';

            selectedSearchCustomer = null;
        } catch (error) {
            notice(error.message, 'error');
        }
    }, 250);
}

function customerResult(customer) {
    const result = document.createElement('button');
    result.type = 'button';
    result.className = 'customer-result';
    result.setAttribute('role', 'option');

    const name = document.createElement('strong');
    name.textContent = customer.name;
    const code = document.createElement('span');
    code.textContent = customer.code;
    result.append(name, code);

    result.addEventListener('click', () => {
        selectedSearchCustomer = customer;
        id('customerSearch').value = customer.name;
        id('customerResults').classList.add('dc-hidden');
        id('customerSearchHint').textContent = `${customer.name} selected.`;
    });

    return result;
}
function initMap(){
    const n=id('dcMap'),lat=Number(n?.dataset.lat),lng=Number(n?.dataset.lng); if(!n||!L||!lat||!lng)return;
    const m=L.map(n).setView([lat,lng],17); window.deliveryCollectionMap=m;
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{attribution:'OpenStreetMap'}).addTo(m);
    const customerName=n.dataset.customer||'Customer',salesmanName=n.dataset.salesman||'Salesman',radius=Number(n.dataset.radius||5);
    L.marker([lat,lng]).addTo(m).bindPopup(`Customer: ${customerName}`).openPopup();
    L.circle([lat,lng],{radius,color:'#198754',fillColor:'#198754',fillOpacity:.08,weight:2}).addTo(m);
    navigator.geolocation?.getCurrentPosition(async p=>{
        const here=[p.coords.latitude,p.coords.longitude]; gps={latitude:here[0],longitude:here[1]};
        L.marker(here).addTo(m).bindPopup(`Salesman: ${salesmanName}`);
        const a=Math.PI/180,h=Math.sin((lat-here[0])*a/2)**2+Math.cos(here[0]*a)*Math.cos(lat*a)*Math.sin((lng-here[1])*a/2)**2,d=2*6371000*Math.atan2(Math.sqrt(h),Math.sqrt(1-h)),ok=d<=radius,allowed=window.collectionLocationRequired===false||ok;
        updateCollectionRange(d,allowed,radius,ok); if(id('confirmLocation')) id('confirmLocation').disabled=!ok;
        id('directionsLink').href=`https://www.google.com/maps/dir/?api=1&origin=${here[0]},${here[1]}&destination=${lat},${lng}`;
        try {
            const response=await fetch(`https://router.project-osrm.org/route/v1/driving/${here[1]},${here[0]};${lng},${lat}?overview=full&geometries=geojson`),route=await response.json();
            if(route.routes?.[0]?.geometry?.coordinates){const points=route.routes[0].geometry.coordinates.map(v=>[v[1],v[0]]),line=L.polyline(points,{color:'#0d6efd',weight:5,opacity:.8}).addTo(m);mapFitTarget=line.getBounds();m.fitBounds(mapFitTarget,{padding:[40,40]});return;}
        } catch(error) { console.warn('Road route unavailable.',error); }
        mapFitTarget=[here,[lat,lng]];
        m.fitBounds(mapFitTarget,{padding:[40,40]});
    },()=>{const allowed=window.collectionLocationRequired===false;notice(allowed?'Location access is optional for this customer.':'Location permission is required to check the customer range.',allowed?'info':'error');updateCollectionRange(null,allowed,radius,false);},{enableHighAccuracy:true,timeout:12000});
}
function updateCollectionRange(distance, allowed, radius) {
    collectionInRange = Boolean(allowed);

    const { module: currentModule, rangeNoticeId, detailsPanelId, proceedButtonId } = moduleContext();

    const distanceText = distance === null ? 'Waiting for GPS' : `${distance.toFixed(1)} m`;
    const hasLocationException = window.collectionLocationRequired === false;
    const exceptionMessage = window.collectionAccessMessage
        || `Location restriction is disabled for this customer. You can now view the ${currentModule} details.`;
    const message = !allowed
        ? 'Customer is out of range'
        : hasLocationException
            ? exceptionMessage
            : `You are within the allowed range. You can now view the ${currentModule} details.`;

    ['distanceMeters'].forEach((key) => {
        if (id(key)) id(key).textContent = distanceText;
    });

    ['rangeState'].forEach((key) => {
        if (!id(key)) return;
        id(key).textContent = message;
        id(key).style.color = allowed ? '#146c43' : '#842029';
    });

    const rangeNotice = id(rangeNoticeId);
    if (rangeNotice) {
        rangeNotice.textContent = allowed && hasLocationException
            ? exceptionMessage
            : allowed
                ? `Allowed radius: ${radius} m. Your distance from the customer: ${distanceText}. You can now view the ${currentModule} details.`
                : `Allowed radius: ${radius} m. Your distance from the customer: ${distanceText}. Customer is out of range.`;
        rangeNotice.className = `notice ${allowed ? 'success' : 'error'}`;
    }

    id(proceedButtonId)?.classList.toggle('dc-hidden', !allowed);
    if (!allowed) id(detailsPanelId)?.classList.add('dc-hidden');
}
function paymentRow(){const tr=document.createElement('tr');tr.innerHTML=`<td><select class="input payment-type"><option>Cash</option><option>PDC</option></select></td><td><input class="input payment-bank" disabled></td><td><input class="input payment-check" disabled></td><td><input class="payment-file" type="file" accept="image/jpeg,image/png,image/gif,image/webp" disabled></td><td><input class="input payment-amount" type="number" min="0" step=".01" value="0"></td><td><button class="btn btn-red remove-row"><i class="fa-solid fa-trash" aria-hidden="true"></i> Remove</button></td>`;tr.querySelector('.payment-type').addEventListener('change',e=>{const enabled=e.target.value==='PDC';tr.querySelectorAll('.payment-bank,.payment-check,.payment-file').forEach(field=>{field.disabled=!enabled;if(!enabled&&field.type!=='file')field.value='';});if(!enabled)tr.querySelector('.payment-file').value='';});id('paymentRows').append(tr);}
function splitRow(){const options=(window.collectionCategories||[]).map(c=>`<option value="${c.catid}" data-required="${c.RequiresAttachment?1:0}">${c.CategoryName}${c.RequiresAttachment?' *':''}</option>`).join('');const tr=document.createElement('tr');tr.innerHTML=`<td><select class="input split-category"><option value="">Select category</option>${options}</select></td><td><input class="input split-amount" type="number" min="0" step=".01" value="0"></td><td><input class="input split-reference"></td><td><input class="split-file" type="file" accept="image/jpeg,image/png,image/gif,image/webp"></td><td><button class="btn btn-red remove-row"><i class="fa-solid fa-trash" aria-hidden="true"></i> Remove</button></td>`;id('splitRows').append(tr);}
function money(value){return Number(value||0).toFixed(2);}
function selectedInvoices(){return [...id('invoiceRows').querySelectorAll('tr[data-invoice]')].filter(row=>row.querySelector('.invoice-select')?.checked).map(row=>{const manual=row.dataset.manual==='1';return{invoice_no:manual?row.querySelector('.manual-invoice-number').value.trim():row.dataset.invoice,amount:manual?Number(row.querySelector('.manual-invoice-amount').value||0):Number(row.dataset.amount),manual};});}
function refreshInvoiceTotal(){if(!id('invoiceBalance'))return;id('invoiceBalance').value=selectedInvoices().reduce((total,invoice)=>total+invoice.amount,0);summary();updateCollectionTotals();}
let invoiceSearchTimer;
function searchInvoices(query) {
    const list = id('invoiceResults');
    const hint = id('invoiceSearchHint');
    clearTimeout(invoiceSearchTimer);

    if (query.length < 2) {
        list.replaceChildren();
        list.classList.add('dc-hidden');
        hint.textContent = 'Enter an invoice number, then press Enter or select Add invoice.';
        return;
    }

    invoiceSearchTimer = setTimeout(async () => {
        try {
            const data = await post('collection_invoices', { customer: id('selectedCustomer').value, q: query });
            if (data.already_collected) {
                list.replaceChildren();
                list.classList.add('dc-hidden');
                hint.textContent = `Invoice ${query} has already been collected.`;
                return;
            }
            const available = data.items.filter((item) => !Number(item.AlreadyCollected));
            list.replaceChildren(...available.map((item) => invoiceResult(item)));
            list.classList.toggle('dc-hidden', !available.length);
            hint.textContent = available.length
                ? `${available.length} matching invoice(s). Select one, or press Enter to add all.`
                : (data.items.length ? 'Matching invoice(s) already collected.' : 'No matching invoice. You may add a manual row.');
        } catch (error) {
            notice(error.message, 'error');
        }
    }, 250);
}

function invoiceResult(item) {
    const result = document.createElement('button');
    result.type = 'button';
    result.className = 'customer-result';
    result.setAttribute('role', 'option');

    const name = document.createElement('strong');
    name.textContent = item.InvoiceNo;
    const meta = document.createElement('span');
    meta.textContent = [item.DeliveryDate, item.DEPARTMENT, money(Number(item.Balance))].filter(Boolean).join(' \u2022 ');
    result.append(name, meta);

    result.addEventListener('click', () => {
        invoiceRow({ invoice_no: item.InvoiceNo, amount: Number(item.Balance), delivery_date: item.DeliveryDate, department: item.DEPARTMENT });
        id('invoiceSearch').value = '';
        id('invoiceResults').classList.add('dc-hidden');
        id('invoiceSearchHint').textContent = `Invoice ${item.InvoiceNo} added.`;
        id('addManualInvoiceRow').classList.add('dc-hidden');
    });

    return result;
}

function invoiceRow(invoice){const rows=id('invoiceRows');if([...rows.querySelectorAll('tr[data-invoice]')].some(row=>row.dataset.manual!=='1'&&row.dataset.invoice===invoice.invoice_no))return notice(`Invoice ${invoice.invoice_no} is already in the list.`,'info');const tr=document.createElement('tr');tr.dataset.invoice=invoice.invoice_no;tr.dataset.amount=String(invoice.amount);tr.dataset.manual='0';const checkbox=document.createElement('input');checkbox.type='checkbox';checkbox.className='invoice-select';checkbox.checked=true;checkbox.addEventListener('change',refreshInvoiceTotal);const checkCell=document.createElement('td');checkCell.append(checkbox);tr.append(checkCell);[invoice.invoice_no,invoice.delivery_date||'',invoice.department||'',money(invoice.amount),''].forEach(value=>{const cell=document.createElement('td');cell.textContent=value;tr.append(cell);});if(rows.querySelector('td[colspan]'))rows.replaceChildren();rows.append(tr);refreshInvoiceTotal();}
function manualInvoiceRow(){const rows=id('invoiceRows'),tr=document.createElement('tr');tr.dataset.invoice='';tr.dataset.amount='0';tr.dataset.manual='1';const checkbox=document.createElement('input');checkbox.type='checkbox';checkbox.className='invoice-select';checkbox.checked=true;checkbox.addEventListener('change',refreshInvoiceTotal);const number=document.createElement('input');number.className='input manual-invoice-number';number.placeholder='Invoice number';const amount=document.createElement('input');amount.className='input manual-invoice-amount';amount.type='number';amount.min='0.01';amount.step='0.01';amount.value='0';[number,amount].forEach(field=>field.addEventListener('input',refreshInvoiceTotal));const selectedCell=document.createElement('td');selectedCell.append(checkbox);const invoiceCell=document.createElement('td');invoiceCell.append(number);const dateCell=document.createElement('td');dateCell.textContent='Manual entry';const departmentCell=document.createElement('td');departmentCell.textContent='-';const amountCell=document.createElement('td');amountCell.append(amount);const remove=document.createElement('button');remove.type='button';remove.className='btn btn-red remove-invoice-row';remove.innerHTML='<i class="fa-solid fa-trash" aria-hidden="true"></i> Remove';const actionCell=document.createElement('td');actionCell.append(remove);tr.append(selectedCell,invoiceCell,dateCell,departmentCell,amountCell,actionCell);if(rows.querySelector('td[colspan]'))rows.replaceChildren();rows.append(tr);refreshInvoiceTotal();number.focus();}
async function findInvoice(){const query=id('invoiceSearch').value.trim(),hint=id('invoiceSearchHint'),manualButton=id('addManualInvoiceRow');if(query.length<2)return notice('Enter at least two characters of the invoice number.','error');try{const data=await post('collection_invoices',{customer:id('selectedCustomer').value,q:query});if(data.already_collected){manualButton.classList.add('dc-hidden');hint.textContent='This invoice has already been collected.';return notice(`Invoice ${query} has already been collected.`,'error');}const available=data.items.filter(item=>!Number(item.AlreadyCollected));const collected=data.items.filter(item=>Number(item.AlreadyCollected));if(collected.length)notice(`Invoice ${collected.map(item=>item.InvoiceNo).join(', ')} has already been collected.`,'error');if(available.length){manualButton.classList.add('dc-hidden');available.forEach(item=>invoiceRow({invoice_no:item.InvoiceNo,amount:Number(item.Balance),delivery_date:item.DeliveryDate,department:item.DEPARTMENT}));hint.textContent=`${available.length} InvoiceList record(s) added. Uncheck an invoice to exclude it.`;}else if(!data.items.length){manualButton.classList.remove('dc-hidden');hint.textContent='No record exists in CollectionSyntaxInvDtl or InvoiceList. You may add a manual invoice row.';}else{manualButton.classList.add('dc-hidden');hint.textContent='This invoice has already been collected.';}}catch(e){notice(e.message,'error');}}
function summary(){if(!id('invoiceBalance')||!id('summaryInvoice'))return;const invoice=Number(id('invoiceBalance').value),split=[...document.querySelectorAll('.split-amount')].reduce((s,v)=>s+Number(v.value||0),0),paid=[...document.querySelectorAll('.payment-amount')].reduce((s,v)=>s+Number(v.value||0),0),balance=invoice-split-paid;id('summaryInvoice').textContent=invoice.toFixed(2);id('summarySplit').textContent=`- ${split.toFixed(2)}`;id('summaryCollected').textContent=`- ${paid.toFixed(2)}`;id('summaryBalance').textContent=balance.toFixed(2);id('summaryBalance').style.color=Math.abs(balance)<.01?'#198754':'#dc3545';}
function updateCollectionTotals(){if(!id('invoiceBalance')||!id('totalOutstandingInvoices'))return;const invoice=Number(id('invoiceBalance').value),split=[...document.querySelectorAll('.split-amount')].reduce((sum,input)=>sum+Number(input.value||0),0),paid=[...document.querySelectorAll('.payment-amount')].reduce((sum,input)=>sum+Number(input.value||0),0),balance=invoice-split-paid;id('totalOutstandingInvoices').textContent=invoice.toFixed(2);id('totalCollectionDetails').textContent=paid.toFixed(2);id('totalSplitBalance').textContent=split.toFixed(2);id('summaryBalance').style.color=balance<-.009?'#b45309':Math.abs(balance)<.01?'#198754':'#dc3545';if(collectionAccessGranted)id('completeTransaction').disabled=balance>.009;}
function collectionRequirements(){const missing=[];if(!id('prNumber')?.value.trim())missing.push({label:'PR number',step:'collectionStep1'});const invoices=selectedInvoices();if(!invoices.length)missing.push({label:'at least one invoice',step:'collectionStep2'});else if(invoices.some(invoice=>!invoice.invoice_no||invoice.amount<=0))missing.push({label:'a valid invoice number and amount',step:'collectionStep2'});const hasPayment=[...id('paymentRows')?.querySelectorAll('.payment-amount')||[]].some(input=>Number(input.value||0)>0);if(!hasPayment)missing.push({label:'at least one payment amount',step:'collectionStep3'});[...id('splitRows')?.rows||[]].forEach(row=>{const amount=Number(row.querySelector('.split-amount')?.value||0),category=row.querySelector('.split-category');if(amount>0&&!category?.value&&!missing.some(item=>item.step==='collectionStep4'))missing.push({label:'a category for each split amount',step:'collectionStep4'});if(amount>0&&category?.value&&category.selectedOptions[0]?.dataset.required==='1'&&!row.querySelector('.split-file')?.files[0]&&!missing.some(item=>item.label==='the required split attachment'))missing.push({label:'the required split attachment',step:'collectionStep4'});});return missing;}
function showSaveConfirmation(){const requirements=collectionRequirements(),box=id('saveRequirements'),confirm=id('confirmSave');if(box){box.innerHTML=requirements.length?`<div class="notice error"><strong>Please complete before saving:</strong><ul>${requirements.map(item=>`<li><a href="#${item.step}">${item.label}</a></li>`).join('')}</ul></div>`:'<div class="notice success">All required fields are complete. You can save this collection.</div>';}if(confirm)confirm.disabled=requirements.length>0;id('saveConfirmModal')?.classList.add('active');}
async function save(){try{if(!collectionInRange||!collectionAccessGranted)throw Error('Open the customer collection details before saving.');const invoice=Number(id('invoiceBalance').value),invoices=selectedInvoices(),splits=[],form=new FormData(),payments=[];[...id('paymentRows').rows].forEach((r,i)=>{const type=r.querySelector('.payment-type').value,amount=Number(r.querySelector('.payment-amount').value||0),file=r.querySelector('.payment-file').files[0],attachmentReference=file?`payment_${i}`:'';if(amount>0){payments.push({type,bank:r.querySelector('.payment-bank').value.trim(),check:r.querySelector('.payment-check').value.trim(),attachment_reference:attachmentReference,amount});if(file)form.append(`payment_attachment_${attachmentReference}`,file);}});[...id('splitRows').rows].forEach((r,i)=>{const category=r.querySelector('.split-category'),amount=Number(r.querySelector('.split-amount').value||0),file=r.querySelector('.split-file').files[0];if(amount>0){if(!category.value)throw Error('Select a category for every split amount.');if(category.selectedOptions[0].dataset.required==='1'&&!file)throw Error('An attachment is required for the selected category.');const attachmentReference=file?`split_${category.value}_${i}`:'';splits.push({catid:Number(category.value),amount,reference:r.querySelector('.split-reference').value.trim(),attachment_reference:attachmentReference});if(file)form.append(`split_attachment_${attachmentReference}`,file);}});const collected=payments.reduce((s,p)=>s+p.amount,0),splitTotal=splits.reduce((s,p)=>s+p.amount,0);if(!invoices.length)throw Error('Add at least one invoice before saving.');if(!id('prNumber').value.trim())throw Error('PR number is required.');if(!payments.length)throw Error('Add a payment amount before saving.');if(invoice-collected-splitTotal>.009)throw Error('Total balance must be zero or an overpayment before saving.');form.set('customer',id('selectedCustomer').value);form.set('amount',collected);form.set('split_amount',splitTotal);form.set('payments',JSON.stringify(payments));form.set('splits',JSON.stringify(splits));form.set('invoices',JSON.stringify(invoices));form.set('pr_number',id('prNumber').value.trim());const result=await post('complete_collection',form);const next=new URL(window.location.href);next.searchParams.delete('customer');next.searchParams.set('focusCustomer','1');next.searchParams.set('savedReference',result.reference||'');window.location.href=next.toString();}catch(e){notice(e.message,'error');}}
async function deliver(button){
    try {
        if (!gps) throw Error('Waiting for GPS location.');
        const row = button.closest('tr');
        const photoInput = row?.querySelector('.store-photo-input');
        const photo = photoInput?.files?.[0];
        if (!photo) throw Error('Attach a photo of the store before confirming this delivery.');
        button.disabled = true;
        const form = new FormData();
        form.set('customer', id('selectedCustomer').value);
        form.set('trip_id', button.dataset.tripId);
        form.set('invoice_no', button.dataset.invoiceNo);
        form.set('latitude', gps.latitude);
        form.set('longitude', gps.longitude);
        form.set('store_photo', photo);
        const result = await post('complete_delivery', form);
        row?.remove();
        notice(result.message, 'success');
        if (Number(result.remaining_for_customer) === 0) {
            const next = new URL(window.location.href);
            next.searchParams.delete('customer');
            next.searchParams.set('focusCustomer', '1');
            window.location.href = next.toString();
        }
    } catch (error) {
        button.disabled = false;
        notice(error.message, 'error');
    }
}
function updateDeliveryButtonStates(){
    document.querySelectorAll('.confirm-delivery').forEach((button) => {
        if (!locationConfirmedForDelivery) { button.disabled = true; return; }
        const hasPhoto = Boolean(button.closest('tr')?.querySelector('.store-photo-input')?.files?.length);
        button.disabled = !hasPhoto;
    });
    document.querySelectorAll('.not-delivered-toggle').forEach((button) => {
        button.disabled = !locationConfirmedForDelivery;
    });
}
async function notDelivered(button){
    const row = button.closest('tr');
    const panel = row?.querySelector('.not-delivered-reason');
    const reasonField = panel?.querySelector('.not-delivered-reason-text');
    const reason = reasonField?.value.trim() || '';
    if (!reason) { notice("Enter a reason before saving.", 'error'); reasonField?.focus(); return; }
    if (!gps) { notice('Waiting for GPS location.', 'error'); return; }
    try {
        button.disabled = true;
        const result = await post('not_delivered', {
            customer: id('selectedCustomer').value,
            trip_id: button.dataset.tripId,
            invoice_no: button.dataset.invoiceNo,
            reason,
            ...gps,
        });
        row?.remove();
        notice(result.message, 'success');
        if (Number(result.remaining_for_customer) === 0) {
            const next = new URL(window.location.href);
            next.searchParams.delete('customer');
            next.searchParams.set('focusCustomer', '1');
            window.location.href = next.toString();
        }
    } catch (error) {
        button.disabled = false;
        notice(error.message, 'error');
    }
}
function initRouteMap(){
    const el=id('routeMap'); if(!el||!window.L||!Array.isArray(window.deliveryRouteStops))return;
    if(window.deliveryRouteMap){window.deliveryRouteMap.invalidateSize();if(window.deliveryRouteBounds)window.deliveryRouteMap.fitBounds(window.deliveryRouteBounds,{padding:[30,30],maxZoom:16});return;}
    const stops=window.deliveryRouteStops.filter(s=>Number(s.Latitude)&&Number(s.Longitude));
    if(!stops.length){el.closest('.route-map-wrap')?.classList.add('dc-hidden');return;}
    const map=L.map(el);
    window.deliveryRouteMap=map;
    window.deliveryRouteMarkers={};
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:19,attribution:'OpenStreetMap'}).addTo(map);
    const latlngs=[];
    stops.forEach(stop=>{
        const lat=Number(stop.Latitude),lng=Number(stop.Longitude);
        latlngs.push([lat,lng]);
        const delivered=(Number(stop.InvoiceCount||0)>0)&&((Number(stop.DeliveredCount||0)+Number(stop.NotDeliveredCount||0))>=Number(stop.InvoiceCount||0));
        const icon=L.divIcon({className:'route-pin'+(delivered?' route-pin--done':''),html:`<span>${stop.DisplaySeq}</span>`,iconSize:[28,28],iconAnchor:[14,14]});
        const address=[stop.Street,stop.Barangay,stop.Municipality,stop.Province].filter(Boolean).join(', ');
        const marker=L.marker([lat,lng],{icon}).addTo(map).bindPopup(`<strong>#${stop.DisplaySeq} Trip ${stop.TripId} &ndash; ${stop.CustomerName}</strong><br>${address}`);
        window.deliveryRouteMarkers[stop.DisplaySeq]=marker;
    });
    window.deliveryRouteBounds=latlngs.length>1?latlngs:[latlngs[0],[latlngs[0][0]+0.001,latlngs[0][1]+0.001]];
    map.fitBounds(window.deliveryRouteBounds,{padding:[30,30],maxZoom:16});
    if(latlngs.length<2)return;
    // Fallback: a straight dashed line, shown immediately and replaced by the
    // real driving route below once/if it loads.
    let routeLine=L.polyline(latlngs,{color:'#a71927',weight:3,dashArray:'6,8',opacity:.85}).addTo(map);
    const waypoints=latlngs.map(([lat,lng])=>`${lng},${lat}`).join(';');
    fetch(`https://router.project-osrm.org/route/v1/driving/${waypoints}?overview=full&geometries=geojson`)
        .then(r=>r.json())
        .then(data=>{
            const coords=data.routes?.[0]?.geometry?.coordinates;
            if(!coords)return;
            routeLine.remove();
            routeLine=L.polyline(coords.map(c=>[c[1],c[0]]),{color:'#a71927',weight:4,opacity:.85}).addTo(map);
        })
        .catch(error=>console.warn('Route directions unavailable, showing straight line instead.',error));
}

/** Pans/zooms the already-open route map to one stop's pin and opens its
 *  popup -- triggered by clicking that stop's customer name in the table
 *  below the map, so you don't have to hunt for the pin yourself. */
function focusRouteStop(displaySeq){
    const map=window.deliveryRouteMap;
    const marker=window.deliveryRouteMarkers?.[displaySeq];
    if(!map||!marker)return;
    id('routeMap')?.scrollIntoView({behavior:'smooth',block:'start'});
    map.setView(marker.getLatLng(),17,{animate:true});
    marker.openPopup();
    const el=marker.getElement();
    if(el){el.classList.add('route-pin--pulse');setTimeout(()=>el.classList.remove('route-pin--pulse'),1500);}
}
document.addEventListener('DOMContentLoaded',()=>{initMap();id('customerSearch')?.addEventListener('input',e=>search(e.target.value.trim()));id('customerSearch')?.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();openCustomer();}});id('customerResults')?.addEventListener('dblclick',openCustomer);id('openCustomer')?.addEventListener('click',openCustomer);id('invoiceSearch')?.addEventListener('input',e=>searchInvoices(e.target.value.trim()));id('invoiceSearch')?.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();findInvoice();}});id('addInvoice')?.addEventListener('click',findInvoice);id('addManualInvoiceRow')?.addEventListener('click',manualInvoiceRow);id('confirmLocation')?.addEventListener('click',async()=>{try{if(!gps)throw Error('Waiting for GPS location.');await post('confirm_location',{customer:id('selectedCustomer').value,module:id('moduleName').value,...gps});notice('Location confirmed. You can now save.','success');id('completeTransaction').disabled=false;}catch(e){notice(e.message,'error');}});id('viewMap')?.addEventListener('click',()=>{id('mapModal').classList.add('active');setTimeout(()=>{window.deliveryCollectionMap?.invalidateSize();if(mapFitTarget)window.deliveryCollectionMap?.fitBounds(mapFitTarget,{padding:[40,40]});},150);});id('closeMap')?.addEventListener('click',()=>id('mapModal').classList.remove('active'));id('viewCollectionDetails')?.addEventListener('click',async()=>{try{if(!collectionInRange||!gps)throw Error('Customer is out of range. Move within 5 m of the customer.');const result=await post('collection_access',{customer:id('selectedCustomer').value,...gps});id('collectionDetails').classList.remove('dc-hidden');collectionAccessGranted=true;updateCollectionTotals();notice(result.access.reason,'success');}catch(e){notice(e.message,'error');}});id('viewDeliveryDetails')?.addEventListener('click',async()=>{try{if(!collectionInRange||!gps)throw Error('Customer is out of range. Move within the allowed radius of the customer.');await post('confirm_location',{customer:id('selectedCustomer').value,module:'delivery',...gps});id('deliveryDetails').classList.remove('dc-hidden');locationConfirmedForDelivery=true;updateDeliveryButtonStates();notice('Location confirmed. Attach a store photo, then confirm each invoice one at a time.','success');}catch(e){notice(e.message,'error');}});document.addEventListener('change',e=>{if(e.target.classList.contains('store-photo-input'))updateDeliveryButtonStates();});id('addPayment')?.addEventListener('click',paymentRow);id('addSplit')?.addEventListener('click',splitRow);document.addEventListener('input',()=>{summary();updateCollectionTotals();});document.addEventListener('click',e=>{if(e.target.classList.contains('remove-row')||e.target.classList.contains('remove-invoice-row')){e.target.closest('tr').remove();refreshInvoiceTotal();}});if(id('paymentRows')){paymentRow();splitRow();summary();updateCollectionTotals();}id('completeTransaction')?.addEventListener('click',()=>id('moduleName').value==='collection'?showSaveConfirmation():deliver());['closeSaveConfirm','cancelSave'].forEach(key=>id(key)?.addEventListener('click',()=>id('saveConfirmModal').classList.remove('active')));id('saveRequirements')?.addEventListener('click',e=>{if(e.target.closest('a'))id('saveConfirmModal').classList.remove('active');});id('confirmSave')?.addEventListener('click',save);});
document.addEventListener('DOMContentLoaded',()=>{
    const params=new URLSearchParams(window.location.search),reference=params.get('savedReference');
    if(reference)notice(`Collection saved successfully. Syntax reference: ${reference}`,'success');
    if(params.get('focusCustomer')==='1')setTimeout(()=>id('customerSearch')?.focus(),0);

    document.querySelectorAll('.confirm-delivery').forEach((button) => {
        button.addEventListener('click', () => deliver(button));
    });
    document.querySelectorAll('.not-delivered-toggle').forEach((button) => {
        button.addEventListener('click', () => {
            const panel = button.closest('tr')?.querySelector('.not-delivered-reason');
            panel?.classList.toggle('dc-hidden');
            if (panel && !panel.classList.contains('dc-hidden')) panel.querySelector('.not-delivered-reason-text')?.focus();
        });
    });
    document.querySelectorAll('.not-delivered-cancel').forEach((button) => {
        button.addEventListener('click', () => {
            const panel = button.closest('.not-delivered-reason');
            panel?.classList.add('dc-hidden');
            const field = panel?.querySelector('.not-delivered-reason-text');
            if (field) field.value = '';
        });
    });
    document.querySelectorAll('.not-delivered-submit').forEach((button) => {
        button.addEventListener('click', () => notDelivered(button));
    });

    id('viewRoute')?.addEventListener('click', () => {
        id('routeModal').classList.add('active');
        setTimeout(initRouteMap, 150);
    });
    id('closeRoute')?.addEventListener('click', () => id('routeModal').classList.remove('active'));
    document.querySelectorAll('.route-focus-customer').forEach((link) => {
        link.addEventListener('click', (e) => {
            e.preventDefault();
            focusRouteStop(link.dataset.seq);
        });
    });
});
})();
