param(
    [Parameter(Mandatory = $true)][uri]$SiteUrl,
    [Parameter(Mandatory = $true)][string]$PrinterName,
    [ValidateSet(58, 80)][int]$WidthMm = 80,
    [string]$Token = $env:RESTAURANT_PRINT_TOKEN
)

$ErrorActionPreference = 'Stop'
if ($SiteUrl.Scheme -ne 'https') { throw "Utilisez l'adresse HTTPS du site pour protéger le jeton d'impression." }
if ($Token.Length -lt 32) { throw 'Définissez RESTAURANT_PRINT_TOKEN (32 caractères minimum) sur ce poste.' }
Add-Type -AssemblyName System.Drawing
Add-Type -Path (Join-Path $PSScriptRoot 'Print-Ticket.cs') -ReferencedAssemblies System.Drawing

# One printing agent per Windows session; the server also claims jobs atomically.
$printerMutex = New-Object System.Threading.Mutex($false, 'Local\SithinemOrderPrinter')
if (!$printerMutex.WaitOne(0)) { throw "Un poste d'impression Sithinem est déjà lancé dans cette session." }
$printerState = Join-Path $env:LOCALAPPDATA 'SithinemPrinter'
New-Item -ItemType Directory -Path $printerState -Force | Out-Null
$pendingFile = Join-Path $printerState 'pending-ack.json'
$apiBase = $SiteUrl.AbsoluteUri.TrimEnd('/') + '/api/printing'
$headers = @{ Authorization = "Bearer $Token"; Accept = 'application/json' }

function Save-Pending($entry) {
    $temporary = Join-Path $printerState 'pending-ack.tmp'
    $entry | ConvertTo-Json -Depth 5 | Set-Content -LiteralPath $temporary -Encoding UTF8
    Move-Item -LiteralPath $temporary -Destination $pendingFile -Force
}

try {
    Write-Host "Impression Sithinem active : $PrinterName ($WidthMm mm). Ctrl+C pour arrêter."
    while ($true) {
        try {
            # A persisted acknowledgement is retried without printing the ticket again.
            if (Test-Path -LiteralPath $pendingFile) {
                $pending = Get-Content -LiteralPath $pendingFile -Raw -Encoding UTF8 | ConvertFrom-Json
                if ($pending.site -ne $apiBase) { throw "Un ticket d'un autre site attend sa confirmation. Relancez avec son adresse." }
                $body = @{ token = $pending.token; success = $pending.success; error = $pending.error } | ConvertTo-Json
                Invoke-RestMethod -Method Post -Uri "$apiBase/jobs/$($pending.id)/acknowledge" -Headers $headers -ContentType 'application/json' -Body ([Text.Encoding]::UTF8.GetBytes($body)) -TimeoutSec 30 | Out-Null
                Remove-Item -LiteralPath $pendingFile
            }
            $response = Invoke-RestMethod -Method Post -Uri "$apiBase/claim" -Headers $headers -TimeoutSec 30
            if ($null -eq $response.job) { Start-Sleep -Seconds 5; continue }
            $job = $response.job
            $pending = @{ site = $apiBase; id = $job.id; token = $job.token; success = $false; error = "Envoi interrompu. Vérifiez le ticket sur l'imprimante avant toute relance." }
            Save-Pending $pending
            try {
                [SithinemTicketPrinter]::Print($PrinterName, $WidthMm, $job.title, $job.text)
                $pending.success = $true
                $pending.error = $null
                Write-Host "Ticket $($job.id) transmis au pilote Windows."
            } catch {
                $pending.error = "Le pilote n'a pas confirmé l'envoi. Vérifiez la connexion et la file Windows avant de relancer."
                Write-Warning $pending.error
            }
            Save-Pending $pending
            # Keep below the API rate limit even during a large batch.
            Start-Sleep -Seconds 2
        } catch {
            Write-Warning 'Connexion ou confirmation indisponible. Nouvelle tentative dans 10 secondes ; aucun ticket ne sera réimprimé automatiquement.'
            Start-Sleep -Seconds 10
        }
    }
} finally {
    $printerMutex.ReleaseMutex()
    $printerMutex.Dispose()
}
