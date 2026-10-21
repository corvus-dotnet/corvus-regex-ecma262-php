//! The `corvus_regex_ecma262` PHP extension: ECMA-262 (JavaScript) regular expressions, with the grammar and the
//! answers of RegExp, over the corvus-regex-ecma262 crate. Namespace `Corvus\RegexEcma262`: `Regex`, `MatchResult`,
//! `Utf16` and the exceptions.
//!
//! A PHP string is matched as UTF-8 (it must be valid UTF-8), with positions in bytes as PCRE's functions give them. A
//! `Utf16` holds UTF-16LE text, which is matched as UTF-16 with positions in code units, as JavaScript gives them.
#![cfg_attr(windows, feature(abi_vectorcall))]

use std::sync::Arc;
use std::time::Duration;

use corvus_regex_ecma262 as ecma;
use ext_php_rs::binary::Binary;
use ext_php_rs::boxed::ZBox;
use ext_php_rs::convert::{FromZval, IntoZval};
use ext_php_rs::exception::PhpException;
use ext_php_rs::prelude::*;
use ext_php_rs::types::{ZendCallable, ZendHashTable, Zval};
use ext_php_rs::zend::ce;

// ---------------------------------------------------------------------------------------------------------------------
// Exceptions

#[php_class]
#[php(name = "Corvus\\RegexEcma262\\RegexException")]
#[php(extends(ce = ce::exception, stub = "\\Exception"))]
#[derive(Default)]
pub struct RegexException;

#[php_class]
#[php(name = "Corvus\\RegexEcma262\\InvalidPatternException")]
#[php(extends(RegexException))]
#[derive(Default)]
pub struct InvalidPatternException;

#[php_class]
#[php(name = "Corvus\\RegexEcma262\\InvalidFlagsException")]
#[php(extends(RegexException))]
#[derive(Default)]
pub struct InvalidFlagsException;

#[php_class]
#[php(name = "Corvus\\RegexEcma262\\PatternTooLargeException")]
#[php(extends(RegexException))]
#[derive(Default)]
pub struct PatternTooLargeException;

#[php_class]
#[php(name = "Corvus\\RegexEcma262\\MatchLimitException")]
#[php(extends(RegexException))]
#[derive(Default)]
pub struct MatchLimitException;

fn compile_error(e: ecma::Error) -> PhpException {
    match e {
        ecma::Error::TooLarge(_) => PhpException::from_class::<PatternTooLargeException>(e.to_string()),
        ecma::Error::Flags(_) => PhpException::from_class::<InvalidFlagsException>(e.to_string()),
        _ => PhpException::from_class::<InvalidPatternException>(e.to_string()),
    }
}

fn limit_error(e: ecma::MatchError) -> PhpException {
    PhpException::from_class::<MatchLimitException>(e.to_string())
}

fn value_error(message: impl Into<String>) -> PhpException {
    PhpException::new(message.into(), 0, ce::value_error())
}

fn type_error(message: impl Into<String>) -> PhpException {
    PhpException::new(message.into(), 0, ce::type_error())
}

fn limit_of(timeout: Option<f64>) -> Option<Duration> {
    timeout.map(|t| Duration::from_secs_f64(t.max(0.0)))
}

// ---------------------------------------------------------------------------------------------------------------------
// Text

/// UTF-16LE text, matched as UTF-16 with positions in code units, as JavaScript gives them.
#[php_class]
#[php(name = "Corvus\\RegexEcma262\\Utf16")]
#[php(flags = ext_php_rs::flags::ClassFlags::Final)]
#[derive(Clone)]
pub struct Utf16 {
    units: Arc<Vec<u16>>,
}

#[php_impl]
impl Utf16 {
    /// The text from its UTF-16LE bytes (an even number of them), lone surrogates and all.
    pub fn __construct(utf16le: Binary<u8>) -> PhpResult<Utf16> {
        let bytes: Vec<u8> = utf16le.into();
        if !bytes.len().is_multiple_of(2) {
            return Err(value_error("UTF-16LE text has an even number of bytes"));
        }
        Ok(Utf16 { units: Arc::new(bytes.as_chunks::<2>().0.iter().map(|c| u16::from_le_bytes(*c)).collect()) })
    }

