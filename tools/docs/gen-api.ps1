<#
.SYNOPSIS
    Writes docs/api.md, the API reference the documentation site publishes, from the classes and functions src/lib.rs
    gives PHP and their doc comments.

.DESCRIPTION
    Every #[php_class] with its doc comment, then the members of its #[php_impl] block (constants and methods, as PHP
    sees them: camelCase names, and PHP types for the Rust ones), then every #[php_function]. Regex, MatchResult and
    Utf16 come first, then the exceptions.

.PARAMETER Check
    Compares what would be written with docs/api.md and writes nothing. The script fails when they differ.
#>
param([switch] $Check)

$ErrorActionPreference = 'Stop'
$root = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
$source = Join-Path $root 'src' 'lib.rs'
$out = Join-Path $root 'docs' 'api.md'
$lines = [System.IO.File]::ReadAllText($source) -replace "`r", '' -split "`n"

# The PHP type of a Rust type the extension takes or gives. A Zval's type depends on what it is, so it is given by the
# parameter's or the method's name.
$zvalTypes = @{
    subject = 'string|Utf16'; replacement = 'string|Utf16|callable'; group = 'int|string'; unicode_version = 'int|string'
    'return:group' = 'string|Utf16|null'; 'return:replace' = 'string|Utf16'; 'return:replaceAll' = 'string|Utf16'
}
function ConvertTo-PhpType([string]$rust, [string]$name) {
    $rust = $rust.Trim() -replace '^PhpResult<(.*)>$', '$1'
    if ($rust -match '^Option<(.*)>$') {
        $inner = ConvertTo-PhpType $Matches[1] $name
        return $(if ($inner -match '\|') { "$inner|null" } else { "?$inner" })
    }
    switch -regex ($rust) {
        '^&?Zval$' { if ($zvalTypes.ContainsKey($name)) { return $zvalTypes[$name] }; return 'mixed' }
        '^(&(''static )?str|String|Binary<u8>)$' { return 'string' }
        '^i64$' { return 'int' }
        '^f64$' { return 'float' }
        '^bool$' { return 'bool' }
        '^(Vec<.*>|ZBox<ZendHashTable>)$' { return 'array' }
        '^(Self|\w+)$' { return $rust }
    }
    throw "No PHP type for the Rust type $rust ($name)."
}

function ConvertTo-CamelCase([string]$name) {
    if ($name.StartsWith('__')) { return $name }
    return [regex]::Replace($name, '_(\w)', { $args[0].Groups[1].Value.ToUpperInvariant() })
}

# A doc comment as Markdown: the comment is Markdown already, but $` (the text before a match) is no code span, and a
# < outside a code span would open HTML.
function Convert-Doc([string[]]$doc) {
    $text = (($doc -join "`n").Trim()) -replace '\$`', '`` $$` ``'
    $parts = [regex]::Split($text, '(`` [^`]+ ``|`[^`]+`)')
    return (($parts | ForEach-Object { if ($_ -match '^`') { $_ } else { $_ -replace '<', '\<' } }) -join '')
}

