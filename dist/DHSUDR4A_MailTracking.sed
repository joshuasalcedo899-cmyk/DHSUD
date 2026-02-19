[Version]
Class=IEXPRESS
SEDVersion=3

[Options]
PackagePurpose=InstallApp
ShowInstallProgramWindow=1
HideExtractAnimation=0
UseLongFileName=1
InsideCompressed=0
CAB_FixedSize=0
CAB_ResvCodeSigning=0
RebootMode=N
InstallPrompt=%InstallPrompt%
DisplayLicense=%DisplayLicense%
FinishMessage=%FinishMessage%
TargetName=%TargetName%
FriendlyName=%FriendlyName%
AppLaunched=%AppLaunched%
PostInstallCmd=%PostInstallCmd%
AdminQuietInstCmd=%AdminQuietInstCmd%
UserQuietInstCmd=%UserQuietInstCmd%
SourceFiles=SourceFiles

[Strings]
InstallPrompt=
DisplayLicense=
FinishMessage=
TargetName=C:\xampp\htdocs\DHSUD\dist\DHSUDR4A_MailTracking.exe
FriendlyName=DHSUDR4A Mail Tracking
AppLaunched=mshta.exe "C:\xampp\htdocs\DHSUD\DHSUDR4A Mail Tracking.hta"
PostInstallCmd=<None>
AdminQuietInstCmd=
UserQuietInstCmd=
FILE0="DHSUDR4A Mail Tracking.hta"
FILE1="Stop.bat"

[SourceFiles]
SourceFiles0=C:\xampp\htdocs\DHSUD\
[SourceFiles0]
%FILE0%=
%FILE1%=
