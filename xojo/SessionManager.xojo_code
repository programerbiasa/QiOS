#tag Module
Protected Module SessionManager
	#tag Property, Flags = &h0
		Public CurrentUserID As Integer = 0
	#tag EndProperty

	#tag Property, Flags = &h0
		Public CurrentUsername As String = ""
	#tag EndProperty

	#tag Property, Flags = &h0
		Public CurrentFullName As String = ""
	#tag EndProperty

	#tag Property, Flags = &h0
		Public CurrentRole As String = ""
	#tag EndProperty

	#tag Method, Flags = &h0
		Public Sub Logout()
		  CurrentUserID = 0
		  CurrentUsername = ""
		  CurrentFullName = ""
		  CurrentRole = ""
		End Sub
	#tag EndMethod
End Module
#tag EndModule
