# Dynamic PDF Generator

**Version 1.1.0** | PHP 8.2+

* Author: James Inglis <hello@jamesinglis.no>
* URL: https://github.com/jamesinglis/dynamic-pdf
* License: MIT (i.e. do whatever you want with it, but no warranty!)

## Table of Contents

- [Overview](#overview)
- [Features](#features)
- [Requirements](#requirements)
- [Getting Started](#getting-started)
- [Configuration](#configuration)
- [New in 1.0.0](#new-in-100)
- [Callbacks](#callbacks)
- [Helpers](#helpers)
- [Version History](#version-history)

## Overview

This PHP-based solution generates a dynamic PDF from a PDF base ("template") and one or more dynamic text blocks, based on URL arguments. This solution has been the basis for dynamic certificates and posters. The URL argument structure is open by design, to allow easy population by mail merge tools.

For example: `https://example.com/?name=James%20Inglis` - the "name" argument can be validated and sanitized and used as a placeholder replacement value in the dynamic text block configuration.

## Features

* JSON-based configuration with config-override support
* Multi-environment support (dev/prod) with environment-specific URLs
* Interactive testing interface (test-links.php)
* Flexible callback system for validation, sanitization, and formatting
* PDF template switching via callbacks
* Text and image block positioning with fit-to-width support
* Caching, invalidated automatically when the config or the core version changes
* Accented names printed as typed (José, Søren, Zoë), converted once for the font at the render point
* CLI key rotation utility
* Host-based certificate variants

## Requirements

* **PHP 8.2+** with extensions:
  * `intl` (for NumberFormatter)
  * `mbstring` (for character encoding)
  * `iconv` (for converting text to the fonts' cp1252 encoding)
  * `gd` or `imagick` (for image processing)
* Composer for dependency management
* Apache with mod_rewrite (for .htaccess security)

## Getting Started

To use this solution, you'll need to be comfortable with running PHP scripts. It doesn't have a graphical user interface, however you can run this from the command line or from a web browser.

* Clone this repository to a location accessible by your web server
* Run `composer install` to install the library dependencies
* Open config.json and edit/add to the existing URL arguments and text blocks
* Copy your PDF template file to `<repository_root>/resources/`
* Update the PDF file name in config.json under hosts > default > pdf_template
* Prepare any custom fonts you require using [http://www.fpdf.org/makefont/](http://www.fpdf.org/makefont/) and store the resulting files in `<repository_root>/resources/fonts`
* Update the fonts configuration config.json with new fonts

## Configuration

### Global Configuration

* "debug_mode" - true / false
    * Enables standard PHP debugging to screen
* "show_borders" - true / false
    * Adds a border to all text block elements on the PDF - very useful for setting up elements
* "validate_arguments" - true / false
    * Set this to true if you want to validate arguments - if validation fails, visitors will be taken to the redirect location
* "cache_dynamic_files" - true / false
    * Enable caching for the files that are generated. Make sure you have enough disk space - cache files are not automatically purged!
    
### Hosts

At minimum, the Hosts config needs to have a "default" value set up. Any other hosts you configure need the key to match the domain.

* "slug" - short identifying alphanumeric string, used in cached file names
* "pdf_template" - path to template filename, relative to repository root
* "pdf_orientation" - "P" (portrait) or "L" (landscape)
* "redirect_location" - public URL to redirect to upon failure 
* "validate_arguments_callback" - (optional) host-level validation callback, run after every URL argument has passed its own validation; see [Host-Level Validation Callback](#host-level-validation-callback)

### URL Arguments

* "argument" - name of the URL argument
* "type" - "string", "integer", "float", "custom"
* "default" - static default value
* "default_callback" - callable function to define the default value
* "sanitize_callback" - callable function to sanitize the raw value
* "validate_callback" - callable function to validate the sanitized value
* "validate_for_hosts" - array of hosts that require this argument to validate (empty array matched ALL hosts)
* "mutate_callback" - callable function to mutate the sanitized, validated value

### Text Blocks

### Text Templates

### Fonts

* "name" - name of the font (references in the "text_templates" configuration)
* "style" - "" (plain), "b" (bold) or "i" (italic)
* "file" - (string) .php filename of the font stored in `<repository_root>/resources/fonts`

## Callbacks

This solution has implemented callback functionality where possible to enable more advanced logic that would be impractical to build into the standard processing.

Each relevant callback needs to be a callable function, and can be a standard PDF function or a custom function. callbacks.php contains a number of commonly used callbacks, and are named [type]_[description]:

* sanitize_process_name_filter - Standard function for sanitizing a name: keeps Latin-script letters with their accents, digits, spaces and . , ' ’ - ( ) &
* validate_not_empty - Ensure that the input is not empty
* validate_int_under_999999 - Ensure that the integer is between 0 and 999999
* validate_float_under_999999 - Ensure that the float is between 0 and 999999
* mutate_dollar_amount - Formats a number as a currency amount (according to locale)

Custom functions can be added to callbacks-custom.php.

### URL Argument: Default Value

Allows you to set the default value for a URL argument

Arguments:
 * array $url_argument - all configuration values for this URL argument
 
Returns: (string) default value

```php
function default_custom_callback($url_argument)
{
    return $url_argument["default"];
}
```

### URL Argument: Sanitize Value

Allows you to sanitize the value received from the URL. Intended solely for data sanitization, not for mutation (see mutation callback). Runs *before* validation.

Arguments:
* string $input - raw value from URL argument

Returns: (string) sanitized value

```php
function sanitize_custom_callback($input)
{
    return $input;
}
```

### URL Argument: Validate Value

Allows you to verify whether or not the value is valid via a validation callback that returns true or false.

Arguments:
* string $input - sanitized value
* array $url_argument - all configuration values for this URL argument
 
Returns: (bool) validation result

```php
function validate_custom_callback($input, $url_argument)
{
    return !empty($input);
}
```

### Host-Level Validation Callback

Set a host's `validate_arguments_callback` to validate the URL arguments together, for rules that span several of them. It runs only after every argument has passed its own validation, and its result is ANDed with theirs: returning false fails the request (visitors go to the redirect location), and it can never rescue an argument that already failed.

Arguments:
* array $url_arguments - processed URL arguments, keyed by name (each with "original" and "active" values)
* array $host_configuration_array - this host's configuration
* string $host_name - the matched host key

Returns: (bool) validation result

```php
function validate_arguments_custom_callback(array $url_arguments, array $host_configuration_array, string $host_name): bool
{
    return true;
}
```

### URL Argument: Mutate Value

Allows you to modify the value of a value received from the URL. Runs *after* validation.

Arguments:
 * string $input - sanitized value
 * array $url_argument - all configuration values for this URL argument
 
Returns: (string) mutated value

Example:

```php
function mutate_custom_callback($input, $url_argument)
{
    return $input;
}
```

### PDF Template Callback

Arguments:

* array $host_configuration_array - configuration for the current host
* array $url_arguments_array - all URL arguments, sanitized and mutated

Returns: (string) relative path to PDF template

Example:

```php
function pdf_template_custom_callback($host_configuration_array, $url_arguments_array){
    return $host_configuration_array["pdf_template"];
}
```

### Text Block Toggle Callback

Arguments:

* array $text_block - all configuration for the current text block
* array $url_arguments_array - all URL arguments, sanitized and mutated

Returns: (bool) toggle result

Example:

```php
function text_block_toggle_custom_callback($text_block, $url_arguments)
{
    return true;
}
```

### Text Block Text Callback

Arguments:

* array $text - text supplied by the config before placeholders are processed
* array $url_arguments_array - all URL arguments, sanitized and mutated

Returns: (string) text before placeholders are processed 

Example:

```php
function text_block_text_custom_callback($text, $url_arguments)
{
    return '';
}
```

## Helpers

Helper functions that don't belong anywhere else, but it's worth documenting:

### pdf_text

Converts UTF-8 text to the cp1252 bytes the fonts expect. Core calls it once for every text block, just before the text is measured and drawn, so names keep their accents ("José" prints as "José"). Characters cp1252 can't hold are transliterated where the locale allows ("Łukasz" prints as "Lukasz") and dropped otherwise. Callbacks work on UTF-8 and must never convert text themselves, or it is converted twice and garbled.

### fallback_missing_glyphs

Runs straight after `pdf_text` for every text block. If the text block's font has no glyph for an accented letter (some fonts are subsets with ASCII only), that letter prints as its plain ASCII form ("José" as "Jose", "Æ" as "AE") instead of a blank. Fonts with full coverage are unaffected.

### filter_name_characters

Keeps only Latin-script letters with their accents, digits, spaces and . , ' ’ - ( ) &. It filters characters and never converts encodings. Letters from other scripts are removed because the fonts can't print them, so a name written only in them fails validation instead of producing a blank certificate.

### strip_accents

Deprecated since 1.1.0: an alias of `filter_name_characters`, kept for older custom callbacks. It no longer strips accents.

## New in 1.0.0

### Configuration Override System

Create a `config-override.json` file for local development settings without modifying the main config:

```json
{
  "global": {
    "debug_mode": true,
    "show_borders": true,
    "cache_dynamic_files": false
  }
}
```

### Multi-Environment Support

Configure different environments (dev/prod) with their own URLs and access keys:

```json
{
  "environments": {
    "dev": {
      "url": "https://project.ddev.site:8443",
      "label": "Development",
      "expose": true,
      "visible": true,
      "key": ""
    },
    "prod": {
      "url": "https://certificate.example.com",
      "label": "Production",
      "expose": true,
      "visible": true,
      "key": "YOUR_SECRET_KEY"
    }
  }
}
```

### Test Links Interface

Access `/test-links.php` for an interactive Vue.js-based testing interface that:
- Generates test URLs from `test_versions` configuration
- Supports multiple environments with key-based authentication
- Provides email-friendly formatted text with copy buttons
- Includes dark mode support

Access rule (1.1.0): a host listed in `environments` needs that environment's key, or none if its key is empty (e.g. ddev). Any other host that reaches the site (`www.`, `phpstack-*.cloudwaysapps.com`, an old campaign hostname) needs one of the configured keys, and is closed when no environment has a key.

### Host Active Flag

Hosts can now be marked as inactive, redirecting visitors to a specified location:

```json
{
  "hosts": {
    "default": {
      "active": true,
      "redirect_location": "https://example.com"
    }
  }
}
```

### Key Rotation Utility

Use `php rotate-keys.php` from the command line to rotate authentication keys for all environments and the cache expiry key. Old keys are preserved with timestamps.

### PHP 8.2+ Compatibility

- Replaced deprecated `money_format()` with `NumberFormatter`
- Replaced deprecated `utf8_decode()` with `mb_convert_encoding()`
- Updated Symfony HttpFoundation to 6.4
- Updated Guzzle to 7.x
- Added arrow function syntax for callbacks

### Security Improvements

- Added `.htaccess` with Apache 2.4+ security directives
- Blocks access to sensitive files (.json, .md, .lock, etc.)
- Restricts PHP execution to index.php and test-links.php only
- Security headers for XSS and clickjacking protection

### CLAUDE.md

Added AI assistant guidance document for Claude Code integration.

## Questions and Answers

### What's the difference between a sanitize function and a mutation function?

A sanitize function is run before validation. The mutation function is run after the validation.

The sanitize function will run before the variable name is used in the cache filename. The mutate function will not affect the variable name when it is used in the cache filename.

### When should I mutate a value and when should I just do some custom formatting in the text output?

A mutate function will affect all instances that a value is used. At present, there is no conditional mutation so any one-off formatting needs to be done in the text output.


## Version History

### 1.1.0 (2026-09-29)
* **Names keep their accents:** text stays UTF-8 through every callback and is converted to cp1252 exactly once, at the render point (`pdf_text()`), so José, Søren and Zoë print as typed; characters cp1252 can't hold are transliterated (Łukasz → Lukasz) or dropped, never printed as "?". Fixes names garbled by the older `custom_utf8_decode` direction
* A letter the text block's font has no glyph for prints as plain ASCII (é as e) instead of a blank (`fallback_missing_glyphs()`)
* The name filter (`filter_name_characters()`) keeps Latin-script letters with their accents, `&` and the curly apostrophe `’` (a name only in another script fails validation rather than rendering blank); `strip_accents()` is a deprecated alias and `custom_utf8_decode()` is removed
* URL arguments of an unlisted type use `FILTER_UNSAFE_RAW`, so `O'Brien` and `&` are no longer HTML-escaped into the PDF
* Host-level validation callback (`validate_arguments_callback`), ANDed with per-argument validation
* `test-links.php` requires a key on every host not listed in `environments`; `prod*`/`dev*` environment colours; `label` and `for_hosts` are no longer turned into URL arguments
* `rotate-keys.php` reads `config.json` raw (never bakes in `config-override.json`) and accepts `--no-preserve`
* `.htaccess` blocks URL-encoded `resources/` requests and bare protected directory paths
* The core version is part of the cache key, so a release never serves PDFs cached by the previous one, and the key hashes the exact argument values, so names differing only in accented letters never share a cached PDF
* `composer.lock` is tracked; `setasign/fpdf` is pinned to 1.8.x (1.9 deprecates the PHP font definitions); `bulk_create.php` and Guzzle are removed; PHPUnit tests cover the core helpers

### 1.0.1 (2026-09-25)
* Turns `display_errors` off before anything loads, so errors never disclose server paths (debug mode still enables it)
* Array-valued URL arguments (e.g. `?name[]=x`) no longer throw; they fall back to the default and fail validation
* `mutate_dollar_amount` and `mutate_numeric` no longer throw on blank or non-numeric input under PHP 8

### 1.0.0 (2025-12-28)
**Major update with PHP 8.2+ compatibility and new features**

* **Breaking Changes:**
  * Requires PHP 8.2+ (drops support for PHP 7.x)
  * Updated Symfony HttpFoundation to 6.4 (changed `query->filter()` signature)
  * Updated Guzzle to 7.x (changed request API)

* **New Features:**
  * Configuration override system (`config-override.json`)
  * Multi-environment support with `environments` section
  * Test versions configuration for `test-links.php`
  * Interactive Vue.js testing interface (`test-links.php`)
  * CLI key rotation utility (`rotate-keys.php`)
  * Host `active` flag with redirect support
  * `toggle_for_hosts` support in text blocks
  * Config hash in cache filenames for automatic invalidation
  * Debug output for failed validation in debug mode

* **PHP 8.2+ Compatibility:**
  * Replaced deprecated `money_format()` with `NumberFormatter`
  * Replaced deprecated `utf8_decode()` with `mb_convert_encoding()`
  * Added arrow function syntax for filter callbacks
  * Added type hints to function parameters

* **Security:**
  * Added `.htaccess` with Apache 2.4+ directives
  * Blocks access to sensitive configuration files
  * Security headers for XSS and clickjacking protection

* **Documentation:**
  * Added `CLAUDE.md` for AI assistant integration
  * Updated `config-example.json` with all new sections
  * Comprehensive README update

### 0.4.1 (2018-11-20)
* Adds ability to validate an argument for certain hosts only
* Update example config file

### 0.4 (2018-09-24)
* Flesh out callbacks throughout process and add image processing
* Adds bulk creation script

### 0.3 (2018-07-24)
* Adds cache expiry script
* Adds misc callbacks
* Adds text callbacks to text blocks

### 0.2 (2017-11-22)
* Sets the locale as per config
* Ensure cache filenames are being generated with non-mutated values
* Resolve bug with float values not being parsed properly
* Adds an example configuration file
* Adds some questions and answers to the readme and inline code documentation
* Adds text block toggle callback functionality
* Amends to readme.md file

### 0.1 (2017-11-05)

* Initial version