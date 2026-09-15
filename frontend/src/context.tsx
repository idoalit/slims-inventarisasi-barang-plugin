import { createContext, useContext, useEffect, useState } from 'react'
import type { Config, Options, Route, Reply } from './types'
import { formData, read, request, url } from './api'
export const PortalContext=createContext<HTMLElement|null>(null)
export interface ContextValue {config:Config;options:Options;route:Route;go:(r:Route,replace?:boolean)=>void;back:()=>void;dirty:(v:boolean)=>void;refresh:()=>void;revision:number;mutate:(values:Record<string,unknown>,files?:FormData,inventory?:boolean)=>Promise<Reply>}
export const WorkspaceContext=createContext<ContextValue>(null!)
export const useWorkspace=()=>useContext(WorkspaceContext)
export function useData<T>(resource:string,params:Record<string,unknown>={}) {
  const {config,revision}=useWorkspace();const [data,setData]=useState<T>();const [error,setError]=useState('');const [loading,setLoading]=useState(true)
  const key=JSON.stringify(params)
  useEffect(()=>{const controller=new AbortController();setLoading(true);setError('');setData(undefined);read<T>(config,resource,JSON.parse(key),controller.signal).then(setData).catch(e=>{if(e.name!=='AbortError')setError(e.message)}).finally(()=>{if(!controller.signal.aborted)setLoading(false)});return ()=>controller.abort()},[config,resource,key,revision])
  return {data,error,loading}
}
export function mutation(config:Config,options:Options,values:Record<string,unknown>,files?:FormData,inventory=false) {
  const body=formData({request_id:crypto.randomUUID(),...values,csrf_token:inventory?options.inventoryCsrf:options.csrf},files)
  return request(inventory?url(config.inventory,{location_id:values.location_id}):config.watch,body)
}