    /// The text of a UTF-8 string.
    pub fn from_utf8(text: &str) -> Utf16 {
        Utf16 { units: Arc::new(text.encode_utf16().collect()) }
    }

    /// The text as UTF-8, a lone surrogate as U+FFFD.
    pub fn to_utf8(&self) -> String {
        String::from_utf16_lossy(&self.units)
    }

    /// The UTF-16LE bytes.
    pub fn bytes(&self) -> Binary<u8> {
        Binary::from(le_bytes(&self.units))
    }

    /// The number of code units.
    pub fn length(&self) -> i64 {
        self.units.len() as i64
    }
}

fn le_bytes(units: &[u16]) -> Vec<u8> {
    units.iter().flat_map(|u| u.to_le_bytes()).collect()
}

/// The text of a call: a PHP string's bytes as UTF-8, or a Utf16's units.
enum Subject<'a> {
    Utf8(&'a str),
    Utf16(Arc<Vec<u16>>),
}

#[derive(Clone, Copy)]
enum View<'a> {
    Utf8(&'a str),
    Utf16(&'a [u16]),
}

impl Subject<'_> {
    fn view(&self) -> View<'_> {
        match self {
            Subject::Utf8(s) => View::Utf8(s),
            Subject::Utf16(u) => View::Utf16(u),
        }
    }
}

fn subject_of(z: &Zval) -> PhpResult<Subject<'_>> {
    if let Some(zs) = z.zend_str() {
        // A string checked once is marked as valid UTF-8 (the flag PCRE's functions set and read, from PHP 8.3), and
        // read without a check from then on.
        return zs.as_str().map(Subject::Utf8).map_err(|_| value_error("the subject is not valid UTF-8"));
    }
    if let Some(u) = <&Utf16>::from_zval(z) {
        return Ok(Subject::Utf16(u.units.clone()));
    }
    Err(type_error("the subject must be a string or a Corvus\\RegexEcma262\\Utf16"))
}

fn len(view: View<'_>) -> usize {
    match view {
        View::Utf8(s) => s.len(),
        View::Utf16(u) => u.len(),
    }
}

// ---------------------------------------------------------------------------------------------------------------------
// MatchResult

/// A match: where it is and what each group captured, positions in bytes for a string subject and in code units for
/// a Utf16.
#[php_class]
#[php(name = "Corvus\\RegexEcma262\\MatchResult")]
#[php(flags = ext_php_rs::flags::ClassFlags::Final)]
#[derive(Clone)]
pub struct MatchResult {
    spans: Vec<Option<(usize, usize)>>,
    texts: Vec<Option<TextValue>>,
    names: Arc<Vec<String>>,
    named: Vec<Option<usize>>,
}

/// A group's text, kept as the subject's kind.
#[derive(Clone)]
enum TextValue {
    Utf8(String),
    Utf16(Arc<Vec<u16>>),
}

impl TextValue {
    fn to_zval(&self) -> PhpResult<Zval> {
        match self {
            TextValue::Utf8(s) => {
                let mut z = Zval::new();
                z.set_string(s, false)?;
                Ok(z)
            }
            TextValue::Utf16(u) => Utf16 { units: u.clone() }.into_zval(false).map_err(Into::into),
        }
    }
}

impl MatchResult {
    fn of<H: ?Sized + ecma::Haystack>(caps: &ecma::Captures<'_, H>, view: View<'_>, regex: &Regex) -> MatchResult {
        let spans: Vec<Option<(usize, usize)>> = caps.iter().map(|m| m.map(|m| (m.start(), m.end()))).collect();
        let texts = spans
            .iter()
            .map(|s| {
                s.map(|(a, b)| match view {
                    View::Utf8(t) => TextValue::Utf8(String::from_utf8_lossy(&t.as_bytes()[a..b]).into_owned()),
                    View::Utf16(u) => TextValue::Utf16(Arc::new(u[a..b].to_vec())),
                })
            })
            .collect();
        // The group each name stands for in this match: of those that share a name, the one that took part.
        let named = regex
            .names
            .iter()
            .map(|n| caps.name(n).and_then(|m| spans.iter().position(|s| *s == Some((m.start(), m.end())))))
            .collect();
        MatchResult { spans, texts, names: regex.names.clone(), named }
    }

