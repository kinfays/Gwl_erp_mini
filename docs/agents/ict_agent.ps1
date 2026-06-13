param(
    [Parameter(Mandatory = $true)]
    [string]$BaseUrl,

    [Parameter(Mandatory = $true)]
    [string]$ApiToken,

    [int]$TimeoutSeconds = 30
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function Get-NormalizedApiUrl {
    param([string]$Url)

    $clean = $Url.TrimEnd('/')
    if ($clean -match '/api$') {
        return "$clean/agent/report"
    }

    return "$clean/api/agent/report"
}

$bios = Get-CimInstance Win32_BIOS
$os = Get-CimInstance Win32_OperatingSystem
$cpu = Get-CimInstance Win32_Processor | Select-Object -First 1
$system = Get-CimInstance Win32_ComputerSystem
$mac = Get-NetAdapter |
    Where-Object { $_.Status -eq 'Up' -and $_.MacAddress } |
    Select-Object -First 1 -ExpandProperty MacAddress

$payload = @{
    hostname       = $env:COMPUTERNAME
    serial_number  = $bios.SerialNumber
    mac_address    = $mac
    os_name        = $os.Caption
    os_version     = $os.Version
    cpu_name       = $cpu.Name
    ram_gb         = [math]::Round($system.TotalPhysicalMemory / 1GB, 2)
    logged_on_user = $env:USERNAME
    last_boot_time = $os.LastBootUpTime
    manufacturer   = $system.Manufacturer
    model          = $system.Model
    bios_version   = $bios.SMBIOSBIOSVersion
}

$endpoint = Get-NormalizedApiUrl -Url $BaseUrl
$headers = @{
    Authorization = "Bearer $ApiToken"
    Accept = 'application/json'
}

try {
    $response = Invoke-RestMethod -Method Post `
        -Uri $endpoint `
        -Headers $headers `
        -ContentType 'application/json' `
        -Body ($payload | ConvertTo-Json -Depth 5) `
        -TimeoutSec $TimeoutSeconds

    Write-Host "Agent report sent successfully."
    Write-Host ("Matched: {0} | Asset ID: {1}" -f $response.matched, $response.asset_id)
} catch {
    Write-Error ("Agent report failed: {0}" -f $_.Exception.Message)
    exit 1
}

