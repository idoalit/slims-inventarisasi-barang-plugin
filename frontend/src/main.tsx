import React from 'react'
import { createRoot, type Root } from 'react-dom/client'
import { App } from './App'
import { PortalContext } from './context'
import type { Config } from './types'
import './index.css'
interface Runtime {scan:()=>void}
declare global {interface Window {inventoryWorkspaceRuntime?:Runtime}}
if(window.inventoryWorkspaceRuntime)window.inventoryWorkspaceRuntime.scan()
else {
 const roots=new Map<HTMLElement,Root>()
 const scan=()=>{
  for(const [host,root] of roots)if(!host.isConnected){root.unmount();roots.delete(host)}
  document.querySelectorAll<HTMLElement>('[data-inventory-workspace]').forEach(host=>{
   if(roots.has(host))return
   try {
    const config=JSON.parse(host.dataset.config!) as Config
    const shadow=host.attachShadow({mode:'open'});host.textContent=''
    const stylesheet=document.createElement('link');stylesheet.rel='stylesheet';stylesheet.href=host.dataset.css!
    shadow.append(stylesheet)
    const mount=document.createElement('div');const portal=document.createElement('div');portal.dataset.inventoryPortals='';shadow.append(mount,portal)
    const root=createRoot(mount);roots.set(host,root)
    root.render(<PortalContext.Provider value={portal}><App config={config} host={host}/></PortalContext.Provider>)
   }catch(e){host.textContent='Aplikasi inventaris gagal dimuat. Muat ulang halaman.';console.error(e)}
  })
 }
 window.inventoryWorkspaceRuntime={scan}
 new MutationObserver(scan).observe(document.body,{childList:true,subtree:true})
 scan()
}
