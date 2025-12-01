/**
 * Build script to assemble modular HTML files into index.html
 * Reads partials and sections, then combines them into a complete page
 */

import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

const templatesDir = path.join(__dirname, '..', 'templates');
const outputFile = path.join(__dirname, '..', 'index.html');

// Read partial files
function readFile(filePath) {
    try {
        return fs.readFileSync(filePath, 'utf8');
    } catch (error) {
        console.error(`Error reading ${filePath}:`, error.message);
        return '';
    }
}

// Order of sections to include
const sections = [
    'hero',
    'about',
    'team',
    'gallery',
    'find-tutor',
    'become-tutor',
    'testimonials',
    'contact'
];

// Build the HTML
function build() {
    console.log('Building index.html from modular templates...');
    
    // Read partials
    const head = readFile(path.join(templatesDir, 'partials', 'head.html'));
    const header = readFile(path.join(templatesDir, 'partials', 'header.html'));
    const footer = readFile(path.join(templatesDir, 'partials', 'footer.html'));
    
    // Read all sections
    let sectionsHTML = '';
    sections.forEach(sectionName => {
        const sectionPath = path.join(templatesDir, 'sections', `${sectionName}.html`);
        const sectionContent = readFile(sectionPath);
        if (sectionContent) {
            sectionsHTML += '\n        ' + sectionContent.split('\n').join('\n        ').trim() + '\n';
        } else {
            console.warn(`Warning: Section ${sectionName}.html not found`);
        }
    });
    
    // Assemble the complete HTML
    const html = `<!doctype html>
<!-- SmartTutor Connect - Landing Page
     Main marketing and information page for the online tutoring platform.
     Includes hero section, about, team, gallery, tutor directory, testimonials, and contact form.
     
     This file is auto-generated from modular templates.
     To edit sections, modify files in templates/sections/
     To edit partials, modify files in templates/partials/
     Run: npm run build (or node scripts/build.js) to rebuild
-->
<html lang="en">

${head}

<body>
    <!-- Accessibility: Skip link for keyboard navigation -->
    <a class="skip-link" href="#main-content">Skip to content</a>

${header}

    <!-- Main content area -->
    <main id="main-content">${sectionsHTML}    </main>

${footer}
</body>

</html>`;

    // Write to index.html
    fs.writeFileSync(outputFile, html, 'utf8');
    console.log('✅ Successfully built index.html');
    console.log(`   Sections included: ${sections.length}`);
}

// Run build
build();

