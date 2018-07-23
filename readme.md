# Dynamic PDF generator

* Author: James Inglis <hello@jamesinglis.no>
* URL: https://github.com/jamesinglis/dynamic-pdf
* License: MIT (i.e. do whatever you want with it, but no warranty!)


## Overview

This PHP-based solution generates a dynamic PDF from a PDF base ("template") and one of more dynamic text blocks, based on URL arguments. This solution has been the basis for dynamic certificates and posters. The URL argument structure is open by design, to allow easy population by mail merge tools.

For example: http://example.com/?name=James%20Inglis - the "name" argument can be validated and sanitized and used as a placeholder replacement value in the dynamic text block configuration.

## Features

* TBA

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

### URL Arguments

* "argument" - name of the URL argument
* "type" - "string", "integer", "float", "custom"
* "default" - static default value
* "default_callback" - callable function to define the default value
* "sanitize_callback" - callable function to sanitize the raw value
* "validate_callback" - callable function to validate the sanitized value
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

* sanitize_process_name_filter - Standard function for sanitizing a name
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

### strip_accents

Strip the accents from the string and replace with the nearest ASCII equivalent (e.g. "Jämés" becomes "James").

## To Do in Future
(if there's a demand for it)

* Clean up the code so it's cleaner and more extensible
    * Not the best coding - this started as a quick and dirty solution!


## Questions and Answers

### What's the difference between a sanitize function and a mutation function?

A sanitize function is run before validation. The mutation function is run after the validation.

The sanitize function will run before the variable name is used in the cache filename. The mutate function will not affect the variable name when it is used in the cache filename.

### When should I mutate a value and when should I just do some custom formatting in the text output?

A mutate function will affect all instances that a value is used. At present, there is no conditional mutation so any one-off formatting needs to be done in the text output.


## Version history

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