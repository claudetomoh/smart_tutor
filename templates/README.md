# Templates Directory Structure

This directory contains modular HTML templates that are assembled into `index.html` using the build script.

## Directory Structure

```
templates/
├── partials/          # Reusable page components
│   ├── head.html      # Document head (meta, fonts, styles)
│   ├── header.html    # Site navigation header
│   └── footer.html    # Site footer with links
├── sections/          # Main content sections
│   ├── hero.html      # Hero landing section
│   ├── about.html     # About section with metrics
│   ├── team.html      # Team members section
│   ├── gallery.html   # Image gallery section
│   ├── find-tutor.html # Tutor directory section
│   ├── become-tutor.html # Tutor recruitment section
│   ├── testimonials.html # Customer testimonials
│   └── contact.html   # Contact form and info
└── layout.html        # Base page layout (optional reference)
```

## How It Works

1. **Edit individual sections**: Modify files in `templates/sections/` to update specific page areas
2. **Edit partials**: Modify `templates/partials/` for shared components (header, footer)
3. **Build**: Run `npm run build:html` (or `node scripts/build.js`) to generate `index.html`
4. **Watch mode**: Run `npm run watch:html` to automatically rebuild on file changes

## Benefits

- **Maintainability**: Each section is in its own file, making it easier to find and edit
- **Reusability**: Partials can be shared across multiple pages
- **Collaboration**: Multiple developers can work on different sections simultaneously
- **Version Control**: Easier to track changes to specific sections in git

## Adding a New Section

1. Create a new file in `templates/sections/` (e.g., `new-section.html`)
2. Add the section name to the `sections` array in `scripts/build.js`
3. Run `npm run build:html` to rebuild `index.html`

## Notes

- The generated `index.html` file should NOT be edited directly
- Always edit template files and rebuild
- The build script preserves all comments and formatting

