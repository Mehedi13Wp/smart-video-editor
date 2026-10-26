import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import Dashboard from './pages/Dashboard';
import ProjectPage from './pages/Project';
import '../css/app.css';
createInertiaApp({
    title: title => `${title} · AI Video Studio`,
    resolve: name => { if(name === 'Dashboard') return Dashboard; if(name === 'Project') return ProjectPage; throw new Error(`Unknown page: ${name}`); },
    setup({el, App, props}) { createRoot(el).render(<App {...props}/>); },
    progress:{color:'#a3e635'},
});
