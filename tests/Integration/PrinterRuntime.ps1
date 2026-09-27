$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Drawing
Add-Type -Path (Join-Path $PSScriptRoot '../../scripts/Print-Ticket.cs') -ReferencedAssemblies System.Drawing
$parseErrors = $null
$parseTokens = $null
[System.Management.Automation.Language.Parser]::ParseFile((Join-Path $PSScriptRoot '../../scripts/Start-OrderPrinter.ps1'), [ref]$parseTokens, [ref]$parseErrors) | Out-Null
if ($parseErrors.Count) { throw ($parseErrors | Out-String) }
# Never send a document to a real device in automated checks.
try {
    [SithinemTicketPrinter]::Print('SITHINEM_NONEXISTENT_TEST_PRINTER', 58, 'Test', 'Test')
    throw 'The renderer should reject an unknown printer.'
} catch {
    if ($_.Exception.ToString() -notmatch 'Imprimante Windows introuvable') { throw }
}
Write-Output 'Printer runtime: compilation, agent syntax and invalid-device check passed.'
