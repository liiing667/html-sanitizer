CHANGELOG
=========

8.2
---

 * Make HTML parsing transactional: freeze the input digest, parser generation and DOM document identity per parse, and only commit a fully parsed document
 * Add `ReportingHtmlSanitizerInterface` with `sanitizeWithReport()` / `sanitizeForWithReport()` and parser reports containing the input digest, parse stage and node count
 * Resolve env vars at compile time in bundle options used to build the container
 * Add `HtmlSanitizerBundle`, which provides the `html_sanitizer` configuration and the services previously provided by `FrameworkBundle` under `framework.html_sanitizer`

8.0
---

 * Remove `MastermindsParser`; use `NativeParser` instead
 * Add argument `$context` to `ParserInterface::parse()`

7.4
---

 * Use the native HTML5 parser when using PHP 8.4+
 * Deprecate `MastermindsParser`; use `NativeParser` instead
 * [BC BREAK] `ParserInterface::parse()` can now return `\Dom\Node|\DOMNode|null` instead of just `\DOMNode|null`
 * Add argument `$context` to `ParserInterface::parse()`

7.2
---

 * Add support for configuring the default action to block or allow unconfigured elements instead of dropping them

6.4
---

 * Add support for sanitizing unlimited length of HTML document

6.1
---

 * Add the component as experimental
