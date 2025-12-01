/**
 * Watch script to rebuild HTML when template files change
 */

import fs from 'fs';
import path from 'path';
import { exec } from 'child_process';
import { fileURLToPath } from 'url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

const templatesDir = path.join(__dirname, '..', 'templates');
const buildScript = path.join(__dirname, 'build.js');

console.log('👀 Watching templates directory for changes...');
console.log('   Press Ctrl+C to stop\n');

// Watch for changes in templates directory
fs.watch(templatesDir, { recursive: true }, (eventType, filename) => {
    if (filename && (filename.endsWith('.html') || filename.endsWith('.js'))) {
        console.log(`📝 Detected change: ${filename}`);
        console.log('   Rebuilding...');
        
        exec(`node "${buildScript}"`, (error, stdout, stderr) => {
            if (error) {
                console.error('❌ Build error:', error);
                return;
            }
            console.log(stdout);
            console.log('✅ Rebuild complete\n');
        });
    }
});

