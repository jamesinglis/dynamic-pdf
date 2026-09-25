# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a PHP-based dynamic PDF certificate generator that uses FPDF/FPDI library to overlay text on PDF templates. It is designed as a **template project** that can be copied and customized for specific certificate campaigns.

**Core Technology:** PHP 8.2+ with FPDF/FPDI libraries for PDF manipulation.

## Critical Environment Requirements

### DDev Usage
**Always use DDev for local development testing:**
```bash
# Start the development environment
ddev start

# Test certificate generation
curl "https://dynamic-pdf.ddev.site:8443/?name=Test&amount=1000"
```

### File Structure
```
/
├── config.json           # Main configuration (project-specific)
├── config-example.json   # Example configuration template
├── config-override.json  # Local overrides (not committed)
├── custom-callbacks.php  # Project-specific callback functions
├── callbacks.php         # Reusable callback functions
├── helpers.php           # Utility functions (load_config, strip_accents)
├── defaults.php          # Default configuration values
├── index.php             # Main certificate generator
├── test-links.php        # Testing interface
├── rotate-keys.php       # CLI key rotation utility
├── expire-cache.php      # Cache management
├── bulk_create.php       # Batch certificate generation
├── resources/
│   ├── *.pdf             # PDF templates
│   └── fonts/            # Custom fonts
└── cache/                # Generated PDF cache
```

## Configuration System

The system uses JSON-based configuration with callback support:

### config.json Structure
```json
{
  "global": { ... },           // Global settings (debug, borders, caching)
  "environments": { ... },     // Dev/prod environment URLs
  "test_versions": { ... },    // Sample test data
  "hosts": { ... },            // Domain-specific configurations
  "url_arguments": [ ... ],    // URL parameter definitions
  "text_blocks": [ ... ],      // Text positioning and styling
  "text_templates": { ... },   // Reusable text styles
  "fonts": [ ... ]             // Font registrations
}
```

### config-override.json
Use for local development without modifying config.json:
```json
{
  "global": {
    "debug_mode": true,
    "show_borders": true,
    "cache_dynamic_files": false
  }
}
```

## Callback System

Callbacks enable complex conditional logic:
- `pdf_template_callback` - Dynamically select PDF template
- `text_callback` - Modify text content
- `position_callback` - Adjust text positioning
- `toggle_callback` - Show/hide text blocks conditionally
- `validate_callback` - Validate URL parameters
- `sanitize_callback` - Clean input data
- `mutate_callback` - Format output (currency, numbers)

## Positioning System

**Coordinate System:**
- Units: millimeters from top-left corner
- X-axis: left (0) to right
- Y-axis: top (0) to bottom

**Text Block Position:**
```json
{
  "x": 30,           // mm from left edge
  "y": 47,           // mm from top edge
  "width": 237,      // mm width of text box
  "height": 20,      // mm height
  "align": "C"       // L (left), C (center), R (right)
}
```

## Testing Workflow

### Visual Debugging
Enable borders for positioning work:
```json
{
  "global": {
    "debug_mode": true,
    "show_borders": true
  }
}
```

### Generate Test Certificate
```bash
curl "https://dynamic-pdf.ddev.site:8443/?name=John+Smith&amount=1500" -o test.pdf
```

### Test Links Interface
Access the Vue.js testing interface at `/test-links.php`

## Common Tasks

### Adding a New Font
1. Convert TTF to FPDF format:
```bash
cd resources/fonts
php ../../vendor/setasign/fpdf/makefont/makefont.php /path/to/font.ttf iso-8859-1
```

2. Register in config.json:
```json
{
  "fonts": [
    { "name": "FontName", "style": "", "file": "font-name.php" }
  ]
}
```

### Rotating Security Keys
```bash
php rotate-keys.php
```

### Clearing Cache
```bash
php expire-cache.php
# Or via web with key: https://domain.com/expire-cache.php?key=YOUR_KEY
```

## Creating a New Project

1. Copy this entire directory to a new project folder
2. Update `config.json` with project-specific settings
3. Replace PDF templates in `resources/`
4. Add project-specific callbacks to `custom-callbacks.php`
5. Update fonts if needed
6. Configure DDev with appropriate hostname

## Troubleshooting

### Issue: PDF file is tiny (19 bytes)
**Cause:** DDev not running or wrong URL
**Fix:** Run `ddev start` and verify hostname

### Issue: Text in wrong position
**Fix:** Enable `show_borders: true` and adjust coordinates

### Issue: Function not found
**Fix:** Check callback function names match config.json exactly

### Issue: Variables not replaced (seeing %%NAME%%)
**Cause:** Using text_callback without manual variable replacement
**Fix:** Add `str_replace()` calls in callback function

## PHP 8.2+ Compatibility Notes

This project uses modern PHP features:
- `NumberFormatter` for currency formatting (replaces deprecated `money_format`)
- `mb_convert_encoding` for character encoding (replaces deprecated `utf8_decode`)
- Arrow functions for callbacks
- Typed function parameters
