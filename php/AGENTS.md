# AGENTS.md

Scoped rules for all PHP code under this directory.

## WordPress Coding Standards for PHP

All changes to `.php` files in this tree MUST conform to the official
WordPress PHP coding standards:

- https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/
- https://developer.wordpress.org/coding-standards/wordpress-coding-standards/inline-documentation-standards/php/

In practice this means following the rules enforced by
`WordPress-Coding-Standards` (WPCS) via PHP_CodeSniffer, including:

### Formatting

- Indent with **tabs**, not spaces. Align with tabs; only use spaces for
  mid-line alignment where tabs cannot express it.
- Use Unix line endings (`\n`) and end every file with a single newline.
- No trailing whitespace.
- Opening PHP tag `<?php` on its own line; no closing `?>` at end of pure PHP
  files.
- Use full `<?php ... ?>` tags — never short tags (`<?`, `<?=` in library code).

### Braces, spacing, and control structures

- Always use braces for `if`, `else`, `elseif`, `for`, `foreach`, `while`,
  `do`, `switch` — no single-line bodies without braces.
- Opening brace on the **same line** as the statement.
- Put a single space after control-structure keywords: `if (`, `foreach (`,
  `switch (`.
- Put spaces **inside** parentheses for control structures and function
  calls with arguments: `if ( $foo ) {`, `my_function( $a, $b )`.
- No space between a function name and its parentheses in a declaration:
  `function my_function( $arg ) { ... }`.
- Space around all operators: `$a = $b + $c;`, `$x === $y`.
- Use `===` / `!==` (strict comparison) by default; only use `==` when a
  loose comparison is genuinely intended and document why.
- Yoda conditions when comparing a variable to a literal:
  `if ( true === $ready ) { ... }`.

### Naming

All function and method names in code we write MUST use
`lower_snake_case`, per the WordPress coding conventions. This applies to
every file under this tree **except** `src/vendor_prefixed/`, which
contains third-party code kept in its upstream style — do not rename or
reformat anything in that directory.

- Functions, variables, and hooks: `lower_snake_case`.
- Classes: `PascalCase` (e.g. `StoryReturnHandler`).
- Class methods and properties: `lower_snake_case`.
- Constants: `UPPER_SNAKE_CASE`.
- File names for class files: match the class (per the project's autoload
  configuration) or use `lowercase-with-dashes.php` for non-class files.

### Arrays

- Prefer the long array syntax `array( ... )` over `[ ... ]` (WPCS default).
- Trailing comma after the last element of multiline arrays.
- One element per line for multiline arrays, with keys aligned where the
  existing file does so.

### Strings and translations

- Prefer single quotes for plain strings; use double quotes only when
  interpolating or when the string contains a single quote.
- Wrap every user-facing string in the appropriate translation function
  (`__()`, `_e()`, `esc_html__()`, `esc_attr__()`, `_n()`, `_x()`,
  `sprintf()` with `__()`, etc.) with the plugin's text domain
  (`the-shorthand-editor`).
- Add `/* translators: ... */` comments above any translated string that
  uses placeholders.

### Security

- Sanitize on input, escape on output — every time, at the last possible
  moment before output:
  - Output: `esc_html()`, `esc_attr()`, `esc_url()`, `esc_js()`,
    `wp_kses()` / `wp_kses_post()`.
  - Input: `sanitize_text_field()`, `sanitize_key()`, `absint()`,
    `sanitize_textarea_field()`, `sanitize_url()`, etc.
- Unslash superglobals before sanitizing: `sanitize_text_field( wp_unslash( $_GET['x'] ) )`.
- Check nonces with `wp_verify_nonce()` for any state-changing request.
- Check capabilities with `current_user_can()` before privileged actions.
- Use prepared statements (`$wpdb->prepare()`) for any dynamic SQL.
- Never `echo` user-supplied data without escaping.

### WordPress API usage

- Prefer core WordPress APIs over rolling custom equivalents
  (`wp_remote_*`, `wp_safe_redirect`, `wp_insert_post`, `get_post_meta`,
  `wp_enqueue_script`, the Options API, the Transients API, etc.).
- Guard every entry-point file with `if ( ! defined( 'ABSPATH' ) ) { exit; }`.
- Register hooks with `add_action` / `add_filter` — do not call handlers
  directly.

### Documentation

- Every file, class, method, and function gets a DocBlock following the
  WordPress inline docs standard.
- Use `@param`, `@return`, `@throws`, `@since`, `@var` as appropriate.
- Prefer concrete types (`int`, `string`, `array`, `WP_Post|null`,
  `WP_Error`) over `mixed`.
- Keep DocBlock summaries to a single line; put detail in the description
  paragraph below.

### Errors and logging

- Return `WP_Error` for recoverable failures in service-layer code. Reserve
  `wp_die()` for terminal admin-request failures where no caller can handle
  the error, and always pass a user-friendly title and message.
- Do not silence errors with `@`.
- Do not leave `var_dump`, `print_r`, `error_log` debugging calls in
  committed code.

## Workflow expectations

- Match the patterns already present in the surrounding file — if existing
  code in the same file disagrees with a rule above, prefer local
  consistency and call out the divergence in the PR description.
- Run the PHP unit test suite after any non-trivial PHP change:

      ./vendor/bin/phpunit

- When adding or renaming functions, classes, or hooks, update the
  relevant DocBlocks and any documentation that references them.
- Keep changes narrowly scoped — do not reformat unrelated code to fit the
  style guide in the same commit.
