param([Parameter(Mandatory=$true)][string]$Version)
$zipPath = "hitwatch4lox-V.$Version.zip"
$srcDir = $PSScriptRoot

if (Test-Path $zipPath) { [System.IO.File]::Delete((Join-Path $srcDir $zipPath)) }

Add-Type -AssemblyName System.IO.Compression.FileSystem
$zip = [System.IO.Compression.ZipFile]::Open((Join-Path $srcDir $zipPath), 'Create')

$excludeDirs  = @('.git', '.github', '__pycache__', '.claude', 'node_modules', 'log', '.pytest_cache', 'tests', 'data', '.playwright-mcp')
$excludeFiles = @('*.zip', '*.pyc', 'aimemory.md', '.gitignore', '.gitattributes', 'build_zip.ps1',
                  'create-plugin-zip.sh', 'AGENTS.md', 'gemini.md', 'CLAUDE.md',
                  '*.code-workspace', '*_logging_block.txt')

Get-ChildItem -Path $srcDir -Recurse -File | ForEach-Object {
    $rel   = $_.FullName.Substring($srcDir.Length + 1)
    $parts = $rel -split '[/\\]'
    $skip  = $false
    foreach ($d in $excludeDirs)  { if ($parts -contains $d)       { $skip = $true; break } }
    foreach ($p in $excludeFiles) { if ($_.Name -like $p)          { $skip = $true; break } }
    if ($skip) { return }

    $entryPath = $rel.Replace([char]92, [char]47)
    $entry  = $zip.CreateEntry($entryPath)
    $stream = $entry.Open()
    $fs     = [System.IO.File]::OpenRead($_.FullName)
    $fs.CopyTo($stream)
    $fs.Close()
    $stream.Close()
}

$zip.Dispose()
$size = [math]::Round((Get-Item (Join-Path $srcDir $zipPath)).Length / 1KB, 1)
Write-Host "ZIP fertig: $zipPath ($size KB)"
