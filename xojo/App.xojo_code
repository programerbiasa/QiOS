#tag Class
Protected Class App
Inherits Application
	#tag Event
		Sub Opening()
		  DBManager.LoadConfig
		  Call DBManager.Connect
		  wndLogin.Show
		End Sub
	#tag EndEvent

	#tag Event
		Function UnhandledException(error As RuntimeException) As Boolean
		  MessageBox("Terjadi kesalahan sistem yang tidak tertangani:" + EndOfLine + error.Message)
		  Return True
		End Function
	#tag EndEvent

	#tag Property, Flags = &h0
		Public Version As String = "1.0.0"
	#tag EndProperty
End Class
#tag EndClass
