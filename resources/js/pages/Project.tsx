import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import Layout from '../components/Layout';
import Timeline from '../components/Timeline';
import FootballRemix from '../components/FootballRemix';
import { Errors } from './Dashboard';
import { time, type Media, type Project } from '../types';
export default function ProjectPage({project}:{project:Project}) {
    const { errors } = usePage().props;
    const [selected,setSelected] = useState<number|null>(project.media[0]?.id ?? null);
    const [editing,setEditing] = useState(false);
    const [localFiles,setLocalFiles] = useState<{name:string;size:number;already_added:boolean}[]>([]);
    const [localLoading,setLocalLoading] = useState(false);
    const [localBusy,setLocalBusy] = useState('');
    const [localError,setLocalError] = useState('');
    const active = project.media.find(m=>m.id===selected) ?? project.media[0];
    const upload = useForm<{video:File|null}>({video:null});
    const edit = useForm({name:project.name,description:project.description || ''});
    const fileInput = useRef<HTMLInputElement>(null);
    const refreshLocal = async () => {
        setLocalLoading(true); setLocalError('');
        try {
            const response = await fetch(`/projects/${project.id}/local-imports`, {headers:{Accept:'application/json'}});
            if (!response.ok) throw new Error('Could not scan the local folder.');
            setLocalFiles(await response.json());
        } catch (error) { setLocalError(error instanceof Error ? error.message : 'Could not scan the local folder.'); }
        finally { setLocalLoading(false); }
    };
    useEffect(()=>{void refreshLocal();},[project.id]);
    return <Layout><Head title={project.name}/><Link href="/" className="back">← All projects</Link>
        <section className="page-heading"><div><div className="eyebrow">PROJECT WORKSPACE</div><h1>{project.name}</h1><p>{project.description || 'Upload your footage and shape your first cut.'}</p></div><div className="actions"><button className="secondary" onClick={()=>setEditing(!editing)}>Edit project</button><button className="danger" onClick={()=>{if(confirm('Delete this project and all its uploaded videos? This cannot be undone.'))router.delete(`/projects/${project.id}`);}}>Delete</button></div></section>
        {editing&&<form className="panel create-form" onSubmit={e=>{e.preventDefault();edit.patch(`/projects/${project.id}`,{onSuccess:()=>setEditing(false)});}}><label>Name<input required maxLength={120} value={edit.data.name} onChange={e=>edit.setData('name',e.target.value)}/></label><label>Description<textarea maxLength={2000} value={edit.data.description} onChange={e=>edit.setData('description',e.target.value)}/></label><Errors errors={edit.errors}/><button className="primary" disabled={edit.processing}>Save project</button></form>}
        {errors.remix && <p className="error" role="alert">{errors.remix}</p>}
        <div className="editor-grid"><div>{active?<Editor key={`${active.id}-${active.updated_at ?? ''}`} media={active} sources={project.media} onSelect={setSelected}/>:<div className="preview-empty"><span className="empty-icon">▷</span><h2>A blank canvas for your footage</h2><p>Upload a video to preview and trim it here.</p></div>}</div>
            <aside className="media-column"><LocalImportPanel files={localFiles} loading={localLoading} busy={localBusy} error={localError || errors.filename || errors.video || ''} refresh={refreshLocal} importFile={name=>{setLocalBusy(name);router.post(`/projects/${project.id}/local-imports`,{filename:name},{preserveScroll:true,onSuccess:()=>void refreshLocal(),onFinish:()=>setLocalBusy('')});}}/><form className="panel upload-panel" onSubmit={e=>{e.preventDefault(); upload.post(`/projects/${project.id}/media`,{forceFormData:true,preserveScroll:true,onSuccess:()=>{upload.reset(); if(fileInput.current)fileInput.current.value='';}});}}><div className="eyebrow">ADD FOOTAGE</div><h2>Upload a video</h2><p>Up to 60 minutes · 2 GB</p><label className="file-picker"><span>↑</span>Choose video<input ref={fileInput} type="file" required accept="video/mp4,video/quicktime,video/webm,video/x-matroska,video/x-msvideo,video/mpeg,video/ogg,.mkv,.avi" disabled={upload.processing} onChange={e=>upload.setData('video',e.target.files?.[0]||null)}/></label><small className="muted">MP4, MOV, WebM, MKV, AVI, MPEG, Ogg. MP4 (H.264) is best for browser preview.</small><Errors errors={upload.errors}/>{upload.progress&&<div aria-live="polite"><progress value={upload.progress.percentage} max={100}/><small>{upload.progress.percentage===100?'Validating video…':`Uploading ${upload.progress.percentage}%`}</small></div>}<button className="primary full" disabled={!upload.data.video||upload.processing}>{upload.processing?'Uploading & validating…':'Upload video'}</button></form>
            <div className="panel media-list"><h2>Project media <span className="count">{project.media.length}</span></h2>{project.media.length===0?<p className="muted">Your uploaded videos will appear here.</p>:project.media.map(m=><button className={`media-row ${active?.id===m.id?'selected':''}`} key={m.id} onClick={()=>setSelected(m.id)}><span className="media-icon">▷</span><span><strong>{m.original_name}</strong><small>{time(m.duration)} · {m.width} × {m.height}</small></span></button>)}</div></aside>
        </div>
    </Layout>;
}
function LocalImportPanel({files,loading,busy,error,refresh,importFile}:{files:{name:string;size:number;already_added:boolean}[];loading:boolean;busy:string;error:string;refresh:()=>void;importFile:(name:string)=>void}) {
    return <section className="panel upload-panel local-import-panel"><div className="eyebrow">LARGE FILE · NO BROWSER UPLOAD</div><h2>Use a video from your PC folder</h2><p>Copy videos into this folder, then refresh and add one to the project:</p><code className="folder-path">D:\AI-Video-Studio\storage\app\private\incoming</code><small className="muted">MP4, MOV, WebM, MKV or AVI · up to 2 GB / 60 minutes. The original stays in this folder.</small><button type="button" className="secondary full" onClick={refresh} disabled={loading}>{loading?'Checking folder…':'↻ Check folder'}</button>{error&&<p className="error" role="alert">{error}</p>}{!loading&&files.length===0&&<p className="muted">No supported videos found yet. Copy a file into the folder above, then check again.</p>}{files.map(file=><div className="local-file-row" key={file.name}><span><strong>{file.name}</strong><small>{(file.size/1048576).toFixed(1)} MB</small></span><button type="button" className={file.already_added?'secondary':'primary'} disabled={file.already_added||!!busy} onClick={()=>importFile(file.name)}>{file.already_added?'Added':busy===file.name?'Checking…':'Add'}</button></div>)}</section>;
}
function Editor({media,sources,onSelect}:{media:Media; sources:Media[]; onSelect:(id:number)=>void}) {
    const video = useRef<HTMLVideoElement>(null);
    const [currentTime,setCurrentTime] = useState(0);
    const [playing,setPlaying] = useState(false);
    const [ready,setReady] = useState(false);
    const [previewing,setPreviewing] = useState(false);
    const [playbackError,setPlaybackError] = useState('');
    const trim = useForm({trim_start:media.trim_start,trim_end:media.trim_end});
    const valid = Number.isFinite(trim.data.trim_start)&&Number.isFinite(trim.data.trim_end)&&trim.data.trim_start>=0&&trim.data.trim_end>trim.data.trim_start&&trim.data.trim_end<=media.duration;
    const url = `/projects/${media.project_id}/media/${media.id}`;
    const seek = (value:number) => {
        if(video.current && video.current.readyState >= 1 && Number.isFinite(value)) {
            const target = Math.max(0, Math.min(value, media.duration, video.current.duration || media.duration));
            video.current.currentTime=target;
            setCurrentTime(target);
        }
    };
    const togglePlayback = () => {
        if (!video.current) return;
        setPreviewing(false);
        if (!video.current.paused) video.current.pause();
        else {
            if (video.current.ended) seek(0);
            void video.current.play().catch(() => setPlaybackError('Playback failed. Try the native preview controls or a browser-compatible MP4.'));
        }
    };
    return <><section className="panel player-panel"><div className="player-heading"><span className="eyebrow">PREVIEW</span><span>{media.width} × {media.height} · {media.codec}</span></div><video ref={video} controls preload="metadata" src={`${url}/stream`} onLoadedMetadata={()=>setReady(true)} onPlay={()=>setPlaying(true)} onPause={()=>setPlaying(false)} onSeeked={()=>setCurrentTime(video.current?.currentTime ?? 0)} onError={()=>{setReady(false);setPlaying(false);setPlaybackError('Your browser cannot play this video codec. Try an MP4 with H.264 video and AAC audio. The original file is still available below.');}} onTimeUpdate={()=>{setCurrentTime(video.current?.currentTime ?? 0);if(previewing&&video.current&&video.current.currentTime>=trim.data.trim_end){video.current.pause();setPreviewing(false);}}} onEnded={()=>{setPreviewing(false);setPlaying(false);}}/>{playbackError&&<p className="error" role="alert">{playbackError}</p>}<div className="video-caption"><strong>{media.original_name}</strong><span>{time(media.duration)} · {(media.size/1048576).toFixed(1)} MB</span></div></section>
        <Timeline media={media} sources={sources} onSelect={onSelect} currentTime={currentTime} playing={playing} ready={ready} trimStart={trim.data.trim_start} trimEnd={trim.data.trim_end} onSeek={value=>{setPreviewing(false);seek(value);}} onToggle={togglePlayback}/>
        <FootballRemix key={media.id} media={media}/>
        <form className="panel trim-panel" onSubmit={e=>{e.preventDefault();trim.patch(url,{preserveScroll:true});}}><div className="section-heading"><h2>Shape your clip</h2><span className="pill">Basic trim</span></div><p>Choose your in and out points. Saved trims keep the original footage intact.</p>
            <div className="timeline" aria-hidden="true"><div style={{left:`${trim.data.trim_start/media.duration*100}%`,width:`${Math.max(0,(trim.data.trim_end-trim.data.trim_start)/media.duration*100)}%`}}/></div><div className="timeline-labels"><span>0:00</span><span>{time(media.duration)}</span></div>
            <div className="trim-fields"><label>IN POINT <span>(seconds)</span><input aria-label="Trim start seconds" type="number" required min={0} max={media.duration} step="any" value={trim.data.trim_start} onChange={e=>trim.setData('trim_start',e.target.valueAsNumber)}/><input aria-label="Trim start slider" type="range" min={0} max={media.duration} step={0.01} value={trim.data.trim_start} onChange={e=>{const value=+e.target.value;trim.setData('trim_start',value);seek(value);}}/><button type="button" className="text-button" onClick={()=>trim.setData('trim_start',Number((video.current?.currentTime||0).toFixed(3)))}>Use playhead</button></label>
            <label>OUT POINT <span>(seconds)</span><input aria-label="Trim end seconds" type="number" required min={0} max={media.duration} step="any" value={trim.data.trim_end} onChange={e=>trim.setData('trim_end',e.target.valueAsNumber)}/><input aria-label="Trim end slider" type="range" min={0} max={media.duration} step={0.01} value={trim.data.trim_end} onChange={e=>trim.setData('trim_end',+e.target.value)}/><button type="button" className="text-button" onClick={()=>trim.setData('trim_end',Number((video.current?.currentTime||0).toFixed(3)))}>Use playhead</button></label></div>
            <Errors errors={trim.errors}/>{!valid&&<p className="error">Set the end after the start, within the video duration.</p>}<div className="trim-summary"><span>Selected duration <strong>{valid?time(trim.data.trim_end-trim.data.trim_start):'—'}</strong></span><span className="muted">Trim markers only · export is not included in Phase 1</span></div>
            <div className="actions"><button className="primary" disabled={!valid||trim.processing}>{trim.processing?'Saving…':'Save trim'}</button><button className="secondary" type="button" disabled={!valid} onClick={()=>{seek(trim.data.trim_start);setPreviewing(true);void video.current?.play().catch(()=>{setPreviewing(false);setPlaybackError('Playback failed. Use a browser-compatible MP4 video.');});}}>▷ Preview selection</button><button type="button" className="text-button" onClick={()=>{trim.setData({trim_start:0,trim_end:media.duration});setPreviewing(false);}}>Reset to full video</button></div>
        </form><div className="media-tools"><a href={`${url}/stream`} download={media.original_name}>Download original ↗</a><button className="danger" onClick={()=>{if(confirm('Permanently remove this video and its trim settings?'))router.delete(url,{preserveScroll:true});}}>Remove video</button></div>
    </>;
}
