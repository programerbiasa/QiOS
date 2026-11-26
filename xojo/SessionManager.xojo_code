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
		Public Sub SetOnline(userId As Integer)
		  If DBManager.DB Is Nil Or Not DBManager.IsOnline Or userId <= 0 Then Return
		  
		  Dim devName As String = "Windows Desktop POS (Xojo)"
		  Dim token As String = "POS-" + DateTime.Now.ToString("yyyyMMddHHmmss")
		  
		  Try
		    Dim sql As String = "UPDATE users SET active_session_token = ?, active_device_name = ?, last_active_at = NOW(), last_seen = NOW() WHERE id = ?"
		    DBManager.DB.ExecuteSQL(sql, token, devName, userId)
		  Catch
		  End Try
		End Sub
	#tag EndMethod

	#tag Method, Flags = &h0
		Public Sub SendHeartbeat()
		  If DBManager.DB Is Nil Or Not DBManager.IsOnline Or CurrentUserID <= 0 Then Return
		  
		  Try
		    DBManager.DB.ExecuteSQL("UPDATE users SET last_active_at = NOW(), last_seen = NOW() WHERE id = ?", CurrentUserID)
		  Catch
		  End Try
		End Sub
	#tag EndMethod

	#tag Method, Flags = &h0
		Public Sub Logout()
		  If DBManager.DB <> Nil And DBManager.IsOnline And CurrentUserID > 0 Then
		    Try
		      DBManager.DB.ExecuteSQL("UPDATE users SET active_session_token = NULL, active_device_name = NULL, last_active_at = NULL WHERE id = ?", CurrentUserID)
		    Catch
		    End Try
		  End If
		  
		  CurrentUserID = 0
		  CurrentUsername = ""
		  CurrentFullName = ""
		  CurrentRole = ""
		End Sub
	#tag EndMethod
End Module
#tag EndModule
