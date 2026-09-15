/* Inventory UI — Alpine providers. No framework build step is required. */
(function () {
    'use strict';
    if (window.InventoryUI) return;
    const runtimeUrl = document.currentScript.dataset.alpine;
    const UI = window.InventoryUI = {
        page: null,
        async request(url, data) {
            let response;
            try {
                response = await fetch(url, data ? {method: 'POST', body: data, credentials: 'same-origin'} : {credentials: 'same-origin'});
            } catch (_) { throw new Error('Koneksi terputus. Isian tetap tersedia; periksa koneksi sebelum mencoba lagi.'); }
            let result;
            try { result = await response.json(); }
            catch (_) { throw new Error('Respons tidak dapat dibaca. Periksa sesi login dan batas unggah server.'); }
            if (!response.ok || !result.ok) throw new Error(result.message || 'Permintaan gagal. Silakan coba lagi.');
            return result;
        },
        navigate(url) { jQuery('#mainContent').simbioAJAX(url); },
        clean() { if (UI.page) UI.page.dirty = false; },
        confirm(message) { return Alpine.store('inventoryDialog').request(message); },
        validation(form) {
            const invalid = form.querySelector(':invalid');
            if (!invalid) return true;
            for (let p = invalid.parentElement; p && p !== form; p = p.parentElement) if (p.tagName === 'DETAILS') p.open = true;
            invalid.reportValidity(); invalid.focus(); return false;
        },
    };

    function formState() {
        return {
            busy: false, error: '',
            async save(event) {
                event.stopImmediatePropagation();
                if (this.busy) return;
                const form = event.target;
                // A wizard never submits an incomplete hidden step on Enter.
                const wizard = form.closest('[data-inventory-wizard]');
                if (wizard && Alpine.$data(wizard).step < 3) { await Alpine.$data(wizard).next(); return; }
                if (!UI.validation(form)) return;
                const data = new FormData(form);
                if (event.submitter?.name) data.set(event.submitter.name, event.submitter.value);
                this.busy = true; this.error = '';
                const action = data.get('watch_action');
                try {
                    if (['stop'].includes(action) && !await UI.confirm('Hentikan jadwal mulai tanggal yang dipilih? Riwayat pemeriksaan tetap tersimpan.')) return;
                    if (['verify','reject','submit'].includes(data.get('mode')) && !await UI.confirm('Simpan keputusan ini dan lanjutkan ke tahap berikutnya?')) return;
                    form.setAttribute('inert','');
                    const result = await UI.request(form.action, data);
                    UI.clean(); UI.navigate(result.url);
                } catch (e) { this.error = e.message; this.$nextTick(() => form.querySelector('[role="alert"]')?.scrollIntoView({block:'center',behavior:'smooth'})); }
                finally { form.removeAttribute('inert'); this.busy = false; }
            },
        };
    }

    function register() {
        if (UI.registered) return;
        UI.registered = true;
        Alpine.store('inventoryDialog', {
            open: false, message: '', resolve: null,
            request(message) {
                if (this.open) return Promise.resolve(false);
                this.message = message; this.open = true;
                return new Promise(resolve => { this.resolve = resolve; });
            },
            finish(value) { const resolve = this.resolve; this.open = false; this.resolve = null; if (resolve) resolve(value); },
        });
        Alpine.data('inventoryDialog', () => ({
            init() { this.$watch('$store.inventoryDialog.open', value => { if (value) this.$el.showModal(); else this.$el.close(); }); },
        }));
        const dialog = document.createElement('dialog');
        dialog.className = 'inventory-ui inv-dialog';
        dialog.setAttribute('x-data', 'inventoryDialog');
        dialog.setAttribute('aria-labelledby', 'inventory-confirm-title');
        dialog.setAttribute('@cancel.prevent', '$store.inventoryDialog.finish(false)');
        dialog.innerHTML = '<h3 id="inventory-confirm-title">Konfirmasi tindakan</h3><p x-text="$store.inventoryDialog.message"></p><div class="inv-actions"><button type="button" class="btn btn-default" autofocus @click="$store.inventoryDialog.finish(false)">Batal</button><button type="button" class="btn btn-primary" @click="$store.inventoryDialog.finish(true)">Lanjutkan</button></div>';
        document.body.appendChild(dialog);

        Alpine.data('inventoryForm', formState);
        Alpine.data('inventoryPage', () => ({
            dirty: false, syncMessage: '',
            init() {
                UI.page = this;
                this.$el.querySelectorAll('.form-group').forEach((group,index)=>{
                    const label=group.querySelector('label:not([for])'); const field=group.querySelector('input:not([type="hidden"]),select,textarea');
                    if(label && field){ if(!field.id)field.id='inventory-field-'+index; label.htmlFor=field.id; }
                });
                this.sync();
            },
            destroy() { if (UI.page === this) UI.page = null; },
            filter(form) {
                const url = new URL(form.closest('[data-base]').dataset.base, location.href);
                url.searchParams.delete('page');
                for (const [key, value] of new FormData(form)) url.searchParams.set(key, value);
                UI.navigate(url.href);
            },
            async sync() {
                const root = this.$el;
                if (root.dataset.write !== '1' || !root.dataset.csrf || window.inventoryWatchSyncBusy) return;
                window.inventoryWatchSyncBusy = true;
                let generated = 0;
                try {
                    let more = true;
                    while (more && root.isConnected) {
                        const data = new FormData(); data.set('watch_action','sync'); data.set('csrf_token',root.dataset.csrf);
                        const result = await UI.request(root.dataset.base, data);
                        generated += result.generated; more = result.more;
                        if (generated) this.syncMessage = generated + ' pemeriksaan jatuh tempo disiapkan.';
                    }
                    if (generated && root.isConnected && !this.dirty && root.dataset.list === '1') UI.navigate(root.dataset.refresh);
                } catch (e) { this.syncMessage = 'Jadwal belum disinkronkan: ' + e.message; }
                finally { window.inventoryWatchSyncBusy = false; }
            },
        }));
        Alpine.data('inventoryChecklist', items => ({
            sequence: items.length,
            items: items.map((item, key) => ({...item, key})),
            add() { if (this.items.length < 100) { this.items.push({key:this.sequence++,group:'Sarana',object:'',instruction:''}); this.$dispatch('inventory-dirty'); } },
            async remove(index) { if (this.items.length > 1 && await UI.confirm('Hapus butir ini dari versi checklist yang sedang disusun?')) { this.items.splice(index,1); this.$dispatch('inventory-dirty'); } },
        }));
        Alpine.data('inventoryWizard', config => ({
            ...config, step: 1, scopeChanged: false, scopeError: '', loading: false,
            init() {
                this.$el.dataset.inventoryWizard = '1';
                this.location = String(this.location); this.template = String(this.template);
                this.mapping = this.items.map((_,index) => this.mapping[index] || '');
            },
            async next() {
                if (this.loading) return;
                this.scopeError = '';
                if (this.step === 1 && this.scopeChanged) {
                    this.loading = true;
                    try {
                        const url = new URL(this.scopeUrl, location.href);
                        url.searchParams.set('location_id',this.location); url.searchParams.set('template_id',this.template);
                        const result = await UI.request(url.href);
                        this.items = result.items; this.assets = result.assets; this.mapping = result.items.map(() => ''); this.scopeChanged = false;
                    } catch (e) { this.scopeError = e.message; return; }
                    finally { this.loading = false; }
                }
                this.step = Math.min(3,this.step+1);
            },
        }));
        Alpine.data('inventoryUpload', () => ({
            previews: [],
            change(event) { this.destroy(); this.previews = Array.from(event.target.files).map(file=>URL.createObjectURL(file)); },
            destroy() { this.previews.forEach(url=>URL.revokeObjectURL(url)); this.previews=[]; },
        }));
        Alpine.data('inventoryInspection', config => ({
            ...formState(), ...config, form: null, message: '', photoMessages: {}, uncertain: false,
            init() { this.form = this.$el; },
            get completed() { return Object.values(this.results).filter(result=>result.outcome).length; },
            hasPhotos(id) { const e=this.evidence[id]; return e.files.length>0 || e.remove.length>0; },
            pickPhotos(event,id) {
                const e=this.evidence[id]; const files=Array.from(event.target.files);
                if (e.photos.length-e.remove.length+e.files.length+files.length>5 || files.some(f=>f.size>2*1024*1024 || !['image/jpeg','image/png','image/webp'].includes(f.type))) {
                    this.error='Pilih maksimal 5 foto per butir, masing-masing 2 MB, dengan format JPEG, PNG, atau WebP.'; event.target.value='';return;
                }
                e.files.push(...files); e.previews.push(...files.map(f=>URL.createObjectURL(f))); event.target.value=''; this.$dispatch('inventory-dirty');
            },
            removeFile(id,index) { const e=this.evidence[id]; URL.revokeObjectURL(e.previews[index]); e.files.splice(index,1);e.previews.splice(index,1); },
            destroy() { Object.values(this.evidence).forEach(e=>e.previews.forEach(url=>URL.revokeObjectURL(url))); },
            applyDocument(document) {
                this.version=document.version;
                const status=this.form.closest?.('[data-inspection]')?.querySelector('[data-document-status] .inv-badge');
                if(status && ['pending','draft','final'].includes(document.status)) {
                    status.textContent={pending:'Belum dimulai',draft:'Draf',final:'Difinalisasi'}[document.status];
                    status.className='inv-badge inv-status-'+document.status;
                }
                this.form.elements.namedItem('version').value=String(this.version);
                for (const [id,e] of Object.entries(this.evidence)) e.photos=document.photos.filter(photo=>String(photo.result_id)===id);
            },
            async draft() {
                const data=new FormData(this.form); data.set('submit_mode','draft');data.set('version',this.version);
                const result=await UI.request(this.form.action,data);this.applyDocument(result.document);
                this.message='Draf pemeriksaan tersimpan.'; return result;
            },
            async photos(id) {
                if (!this.hasPhotos(id)) return;
                const e=this.evidence[id];const data=new FormData();
                data.set('watch_action','result_photos');data.set('csrf_token',this.form.elements.namedItem('csrf_token').value);
                data.set('inspection_id',this.id);data.set('result_id',id);data.set('version',this.version);
                e.files.forEach(file=>data.append('photos[]',file));e.remove.forEach(photo=>data.append('remove[]',photo));
                const result=await UI.request(this.form.action,data);this.applyDocument(result.document);
                e.previews.forEach(url=>URL.revokeObjectURL(url));e.files=[];e.previews=[];e.remove=[];
                this.photoMessages[id]='Foto butir tersimpan.';
            },
            validateFinal() {
                const form=this.form; let target=null;
                if (!form.elements.namedItem('performed_date').value) target=form.elements.namedItem('performed_date');
                for (const [id,result] of Object.entries(this.results)) {
                    const required=['outcome'];
                    if (result.outcome && result.outcome!=='good') required.push('notes');
                    if (result.outcome==='action') required.push('assignee_id','priority','deadline');
                    for (const field of required) { const input=form.elements.namedItem('results['+id+']['+field+']'); if (!input.value.trim() && !target) target=input; }
                }
                if (target) { this.error='Lengkapi tanggal, hasil setiap butir, alasan, dan penugasan yang diperlukan sebelum finalisasi.';target.focus();target.scrollIntoView({block:'center'});return false; }
                return true;
            },
            async run(mode,onlyId=null) {
                if(this.busy)return;
                if(this.uncertain){this.error='Status permintaan sebelumnya belum pasti. Muat ulang dokumen untuk memeriksa data tersimpan sebelum mencoba lagi.';return;}
                this.error='';this.message='';
                if(mode==='final' && !this.validateFinal())return;
                this.busy=true;
                // Lock inputs while the serial sequence uses the version returned by each transaction.
                const inputs=Array.from(this.form.querySelectorAll('input,select,textarea'));
                const oldDisabled=inputs.map(input=>input.disabled);
                try {
                    if(mode==='final' && !await UI.confirm('Finalisasi akan mengunci hasil dan foto bukti. Lanjutkan?'))return;
                    // Keep fields enabled for FormData; block user interaction using inert.
                    this.form.setAttribute('inert','');
                    let result=await this.draft();
                    for(const id of (onlyId===null?Object.keys(this.evidence):[onlyId])) await this.photos(id);
                    if(mode==='final') {
                        const data=new FormData(this.form); data.set('submit_mode','final');data.set('version',this.version);
                        result=await UI.request(this.form.action,data);this.applyDocument(result.document);
                        UI.clean();UI.navigate(result.url);
                    } else {
                        this.message='Draf tersimpan'+(onlyId===null?' beserta seluruh foto yang dipilih.':'; foto butir diperbarui.');
                        if(!Object.keys(this.evidence).some(id=>this.hasPhotos(id))) UI.clean();
                    }
                } catch(e) {
                    this.error=(this.message?this.message+' ':'')+e.message+' Bagian yang belum tersimpan tetap tersedia.';
                    this.$nextTick(()=>this.form.querySelector('.watch-error')?.scrollIntoView({block:'center',behavior:'smooth'}));
                    // Lost responses cannot safely be retried: a photo transaction may have committed.
                    if (/Koneksi terputus|Respons tidak dapat dibaca/.test(e.message)) this.uncertain=true;
                } finally {
                    this.form.removeAttribute('inert');inputs.forEach((input,index)=>input.disabled=oldDisabled[index]);this.busy=false;
                }
            },
            saveEvidence(id) { return this.run('draft',id); },
            save(event) { event.stopImmediatePropagation(); return this.run(event.submitter?.value==='final'?'final':'draft'); },
        }));
    }
    let replay = false;
    document.addEventListener('click', async event => {
        const link=event.target.closest('a[href]');
        if(!link || !UI.page?.dirty || replay || link.target==='_blank' || event.ctrlKey || event.metaKey || link.getAttribute('href')==='#')return;
        event.preventDefault();event.stopImmediatePropagation();
        if(await UI.confirm('Ada perubahan yang belum tersimpan. Tinggalkan halaman ini?')) { UI.clean();replay=true;link.click();replay=false; }
    },true);
    window.addEventListener('beforeunload',event=>{if(UI.page?.dirty){event.preventDefault();event.returnValue='';}});
    if(window.Alpine) register();
    else {
        document.addEventListener('alpine:init',register,{once:true});
        const script=document.createElement('script');script.src=runtimeUrl;script.defer=true;document.head.appendChild(script);
    }
})();
