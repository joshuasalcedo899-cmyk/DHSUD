!macro customInstall
  DetailPrint "Preparing DHSUD Mail Tracker environment (XAMPP + database)..."
  ExecWait '"$INSTDIR\DHSUD Mail Tracker.exe" --bootstrap-only' $0

  IntCmp $0 0 setup_ok
  MessageBox MB_ICONSTOP|MB_OK "Environment setup failed during install. Exit code: $0.$\r$\nYou can still launch DHSUD Mail Tracker and it will retry setup automatically.$\r$\nLog: %LOCALAPPDATA%\DHSUDMailTracker\bootstrap.log"
  Goto setup_end

setup_ok:
  DetailPrint "Environment setup completed."

setup_end:
!macroend

!macro customUnInstall
  DetailPrint "Stopping DHSUD services..."
  ExecWait 'sc.exe stop DHSUDApache' $0
  ExecWait 'sc.exe stop DHSUDMySQL' $0

  DetailPrint "Removing DHSUD services..."
  ExecWait 'sc.exe delete DHSUDApache' $0
  ExecWait 'sc.exe delete DHSUDMySQL' $0
!macroend
