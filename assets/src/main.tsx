import { createRoot } from 'react-dom/client';

import { App } from './App';
import './styles.css';

const root = document.getElementById('wp-pfagent-root');

if (root) {
  createRoot(root).render(<App />);
}
