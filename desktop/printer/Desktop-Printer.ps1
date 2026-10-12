$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = New-Object System.Text.UTF8Encoding($false)
if ($env:SITHINEM_MODE -eq 'test' -or $env:SITHINEM_MODE -eq 'ticket') {
    Add-Type -AssemblyName System.Drawing
    Add-Type -Path (Join-Path (Split-Path $env:SITHINEM_AGENT) 'Print-Ticket.cs') -ReferencedAssemblies System.Drawing
    if ($env:SITHINEM_MODE -eq 'ticket') {
        # One ticket requested from the application, already sanitized by the server.
        [SithinemTicketPrinter]::Print($env:SITHINEM_PRINTER, [int]$env:SITHINEM_WIDTH, $env:SITHINEM_TICKET_TITLE, $env:SITHINEM_TICKET_TEXT)
    } else {
        [SithinemTicketPrinter]::Print($env:SITHINEM_PRINTER, [int]$env:SITHINEM_WIDTH, 'Sithinem — Test', "SITHINEM`nTicket de test`nÉté · quantité : 1`nFormat : $($env:SITHINEM_WIDTH) mm`n")
    }
} else {
    & $env:SITHINEM_AGENT -SiteUrl $env:SITHINEM_SITE -PrinterName $env:SITHINEM_PRINTER -WidthMm ([int]$env:SITHINEM_WIDTH) -ReportStatus
}
