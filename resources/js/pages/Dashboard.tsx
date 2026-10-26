import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import Layout from '../components/Layout';
import type { Project } from '../types';
export default function Dashboard({projects}:{projects:Project[]}) {
    const [creating,setCreating] = useState(false);
    const [search,setSearch] = useState('');
    const form = useForm({name:'',description:''});
    const visible = projects.filter(p=>`${p.name} ${p.description || ''}`.toLowerCase().includes(search.toLowerCase()));
    return <Layout><Head title="Projects"/>
        <section className="page-heading"><div><div className="eyebrow">YOUR CREATIVE WORKSPACE</div><h1>Good footage. Great stories.</h1><p>Bring your videos together. Find the moments that matter.</p></div><button className="primary" onClick={()=>setCreating(!creating)}>＋ New project</button></section>
        {creating && <form className="panel create-form" onSubmit={e=>{e.preventDefault(); form.post('/projects');}}><h2>Create a project</h2>
            <label>Project name<input autoFocus maxLength={120} required placeholder="e.g. Weekend match — first cut" value={form.data.name} onChange={e=>form.setData('name',e.target.value)}/></label>
            <label>Description <span className="muted">(optional)</span><textarea maxLength={2000} placeholder="What's the story?" value={form.data.description} onChange={e=>form.setData('description',e.target.value)}/></label>
            <Errors errors={form.errors}/><div className="actions"><button className="primary" disabled={form.processing}>Create project</button><button type="button" className="secondary" onClick={()=>setCreating(false)}>Cancel</button></div></form>}
        <section className="stats"><div className="stat"><span>Total projects</span><strong>{projects.length.toString().padStart(2,'0')}</strong><small>Your stories in progress</small></div><div className="stat"><span>Video library</span><strong>{projects.reduce((sum,p)=>sum+(p.media_count||0),0).toString().padStart(2,'0')}</strong><small>Uploaded and validated</small></div><div className="stat accent"><span>Make every second count</span><strong>15 <em>min</em></strong><small>Highlight length · source up to 60 min / 2 GB</small></div></section>
        <div className="section-heading"><h2>Your projects <span className="count">{projects.length}</span></h2><input className="search" aria-label="Search projects" placeholder="Search projects…" value={search} onChange={e=>setSearch(e.target.value)}/></div>
        {visible.length ? <section className="project-grid">{visible.map((p,i)=><Link className="project-card" key={p.id} href={`/projects/${p.id}`}><div className={`card-art art-${i%3}`}><span className="art-lines"/><span className="play-icon">▶</span><span className="card-tag">PROJECT {String(p.id).padStart(2,'0')}</span></div><div className="card-body"><div className="card-title"><h3>{p.name}</h3><span>↗</span></div><p>{p.description || 'A new story starts here.'}</p><div className="card-meta"><span>{p.media_count} video{p.media_count===1?'':'s'}</span><span>{new Date(p.updated_at).toLocaleDateString(undefined,{month:'short',day:'numeric'})}</span></div></div></Link>)}</section> : <section className="empty panel"><div className="empty-icon">▤</div><h2>{search?'No matching projects':'Your next story starts here'}</h2><p>{search?'Try a different project name.':'Create a project, upload your footage, and make your first trim.'}</p>{!search&&<button className="primary" onClick={()=>setCreating(true)}>＋ Create your first project</button>}</section>}
        <section className="roadmap"><div><div className="eyebrow">NEXT UP</div><h2>Refine each sports edit.</h2></div><div><h3>Review key moments <span className="pill">Planned</span></h3><p>Preview and approve suggested moments before rendering.</p></div><div><h3>Sports Analysis <span className="pill">Future module</span></h3><p>Verified match events and detailed analysis remain planned.</p></div></section>
    </Layout>;
}
export function Errors({errors}:{errors:Record<string,string>}) { return <>{Object.entries(errors).map(([key,message])=><p className="error" role="alert" key={key}>{message}</p>)}</>; }