    /// The index of a group given by number or name.
    fn index(&self, group: Option<&Zval>) -> PhpResult<Option<usize>> {
        let Some(group) = group else { return Ok(Some(0)) };
        if let Some(i) = group.long() {
            if i >= 0 && (i as usize) < self.spans.len() {
                return Ok(Some(i as usize));
            }
            return Err(value_error(format!("no group {i}")));
        }
        if let Some(name) = group.str() {
            return match self.names.iter().position(|n| n == name) {
                Some(k) => Ok(self.named[k]),
                None => Err(value_error(format!("no group named {name}"))),
            };
        }
        Err(type_error("a group is an int or a string"))
    }
}

#[php_impl]
impl MatchResult {
    /// The start of the group (0, the whole match, by default), or null when it did not take part.
    pub fn start(&self, group: Option<&Zval>) -> PhpResult<Option<i64>> {
        Ok(self.index(group)?.and_then(|i| self.spans[i]).map(|(s, _)| s as i64))
    }

    /// The end of the group (0, the whole match, by default), or null when it did not take part.
    pub fn end(&self, group: Option<&Zval>) -> PhpResult<Option<i64>> {
        Ok(self.index(group)?.and_then(|i| self.spans[i]).map(|(_, e)| e as i64))
    }

    /// The text of the group (0, the whole match, by default), or null when it did not take part.
    pub fn group(&self, group: Option<&Zval>) -> PhpResult<Zval> {
        match self.index(group)?.and_then(|i| self.texts[i].as_ref()) {
            Some(t) => t.to_zval(),
            None => Ok(Zval::null()),
        }
    }

    /// The text of the whole match then of each group, null for one that did not take part: the array preg_match
    /// fills.
    pub fn groups(&self) -> PhpResult<Vec<Zval>> {
        self.texts.iter().map(|t| t.as_ref().map_or_else(|| Ok(Zval::null()), TextValue::to_zval)).collect()
    }

    /// The text of the group of each name, null for a name whose group did not take part, in the order of the names.
    pub fn named_groups(&self) -> PhpResult<ZBox<ZendHashTable>> {
        let mut out = ZendHashTable::with_capacity(self.names.len() as u32);
        for (k, name) in self.names.iter().enumerate() {
            let v = match self.named[k].and_then(|i| self.texts[i].as_ref()) {
                Some(t) => t.to_zval()?,
                None => Zval::null(),
            };
            out.insert(name.as_str(), v)?;
        }
        Ok(out)
    }

    /// The number of groups, the whole match among them.
    pub fn count(&self) -> i64 {
        self.spans.len() as i64
    }
}

// ---------------------------------------------------------------------------------------------------------------------
// Regex

/// A compiled ECMA-262 regular expression, as `new RegExp($pattern, $flags)` compiles it.
#[php_class]
#[php(name = "Corvus\\RegexEcma262\\Regex")]
#[php(flags = ext_php_rs::flags::ClassFlags::Final)]
pub struct Regex {
    inner: Arc<ecma::Regex>,
    /// Neither u nor v: a match is by UTF-16 code unit.
    legacy: bool,
    global: bool,
    names: Arc<Vec<String>>,
}

impl Regex {
    fn captures_at(&self, view: View<'_>, start: usize, limit: Option<Duration>) -> PhpResult<Option<MatchResult>> {
        Ok(match view {
            View::Utf8(s) => {
                let caps = match limit {
                    Some(l) => self.inner.try_captures_at(s, start, l).map_err(limit_error)?,
                    None => self.inner.captures_at(s, start),
                };
                caps.map(|c| MatchResult::of(&c, view, self))
            }
            View::Utf16(u) => {
                let caps = match limit {
                    Some(l) => self.inner.try_captures_at_utf16(u, start, l).map_err(limit_error)?,
                    None => self.inner.captures_at_utf16(u, start),
                };
                caps.map(|c| MatchResult::of(&c, view, self))
            }
        })
    }

