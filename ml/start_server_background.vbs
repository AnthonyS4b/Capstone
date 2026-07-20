' start_server_background.vbs
' Double-click this file to start the ML API Server in the background.
' The server will keep running even after this script finishes.
' To stop the server, double-click stop_server.vbs

Set WshShell = CreateObject ("WScript.shell")

WshShell.CurrentDirectory = CreateObject("Scripting.FileSystemObject").GetParentFolderName(WScript.ScriptFullName)
' Install dependencies first (visible window so you can see progress)
WshShell.Run "cmd /c py -m pip install -r requirements.txt", 1, True
' Start the Flask server in the background (hidden window)
WshShell.Run "py api_server.py ", 0, False
 
MsgBox "ML Server started in the background!" & vbCrLf & vbCrLf & "Server running at: http://127.0.0.1:5000" & vbCrLf & vbCrLf & "To stop the server, double-click stop_server.vbs", vbInformation, "ML Server"