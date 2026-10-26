import { Link, usePage } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';
export default function Layout({children}:PropsWithChildren) {
    const { flash } = usePage<{flash:{success?:string}}>().props;
    return <div className="shell">
        <aside className="sidebar"><Link href="/" className="brand"><span className="brand-icon">▶</span><span>AI Video<span className="brand-sub">STUDIO</span></span></Link>
            <div className="nav-label">WORKSPACE</div><Link href="/" className="nav-item active"><span>▦</span> Projects</Link>
            <div className="nav-label future-heading">VIDEO TOOLS</div>
            <div className="nav-item future"><span>◈</span> Sports Highlights <small>Local beta</small></div>
            <div className="nav-label future-heading">COMING LATER</div>
            <div className="nav-item future"><span>◎</span> Sports Analysis <small>Future</small></div>
            <div className="local-note"><span className="status-dot"/> Local workspace<p>Your footage stays on this computer.</p><small>PHASE 1 · ORGANIZE & TRIM</small></div>
        </aside>
        <div className="main"><header className="topbar"><span>Workspace <span className="slash">/</span> Video studio</span><span className="local-badge">● Local mode</span></header>
            {flash?.success && <div className="toast" role="status">✓ {flash.success}</div>}
            <main>{children}</main><footer>AI Video Studio <span>Built for your next cut.</span></footer>
        </div>
    </div>;
}