    /// The position after an empty match at `at`: one code point on with u or v, one code unit without (over UTF-8,
    /// from a character beyond the Basic Multilingual Plane that is its third byte, between its halves).
    fn advance(&self, view: View<'_>, at: usize) -> usize {
        match view {
            View::Utf8(s) => {
                let b = s.as_bytes();
                if self.legacy && at < b.len() && b[at] >= 0xF0 {
                    return at + 2;
                }
                let mut next = at + 1;
                while next < b.len() && (b[next] & 0xC0) == 0x80 {
                    next += 1;
                }
                next
            }
            View::Utf16(u) => {
                if !self.legacy
                    && at + 1 < u.len()
                    && (0xD800..0xDC00).contains(&u[at])
                    && (0xDC00..0xE000).contains(&u[at + 1])
                {
                    at + 2
                } else {
                    at + 1
                }
            }
        }
    }

    fn start_of(view: View<'_>, offset: i64) -> PhpResult<Option<usize>> {
        if offset < 0 {
            return Err(value_error("the offset must not be negative"));
        }
        let offset = offset as usize;
        Ok((offset <= len(view)).then_some(offset))
    }

    fn substitute(&self, subject: &Zval, replacement: &Zval, all: bool) -> PhpResult<Zval> {
        let text = subject_of(subject)?;
        let view = text.view();
        if let Some(template) = replacement.binary_slice::<u8>() {
            return match view {
                View::Utf8(s) => {
                    let template = std::str::from_utf8(template)
                        .map_err(|e| value_error(format!("the replacement is not valid UTF-8: {e}")))?;
                    let out = if all { self.inner.replace_all(s, template) } else { self.inner.replace(s, template) };
                    let mut z = Zval::new();
                    z.set_string(&out, false)?;
                    Ok(z)
                }
                View::Utf16(u) => {
                    let template = std::str::from_utf8(template)
                        .map_err(|e| value_error(format!("the replacement is not valid UTF-8: {e}")))?;
                    let template: Vec<u16> = template.encode_utf16().collect();
                    let out = if all {
                        self.inner.replace_all_utf16(u, &template)
                    } else {
                        self.inner.replace_utf16(u, &template)
                    };
                    Utf16 { units: Arc::new(out.into_owned()) }.into_zval(false).map_err(Into::into)
                }
            };
        }
        if let Some(t) = <&Utf16>::from_zval(replacement) {
            let View::Utf16(u) = view else {
                return Err(type_error("a Utf16 replacement is for a Utf16 subject"));
            };
            let out =
                if all { self.inner.replace_all_utf16(u, &t.units) } else { self.inner.replace_utf16(u, &t.units) };
            return Utf16 { units: Arc::new(out.into_owned()) }.into_zval(false).map_err(Into::into);
        }
        let callable =
            ZendCallable::new(replacement).map_err(|_| type_error("the replacement must be a string or a callable"))?;
        // A callable: called with each MatchResult, its result put in place of the match.
        let total = len(view);
        let mut at = 0;
        let mut last = 0;
        let mut utf8 = String::new();
        let mut utf16: Vec<u16> = Vec::new();
        while at <= total {
            let Some(m) = self.captures_at(view, at, None)? else { break };
            let (s, e) = m.spans[0].expect("group 0 is the match");
            let put = callable.try_call(vec![&m])?;
            match view {
                View::Utf8(t) => {
                    utf8.push_str(&String::from_utf8_lossy(&t.as_bytes()[last..s]));
                    utf8.push_str(put.str().ok_or_else(|| type_error("the callable must return a string"))?);
                }
                View::Utf16(u) => {
                    utf16.extend_from_slice(&u[last..s]);
                    match <&Utf16>::from_zval(&put) {
                        Some(r) => utf16.extend_from_slice(&r.units),
                        None => utf16.extend(
                            put.str()
                                .ok_or_else(|| type_error("the callable must return a string or a Utf16"))?
                                .encode_utf16(),
                        ),
                    }
                }
            }
            last = e;
            if !all {
                break;
            }
            at = if e == s { self.advance(view, e) } else { e };
        }
        match view {
            View::Utf8(t) => {
                utf8.push_str(&String::from_utf8_lossy(&t.as_bytes()[last..]));
                let mut z = Zval::new();
                z.set_string(&utf8, false)?;
                Ok(z)
            }
            View::Utf16(u) => {
                utf16.extend_from_slice(&u[last..]);
                Utf16 { units: Arc::new(utf16) }.into_zval(false).map_err(Into::into)
            }
        }
    }
}

