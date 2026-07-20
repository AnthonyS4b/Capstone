' stop_server.vbs
' Double-click this file to stop the ML API Server running in the background.
Set WshShell = CreateObject("WScript.Shell")
WshShell.Run "cmd /c taskkill /f /im pythonw.exe", 0, True
MsgBox "ML Server has been stopped.", vbInformation, "ML Server"