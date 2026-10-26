import { useEffect, useState } from 'react';
import { time, type Media, type RemixJob } from '../types';

export default function FootballRemix({media}: {media: Media}) {
    const base = `/projects/${media.project_id}/media/${media.id}/remixes`;
    const [jobs, setJobs] = useState<RemixJob[]>([]);
    const [variant, setVariant] = useState<'a'|'b'>('a');
    const [targets, setTargets] = useState({a:30,b:20});
    const [authorized, setAuthorized] = useState(false);
    const [loading, setLoading] = useState(true);
    const [submitting, setSubmitting] = useState(false);
    const [error, setError] = useState('');
    const [pollError, setPollError] = useState('');
    const active = jobs.find(job => job.status === 'pending' || job.status === 'running');
    const target = targets[variant];
    const completed = jobs.filter(job => job.status === 'completed' && job.variant === variant);
    const result = completed[0];

    useEffect(() => {
        let disposed = false;
        let timer: ReturnType<typeof setTimeout>;
        const controller = new AbortController();
        const poll = async () => {
            try {
                const response = await fetch(base, {headers: {Accept: 'application/json'}, signal: controller.signal});
                if (!response.ok) throw new Error('Could not load remix status. Retrying shortly.');
                const data: RemixJob[] = await response.json();
                if (!disposed) { setJobs(data); setPollError(''); }
            } catch (failure) {
                if (!disposed) setPollError(failure instanceof Error ? failure.message : 'Could not load remix status.');
            } finally {
                if (!disposed) { setLoading(false); timer = setTimeout(poll, 2500); }
            }
        };
        void poll();
        return () => { disposed = true; clearTimeout(timer); controller.abort(); };
    }, [base]);

    const generate = async () => {
        setSubmitting(true); setError('');
        try {
            const response = await fetch(base, {
                method: 'POST', credentials: 'same-origin',
                headers: {'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? ''},
                body: JSON.stringify({target_duration: target, variant, authorized_footage: authorized}),
            });
            if (!response.ok) {
                const body = await response.json().catch(() => null);
                throw new Error(response.status === 422 ? Object.values(body?.errors ?? {}).flat().join(' ') : 'Could not queue highlights. Refresh the page and try again.');
            }
            const job: RemixJob = await response.json();
            setJobs(previous => [job, ...previous.filter(item => item.id !== job.id)]);
        } catch (failure) { setError(failure instanceof Error ? failure.message : 'Could not queue highlights.'); }
        finally { setSubmitting(false); }
    };

    return <section className="panel remix-panel" aria-label="Football highlights remix">
        <div className="section-heading"><h2>Make two versions</h2><span className="pill">Local highlight edit</span></div>
        <p>Create two separate MP4 exports from your own or authorized footage. Version A favors visual scene changes; Version B favors audio energy such as crowd/commentary peaks. Each version has its own length and download.</p>
        <p className="remix-disclaimer">These local heuristics suggest cuts; they do not confirm a cricket boundary/wicket or a football attack/goal. Review the exported video before publishing.</p>
        <form onSubmit={event => { event.preventDefault(); void generate(); }}>
            <div className="actions variant-tabs" role="group" aria-label="Choose version"><button type="button" className={variant==='a'?'primary':'secondary'} onClick={()=>setVariant('a')}>Version A · Scene-led</button><button type="button" className={variant==='b'?'primary':'secondary'} onClick={()=>setVariant('b')}>Version B · Audio-led</button></div>
            <label className="remix-duration">Version {variant.toUpperCase()} target length (seconds)<input type="number" min={1} max={900} step={1} required value={target} onChange={event => setTargets(previous=>({...previous,[variant]:event.target.valueAsNumber}))} disabled={submitting}/></label>
            <small className="muted">1–900 seconds (up to 15 minutes), capped at the source duration ({time(media.duration)}).</small>
            <label className="remix-consent"><input type="checkbox" checked={authorized} onChange={event => setAuthorized(event.target.checked)} required/>I own this footage or have permission to edit it.</label>
            <button className="primary" disabled={loading || !!pollError || submitting || jobs.some(job=>(job.status==='pending'||job.status==='running')&&job.variant===variant) || !authorized || !Number.isInteger(target) || target < 1 || target > 900}>{submitting ? 'Queuing…' : jobs.some(job=>(job.status==='pending'||job.status==='running')&&job.variant===variant) ? `Version ${variant.toUpperCase()} queued…` : `Generate Version ${variant.toUpperCase()}`}</button>
        </form>
        {(error || pollError) && <p role="alert" className="error">{error || pollError}</p>}
        {active && <div className="remix-progress" role="status"><strong>Version {active.variant.toUpperCase()} · {active.status === 'pending' ? 'Queued for the local worker' : `Analyzing / rendering · ${active.progress}%`}</strong><progress max={100} value={active.progress}/>{active.status === 'pending' && <small>Processing starts when the local remix worker is running. See the setup steps in docs/football-remix.md.</small>}</div>}
        {!active && jobs.find(job=>job.variant===variant)?.status === 'failed' && <p role="alert" className="error">{jobs.find(job=>job.variant===variant)?.error}</p>}
        {result && <div className="remix-result">
            <label>Version {variant.toUpperCase()} result<select value={result.id} onChange={event=>{const selected=completed.find(job=>job.id===Number(event.target.value));if(selected)setJobs(previous=>[selected,...previous.filter(job=>job.id!==selected.id)]);}}>{completed.map(job => <option key={job.id} value={job.id}>Export #{job.id} · {time(job.analysis?.output_duration ?? job.target_duration)}</option>)}</select></label>
            <video key={result.id} controls preload="metadata" src={`${base}/${result.id}/stream`}/>
            <div className="actions"><a className="secondary" href={`${base}/${result.id}/stream?download=1`}>Download Version {variant.toUpperCase()} MP4</a><span className="muted">{result.analysis?.segments.length} selected segment(s) · H.264 / AAC</span></div>
        </div>}
        {completed.length===0&&<p className="muted">Version {variant.toUpperCase()} has no export yet. Create A, then switch to B and create the second version.</p>}
        <p className="remix-disclaimer">Candidate highlights are not verified goals or events. This does not guarantee copyright clearance or avoidance of Content ID.</p>
    </section>;
}
