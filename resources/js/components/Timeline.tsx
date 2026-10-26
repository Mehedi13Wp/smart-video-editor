import { useRef, useState, type PointerEvent } from 'react';
import { time, type Media } from '../types';

type Props = {
    media: Media;
    sources: Media[];
    currentTime: number;
    playing: boolean;
    ready: boolean;
    trimStart: number;
    trimEnd: number;
    onSelect: (id: number) => void;
    onSeek: (seconds: number) => void;
    onToggle: () => void;
};

// Source-time UI only: no sequence model or additional media decoders.
export default function Timeline(props: Props) {
    const { media, sources, currentTime, playing, ready, trimStart, trimEnd, onSelect, onSeek, onToggle } = props;
    const [zoom, setZoom] = useState(1);
    const surface = useRef<HTMLDivElement>(null);
    const duration = Math.max(media.duration, 0.001);
    const position = Math.min(100, Math.max(0, currentTime / duration * 100));
    const tickCount = Math.min(80, Math.round(8 * zoom));
    const scrub = (event: PointerEvent<HTMLDivElement>) => {
        if (!ready || !surface.current) return;
        const rect = surface.current.getBoundingClientRect();
        onSeek(Math.max(0, Math.min(duration, (event.clientX - rect.left) / rect.width * duration)));
    };
    const validTrim = Number.isFinite(trimStart) && Number.isFinite(trimEnd) && trimEnd > trimStart && trimStart >= 0 && trimEnd <= duration;

    return <section className="panel sequence-panel" aria-label="Timeline editor">
        <div className="sequence-toolbar">
            <div><h2>Timeline <span className="pill">UI foundation</span></h2><small>Selected source · original media time</small></div>
            <div className="sequence-transport">
                <button type="button" className="secondary" disabled={!ready} onClick={() => onSeek(0)} aria-label="Seek to start">|◀</button>
                <button type="button" className="primary" disabled={!ready} onClick={onToggle} aria-label={playing ? 'Pause preview' : 'Play preview'}>{playing ? 'Pause' : 'Play'}</button>
                <output aria-label="Playback position">{time(currentTime)} / {time(duration)}</output>
            </div>
            <label className="sequence-zoom">Zoom {zoom}×<input type="range" min="1" max="8" step="0.5" value={zoom} onChange={e => setZoom(Number(e.target.value))}/></label>
        </div>
        <div className="sequence-sources" aria-label="Timeline source clips">
            {sources.map(source => <button type="button" key={source.id} aria-pressed={source.id === media.id} onClick={() => onSelect(source.id)} title={source.original_name}>
                <strong>{source.original_name}</strong><span>{time(source.duration)}</span>
            </button>)}
        </div>
        <div className="sequence-body">
            <div className="sequence-headers" aria-hidden="true"><div>TIME</div><div><strong>V1</strong><span>Video</span></div><div><strong>A1</strong><span>Audio</span></div></div>
            <div className="sequence-scroll">
                <div ref={surface} className="sequence-surface" style={{width: `${zoom * 100}%`}}
                    onPointerDown={event => { if (event.button !== 0 || !ready) return; event.currentTarget.setPointerCapture(event.pointerId); scrub(event); }}
                    onPointerMove={event => { if (event.currentTarget.hasPointerCapture(event.pointerId)) scrub(event); }}
                    onPointerUp={event => { if (event.currentTarget.hasPointerCapture(event.pointerId)) { scrub(event); event.currentTarget.releasePointerCapture(event.pointerId); } }}>
                    <div className="sequence-ruler" aria-hidden="true">{Array.from({length: tickCount}, (_, index) => <span key={index} style={{left: `${index / tickCount * 100}%`}}>{time(index / tickCount * duration)}</span>)}</div>
                    <div className="sequence-lane sequence-video">
                        <div className="sequence-clip" title={media.original_name}><strong>{media.original_name}</strong><span>{time(duration)} · source video</span></div>
                        {validTrim && <div className="sequence-trim" style={{left: `${trimStart / duration * 100}%`, width: `${(trimEnd - trimStart) / duration * 100}%`}}><span>Trim selection</span></div>}
                    </div>
                    <div className="sequence-lane sequence-audio"><span>No separate audio clips · source audio plays in preview</span></div>
                    <div className="sequence-playhead" style={{left: `${position}%`}} aria-hidden="true"><span/></div>
                </div>
            </div>
        </div>
        <label className="sequence-seek">Playhead<input aria-label="Timeline playhead seconds" type="range" min={0} max={duration} step={0.01} value={Math.min(currentTime, duration)} disabled={!ready} onChange={e => onSeek(Number(e.target.value))}/></label>
        <p className="sequence-note">Click or drag to seek; use the playhead slider for keyboard control. Choose a source clip above to preview it. A1 is a placeholder. Clip arrangement, sequence saving, multi-track rendering and export are not implemented.</p>
    </section>;
}