#[php_impl]
impl Regex {
    /// Compiles a pattern with the flags `new RegExp($pattern, $flags)` takes, any of dgimsuvy. Throws
    /// InvalidPatternException, InvalidFlagsException or PatternTooLargeException (each a RegexException).
    pub fn __construct(pattern: &str, flags: Option<&str>) -> PhpResult<Regex> {
        let inner = ecma::Regex::new(pattern, flags.unwrap_or("")).map_err(compile_error)?;
        let written = format!("{}", inner.flags());
        let names = inner.group_names().map(str::to_owned).collect();
        Ok(Regex {
            legacy: !written.contains('u') && !written.contains('v'),
            global: written.contains('g'),
            inner: Arc::new(inner),
            names: Arc::new(names),
        })
    }

    /// Whether a pattern is valid with the flags, without compiling it.
    pub fn is_valid(pattern: &str, flags: Option<&str>) -> bool {
        ecma::Regex::is_valid(pattern, flags.unwrap_or(""))
    }

    /// The pattern as it was given.
    pub fn source(&self) -> String {
        self.inner.as_str().to_owned()
    }

    /// The flags as RegExp.prototype.flags writes them, in the order dgimsuvy.
    pub fn flags(&self) -> String {
        format!("{}", self.inner.flags())
    }

    /// The number of capturing groups.
    pub fn group_count(&self) -> i64 {
        (self.inner.captures_len() - 1) as i64
    }

    /// The distinct names of the groups, in the order of the first group of each name.
    pub fn group_names(&self) -> Vec<String> {
        self.names.as_ref().clone()
    }

    /// Whether the pattern matches at or after $offset (at $offset alone with the y flag), as RegExp.prototype.test
    /// with lastIndex set to it. With a timeout in seconds, a match that reaches it throws MatchLimitException.
    pub fn test(&self, subject: &Zval, offset: Option<i64>, timeout: Option<f64>) -> PhpResult<bool> {
        let text = subject_of(subject)?;
        let view = text.view();
        let Some(start) = Regex::start_of(view, offset.unwrap_or(0))? else { return Ok(false) };
        let inner = &self.inner;
        match (view, limit_of(timeout)) {
            (View::Utf8(s), None) => Ok(inner.is_match_at(s, start)),
            (View::Utf16(u), None) => Ok(inner.is_match_at_utf16(u, start)),
            (View::Utf8(s), Some(l)) => inner.try_find_at(s, start, l).map(|m| m.is_some()).map_err(limit_error),
            (View::Utf16(u), Some(l)) => inner.try_find_at_utf16(u, start, l).map(|m| m.is_some()).map_err(limit_error),
        }
    }

    /// The first match at or after $offset, as exec finds it with lastIndex set to it: a MatchResult, or null. With a
    /// timeout in seconds, a match that reaches it throws MatchLimitException.
    pub fn exec(&self, subject: &Zval, offset: Option<i64>, timeout: Option<f64>) -> PhpResult<Option<MatchResult>> {
        let text = subject_of(subject)?;
        let view = text.view();
        let Some(start) = Regex::start_of(view, offset.unwrap_or(0))? else { return Ok(None) };
        self.captures_at(view, start, limit_of(timeout))
    }

