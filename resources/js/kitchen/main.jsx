import React from 'react';
import { createRoot } from 'react-dom/client';
import App from './App';
import './app.css';

createRoot(document.getElementById('kitchen-root')).render(
    <React.StrictMode>
        <App />
    </React.StrictMode>,
);
