import type { Config, Reply } from './types'
export class ApiError extends Error {
  constructor(message:string, public status=0, public fields:Record<string,string>={}) {super(message)}
}
export function formData(values:Record<string,unknown>, data = new FormData(), prefix=''):FormData {
  for(const [key,value] of Object.entries(values)) {
    const name=prefix?`${prefix}[${key}]`:key
    if(value instanceof File)data.append(name,value)
    else if(value!==null && typeof value==='object')formData(value as Record<string,unknown>,data,name)
    else data.append(name,value==null?'':String(value))
  }
  return data
}
export function url(base:string, values:Record<string,unknown>):string {
  const u=new URL(base,window.location.href)
  for(const [k,v] of Object.entries(values))if(v!==undefined && v!==null)u.searchParams.set(k,String(v))
  return u.href
}
export async function request<T=Reply>(target:string, body?:FormData, signal?:AbortSignal):Promise<T> {
  let response:Response
  try {response=await fetch(target,{method:body?'POST':'GET',body,signal,credentials:'same-origin',headers:{Accept:'application/json'}})}
  catch(e){if((e as Error).name==='AbortError')throw e;
    // Replay the same committed operation after a lost response; server caches by request_id.
    if(body?.has('request_id')){try{response=await fetch(target,{method:'POST',body,signal,credentials:'same-origin',headers:{Accept:'application/json'}})}catch{throw new ApiError('Koneksi terputus. Isian Anda tetap tersedia; coba lagi.')}}
    else throw new ApiError('Koneksi terputus. Isian Anda tetap tersedia; coba lagi.')
  }
  if(!(response.headers.get('content-type')||'').includes('application/json'))throw new ApiError('Sesi berakhir atau server tidak tersedia. Masuk kembali lalu muat ulang halaman.',response.status)
  const result=await response.json()
  if(!response.ok || !result.ok)throw new ApiError(result.message||'Permintaan gagal.',response.status,result.errors||{})
  return result as T
}
export async function read<T>(config:Config,resource:string,params:Record<string,unknown>={},signal?:AbortSignal):Promise<T>{
  return (await request<{data:T}>(url(config.api,{...params,resource}),undefined,signal)).data
}
export const groups=['Sarana','Prasarana','Lingkungan Fisik']
export const statuses:Record<string,string>={pending:'Belum dimulai',draft:'Draf',final:'Selesai',open:'Perlu dikerjakan',working:'Dikerjakan',review:'Menunggu verifikasi',closed:'Selesai'}
export const dateLabel=(value:unknown)=>value?new Intl.DateTimeFormat('id-ID',{day:'numeric',month:'short',year:'numeric'}).format(new Date(String(value).slice(0,10)+'T12:00:00')):'—'
export const money=(value:unknown)=>new Intl.NumberFormat('id-ID',{style:'currency',currency:'IDR',maximumFractionDigits:0}).format(Number(value||0))
export function inspectionErrors(results:import('./types').Result[],performed:string,today:string):Record<string,string>{
  const errors:Record<string,string>={}
  if(!performed||performed>today)errors.performed_date='Isi tanggal pelaksanaan yang tidak melewati hari ini.'
  for(const r of results){
    if(!r.outcome)errors[String(r.id)]='Pilih hasil pemeriksaan.'
    else if(r.outcome!=='good'&&!r.notes.trim())errors[String(r.id)]='Tambahkan alasan untuk hasil ini.'
    else if(r.outcome==='action'&&(!r.assignee_id||!r.priority||!r.deadline))errors[String(r.id)]='Lengkapi petugas, prioritas, dan tenggat tindak lanjut.'
  }
  return errors
}
