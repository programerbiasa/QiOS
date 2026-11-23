# Skrip Pembuat Pintasan Desktop Windows untuk QiOS Cendekia
$WshShell = New-Object -ComObject WScript.Shell
$DesktopPath = [System.Environment]::GetFolderPath('Desktop')

# 1. Shortcut Utama (Aplikasi Desktop QiOS Cendekia Native)
$TargetExeLocal = Join-Path -Path $PSScriptRoot -ChildPath "dist\QiOS-Cendekia.exe"
$TargetExeRoot  = Join-Path -Path $PSScriptRoot -ChildPath "QiOS-Cendekia.exe"
$TargetExeDesk  = Join-Path -Path $DesktopPath -ChildPath "QiOS-Cendekia.exe"
$IconPath       = Join-Path -Path $PSScriptRoot -ChildPath "assets\images\qios_logo.ico"

$MainShortcutPath = Join-Path -Path $DesktopPath -ChildPath "QiOS Cendekia.lnk"
$Shortcut1 = $WshShell.CreateShortcut($MainShortcutPath)

if (Test-Path $TargetExeDesk) {
    $Shortcut1.TargetPath = $TargetExeDesk
    $Shortcut1.WorkingDirectory = $DesktopPath
} elseif (Test-Path $TargetExeLocal) {
    $Shortcut1.TargetPath = $TargetExeLocal
    $Shortcut1.WorkingDirectory = (Join-Path -Path $PSScriptRoot -ChildPath "dist")
} elseif (Test-Path $TargetExeRoot) {
    $Shortcut1.TargetPath = $TargetExeRoot
    $Shortcut1.WorkingDirectory = $PSScriptRoot
} else {
    $Shortcut1.TargetPath = "msedge.exe"
    $Shortcut1.Arguments = "--app=http://172.16.5.11/qios/ --window-size=1280,820"
    $Shortcut1.WorkingDirectory = $DesktopPath
}

$Shortcut1.Description = "Aplikasi Kasir POS QiOS Cendekia"
if (Test-Path $IconPath) {
    $Shortcut1.IconLocation = "$IconPath,0"
}
$Shortcut1.Save()

# 2. Shortcut Tambahan (Mode Web App Standalone Edge Langsung ke 172.16.5.11/qios)
$WebShortcutPath = Join-Path -Path $DesktopPath -ChildPath "QiOS Cendekia (Web POS).lnk"
$Shortcut2 = $WshShell.CreateShortcut($WebShortcutPath)
$Shortcut2.TargetPath = "msedge.exe"
$Shortcut2.Arguments = "--app=http://172.16.5.11/qios/ --window-size=1280,820 --start-maximized"
$Shortcut2.WorkingDirectory = $DesktopPath
$Shortcut2.Description = "QiOS Cendekia (Akses Cepat Web App)"
if (Test-Path $IconPath) {
    $Shortcut2.IconLocation = "$IconPath,0"
}
$Shortcut2.Save()

Write-Host "Pintasan Desktop 'QiOS Cendekia' berhasil dibuat di Desktop Anda!" -ForegroundColor Green