$classes = [ordered]@{}
$functions = [System.Collections.Generic.List[object]]::new()
$doc = [System.Collections.Generic.List[string]]::new()
$attributes = [System.Collections.Generic.List[string]]::new()
$impl = $null
for ($i = 0; $i -lt $lines.Count; $i++) {
    $line = $lines[$i]
    $trim = $line.Trim()
    if ($trim.StartsWith('///')) { $doc.Add(($trim -replace '^/// ?', '')); continue }
    if ($trim.StartsWith('#[')) { $attributes.Add($trim); continue }
    if ($line -match '^}') { $impl = $null }
    if ($line -match '^pub struct (\w+)' -and $attributes -contains '#[php_class]') {
        $rustName = $Matches[1]
        $php = ($attributes | Where-Object { $_ -match '^#\[php\(name = "(.*)"\)\]$' } | ForEach-Object { $Matches[1] -replace '\\\\', '\' })
        $extends = ($attributes | Where-Object { $_ -match '^#\[php\(extends\((?:.*stub = "(.*)"|(\w+))\)\)\]$' } | ForEach-Object { if ($Matches[1]) { $Matches[1] -replace '\\\\', '\' } else { $Matches[2] } })
        $final = [bool]($attributes | Where-Object { $_ -match 'ClassFlags::Final' })
        $classes[$rustName] = [pscustomobject]@{ Name = $rustName; Php = $php; Extends = $extends; Final = $final; Doc = @($doc); Members = [System.Collections.Generic.List[object]]::new() }
    }
    elseif ($line -match '^impl (\w+) \{' -and $attributes -contains '#[php_impl]') { $impl = $classes[$Matches[1]] }
    elseif ($impl -and $trim -match '^const (\w+): (\w+) = (.*);$') {
        $impl.Members.Add([pscustomobject]@{ Name = $Matches[1]; Code = "public const $(ConvertTo-PhpType $Matches[2] $Matches[1]) $($Matches[1]) = $($Matches[3]);"; Doc = @($doc) })
    }
    elseif ($trim -match '^pub fn (\w+)\(' -and ($impl -or $attributes -contains '#[php_function]')) {
        $rustName = $Matches[1]
        $signature = $trim
        while (-not $signature.EndsWith('{')) { $i++; $signature += ' ' + $lines[$i].Trim() }
        if ($signature -notmatch '^pub fn \w+\((.*)\)\s*(?:->\s*(.*?))?\s*\{$') { throw "No signature in: $signature" }
        $paramText = $Matches[1]
        $returns = $Matches[2]
        $name = ConvertTo-CamelCase $rustName
        # A method without &self is static, but for the constructor, which makes the object.
        $static = $paramText -notmatch '&self' -and $name -ne '__construct'
        $params = foreach ($p in ($paramText -split ',\s*' | Where-Object { $_.Trim() -and $_.Trim() -ne '&self' })) {
            $pn, $pt = $p -split ':\s*', 2
            $type = ConvertTo-PhpType $pt $pn.Trim()
            $default = if ($pt.Trim() -match '^Option<') { ' = null' } else { '' }
            "$type `$$($pn.Trim())$default"
        }
        $returnType = if ($name -eq '__construct') { '' } elseif ($returns) { ': ' + (ConvertTo-PhpType $returns "return:$name") } else { ': void' }
        if ($impl) {
            $code = "public $(if ($static) { 'static ' })function $name($($params -join ', '))$returnType;"
            $impl.Members.Add([pscustomobject]@{ Name = $name; Code = $code; Doc = @($doc) })
        } else {
            $php = ($attributes | Where-Object { $_ -match '^#\[php\(name = "(.*)"\)\]$' } | ForEach-Object { $Matches[1] -replace '\\\\', '\' })
            $functions.Add([pscustomobject]@{ Name = $php; Code = "function $($php -replace '^.*\\', '')($($params -join ', '))$returnType;"; Doc = @($doc) })
        }
    }
    $doc.Clear()
    $attributes.Clear()
}

$sb = [System.Text.StringBuilder]::new()
function Add([string]$line = '') { [void]$sb.Append($line).Append("`n") }
Add '# API reference'
Add
Add 'The PHP API of the extension, namespace `Corvus\RegexEcma262`, generated from the classes and functions'
Add '`src/lib.rs` gives PHP and their doc comments by `tools/docs/gen-api.ps1`. [Getting started](getting-started.md)'
Add 'shows it at work.'

$order = @('Regex', 'MatchResult', 'Utf16') + @($classes.Keys | Where-Object { $_ -notin @('Regex', 'MatchResult', 'Utf16') })
foreach ($key in $order) {
    $class = $classes[$key]
    $short = $class.Php -replace '^.*\\', ''
    Add
    Add "## $short"
    Add
    Add '```php'
    Add "$(if ($class.Final) { 'final ' })class $($class.Php)$(if ($class.Extends) { " extends $($class.Extends)" })"
    Add '```'
    if ($class.Doc.Count) { Add; Add (Convert-Doc $class.Doc) }
    $seen = @{}
    foreach ($member in $class.Members) {
        if ($seen[$member.Name]) { continue }
        $seen[$member.Name] = $true
        Add
        Add "### $short::$($member.Name)"
        Add
        Add '```php'
        Add $member.Code
        Add '```'
        if ($member.Doc.Count) { Add; Add (Convert-Doc $member.Doc) }
    }
}
if ($functions.Count) {
    Add
    Add '## Functions'
    foreach ($f in $functions) {
        Add
        Add "### $($f.Name)"
        Add
        Add '```php'
        Add $f.Code
        Add '```'
        if ($f.Doc.Count) { Add; Add (Convert-Doc $f.Doc) }
    }
}
$text = $sb.ToString().TrimEnd("`n") + "`n"

if ($Check) {
    $current = if (Test-Path $out) { [System.IO.File]::ReadAllText($out) } else { '' }
    if ($current -ne $text) { throw 'docs/api.md is not what src/lib.rs makes: run tools/docs/gen-api.ps1.' }
    Write-Host 'docs/api.md is current.'
    return
}
New-Item -ItemType Directory -Force -Path (Split-Path $out) | Out-Null
[System.IO.File]::WriteAllText($out, $text, [System.Text.UTF8Encoding]::new($false))
Write-Host 'Wrote docs/api.md.'