    /// Every match of a global search, as matchAll makes one: an array of MatchResult. After an empty match the
    /// search goes on one character later, and with the y flag each match must start where the one before ended.
    pub fn match_all(&self, subject: &Zval) -> PhpResult<Vec<MatchResult>> {
        let text = subject_of(subject)?;
        let view = text.view();
        let total = len(view);
        let mut out = Vec::new();
        let mut at = 0;
        while at <= total {
            let Some(m) = self.captures_at(view, at, None)? else { break };
            let (s, e) = m.spans[0].expect("group 0 is the match");
            out.push(m);
            at = if e == s { self.advance(view, e) } else { e };
        }
        Ok(out)
    }

    /// The number of matches of a global search, without making them.
    pub fn count_matches(&self, subject: &Zval) -> PhpResult<i64> {
        let text = subject_of(subject)?;
        Ok(match text.view() {
            View::Utf8(s) => self.inner.find_iter(s).count(),
            View::Utf16(u) => self.inner.find_iter_utf16(u).count(),
        } as i64)
    }

    /// The subject with the first match replaced, or every match with the g flag, as String.prototype.replace replaces
    /// them. The replacement is a template ($& the match, $` the text before it, $' the text after it, $1 to $99 a
    /// group, $<name> a named group, $$ a dollar sign) or a callable given each MatchResult, returning the text.
    pub fn replace(&self, subject: &Zval, replacement: &Zval) -> PhpResult<Zval> {
        self.substitute(subject, replacement, self.global)
    }

    /// The subject with every match replaced, whether or not the g flag is set (String.prototype.replaceAll).
    pub fn replace_all(&self, subject: &Zval, replacement: &Zval) -> PhpResult<Zval> {
        self.substitute(subject, replacement, true)
    }

    /// The subject split around the matches, as String.prototype.split($separator, $limit) splits it: what each group
    /// captured is spliced in after the piece before it (null for a group that did not take part), an empty match at
    /// the start or the end splits nothing, and there are at most $limit pieces.
    pub fn split(&self, subject: &Zval, limit: Option<i64>) -> PhpResult<Vec<Zval>> {
        let text = subject_of(subject)?;
        let limit = match limit {
            Some(n) if n < 0 => return Err(value_error("the limit must not be negative")),
            Some(n) => Some(n as usize),
            None => None,
        };
        match text.view() {
            View::Utf8(s) => {
                let pieces = match limit {
                    Some(n) => self.inner.splitn(s, n),
                    None => self.inner.split(s),
                };
                pieces
                    .into_iter()
                    .map(|p| match p {
                        Some(p) => {
                            let mut z = Zval::new();
                            z.set_string(&p, false)?;
                            Ok(z)
                        }
                        None => Ok(Zval::null()),
                    })
                    .collect()
            }
            View::Utf16(u) => {
                let pieces = match limit {
                    Some(n) => self.inner.splitn_utf16(u, n),
                    None => self.inner.split_utf16(u),
                };
                pieces
                    .into_iter()
                    .map(|p| match p {
                        Some(p) => Utf16 { units: Arc::new(p.into_owned()) }.into_zval(false).map_err(Into::into),
                        None => Ok(Zval::null()),
                    })
                    .collect()
            }
        }
    }
}

/// The version of the corvus-regex-ecma262 crate the extension was built from.
#[php_function]
#[php(name = "Corvus\\RegexEcma262\\crate_version")]
pub fn crate_version() -> &'static str {
    "0.1.1"
}

#[php_module]
pub fn get_module(module: ModuleBuilder) -> ModuleBuilder {
    // The extension's name (as `extension_loaded`, `phpversion` and PIE know it) and version, rather than the crate's.
    module
        .name("corvus_regex_ecma262")
        .version(env!("CARGO_PKG_VERSION"))
        .class::<RegexException>()
        .class::<InvalidPatternException>()
        .class::<InvalidFlagsException>()
        .class::<PatternTooLargeException>()
        .class::<MatchLimitException>()
        .class::<Utf16>()
        .class::<MatchResult>()
        .class::<Regex>()
        .function(wrap_function!(crate_version))
}
