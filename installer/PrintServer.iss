; Print Server installer (Inno Setup 6). Build with build.ps1, which runs:
;   ISCC.exe /DAppVersion=<version> installer\PrintServer.iss

#ifndef AppVersion
  #define AppVersion "0.1.0"
#endif

[Setup]
AppId={{6D767C8B-74CC-4074-B5D4-C308DA036698}
AppName=Print Server
AppVersion={#AppVersion}
AppPublisher=Print Server
DefaultDirName={autopf}\PrintServer
DisableProgramGroupPage=yes
DisableDirPage=yes
PrivilegesRequired=admin
ArchitecturesAllowed=x64compatible
ArchitecturesInstallIn64BitMode=x64compatible
OutputDir=..\dist
OutputBaseFilename=PrintServer-Setup-{#AppVersion}
Compression=lzma2
SolidCompression=yes
UninstallDisplayIcon={app}\PrintServer.exe
WizardStyle=modern

[Files]
Source: "..\dist\PrintServer.exe"; DestDir: "{app}"; Flags: ignoreversion
Source: "..\fonts\ae_AlHor.ttf"; DestDir: "{app}\fonts"; Flags: ignoreversion

[Tasks]
Name: "desktopicon"; Description: "Create a desktop shortcut"

[Icons]
; Start menu and desktop shortcuts: start it again after Quit, or open the status page if it is running
Name: "{autoprograms}\Print Server"; Filename: "{app}\PrintServer.exe"
Name: "{autodesktop}\Print Server"; Filename: "{app}\PrintServer.exe"; Tasks: desktopicon

[Dirs]
; The app runs as the logged-in user and writes config, logs and certificates here
Name: "{commonappdata}\PrintServer"; Permissions: users-modify

[Registry]
; Start for every user at login
Root: HKLM; Subkey: "Software\Microsoft\Windows\CurrentVersion\Run"; ValueType: string; ValueName: "PrintServer"; ValueData: """{app}\PrintServer.exe"""; Flags: uninsdeletevalue

[Run]
Filename: "{sys}\netsh.exe"; Parameters: "advfirewall firewall delete rule name=""Print Server"""; Flags: runhidden
Filename: "{sys}\netsh.exe"; Parameters: "advfirewall firewall add rule name=""Print Server"" dir=in action=allow protocol=TCP localport=80,443"; Flags: runhidden
Filename: "{app}\PrintServer.exe"; Parameters: "--install-cert"; Flags: runhidden waituntilterminated
Filename: "{app}\PrintServer.exe"; Description: "Start Print Server"; Flags: nowait postinstall runasoriginaluser

[UninstallRun]
Filename: "{sys}\taskkill.exe"; Parameters: "/IM PrintServer.exe /F"; Flags: runhidden; RunOnceId: "StopApp"
Filename: "{sys}\netsh.exe"; Parameters: "advfirewall firewall delete rule name=""Print Server"""; Flags: runhidden; RunOnceId: "Firewall"
Filename: "{app}\PrintServer.exe"; Parameters: "--uninstall-cert"; Flags: runhidden waituntilterminated; RunOnceId: "Cert"

[Code]
function PrepareToInstall(var NeedsRestart: Boolean): String;
var
  Code: Integer;
begin
  // Stop a running copy so an upgrade can replace the exe
  Exec(ExpandConstant('{sys}\taskkill.exe'), '/IM PrintServer.exe /F', '', SW_HIDE, ewWaitUntilTerminated, Code);
  Result := '';
end;

procedure CurStepChanged(CurStep: TSetupStep);
var
  Code: Integer;
begin
  if CurStep = ssPostInstall then
  begin
    if Exec(ExpandConstant('{app}\PrintServer.exe'), '--check-ports', '', SW_HIDE, ewWaitUntilTerminated, Code) and (Code <> 0) then
      MsgBox('Port 80 or 443 is already used by another program on this PC (for example Laragon, XAMPP, IIS or Skype).' + #13#10 + #13#10 +
             'Print Server cannot receive print jobs until that program is stopped or removed. ' +
             'The tray icon shows red while the port is blocked.', mbError, MB_OK);
  end;
end;
