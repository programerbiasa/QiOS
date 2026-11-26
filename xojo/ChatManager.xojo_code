#tag Module
Protected Module ChatManager
	#tag Method, Flags = &h0
		Public Sub InitChat()
		  If DBManager.DB Is Nil Or Not DBManager.IsOnline Then Return
		  
		  // Dapatkan ID chat terakhir saat kasir mulai masuk
		  Try
		    Dim rs As RowSet = DBManager.DB.SelectSQL("SELECT COALESCE(MAX(id), 0) AS max_id FROM chat_messages")
		    If rs <> Nil And Not rs.AfterLastRow Then
		      LastReadChatID = rs.Column("max_id").IntegerValue
		    End If
		    UnreadCount = 0
		  Catch
		  End Try
		End Sub
	#tag EndMethod

	#tag Method, Flags = &h0
		Public Function CheckUnreadMessages() As Integer
		  If DBManager.DB Is Nil Or Not DBManager.IsOnline Then Return 0
		  
		  Try
		    // Hitung pesan baru dari pengguna lain (bukan yang dikirim oleh kasir ini sendiri)
		    Dim sql As String = "SELECT COUNT(*) AS total, COALESCE(MAX(id), 0) AS max_id FROM chat_messages WHERE id > ? AND user_id <> ?"
		    Dim rs As RowSet = DBManager.DB.SelectSQL(sql, LastReadChatID, SessionManager.CurrentUserID)
		    
		    If rs <> Nil And Not rs.AfterLastRow Then
		      Dim total As Integer = rs.Column("total").IntegerValue
		      If total > UnreadCount Then
		        // Ada pesan baru masuk yang belum pernah dilihat, bunyikan notifikasi suara
		        System.Beep
		      End If
		      UnreadCount = total
		      Return UnreadCount
		    End If
		  Catch
		  End Try
		  
		  Return 0
		End Function
	#tag EndMethod

	#tag Method, Flags = &h0
		Public Sub MarkAllAsRead()
		  If DBManager.DB Is Nil Or Not DBManager.IsOnline Then Return
		  
		  Try
		    Dim rs As RowSet = DBManager.DB.SelectSQL("SELECT COALESCE(MAX(id), 0) AS max_id FROM chat_messages")
		    If rs <> Nil And Not rs.AfterLastRow Then
		      LastReadChatID = rs.Column("max_id").IntegerValue
		    End If
		  Catch
		  End Try
		  
		  UnreadCount = 0
		End Sub
	#tag EndMethod

	#tag Method, Flags = &h0
		Public Function KirimPesan(pesan As String) As Boolean
		  Dim cleanMsg As String = Trim(pesan)
		  If cleanMsg = "" Then Return False
		  If DBManager.DB Is Nil Or Not DBManager.IsOnline Then Return False
		  
		  Dim senderName As String = SessionManager.CurrentFullName
		  If senderName = "" Then senderName = SessionManager.CurrentUsername
		  If senderName = "" Then senderName = "Kasir"
		  
		  Dim senderRole As String = SessionManager.CurrentRole
		  If senderRole = "" Then senderRole = "kasir"
		  
		  Try
		    Dim sql As String = "INSERT INTO chat_messages (user_id, sender_name, sender_role, message) VALUES (?, ?, ?, ?)"
		    DBManager.DB.ExecuteSQL(sql, SessionManager.CurrentUserID, senderName, senderRole, cleanMsg)
		    
		    // Update LastReadChatID agar pesan yang kita kirim sendiri tidak dihitung unread
		    MarkAllAsRead()
		    Return True
		  Catch err As DatabaseException
		    MessageBox("Gagal mengirim pesan: " + err.Message)
		    Return False
		  End Try
		End Function
	#tag EndMethod

	#tag Method, Flags = &h0
		Public Function MuatRiwayatChat(maxRows As Integer = 50) As RowSet
		  If DBManager.DB Is Nil Or Not DBManager.IsOnline Then Return Nil
		  
		  Try
		    Dim sql As String = "SELECT id, user_id, sender_name, sender_role, message, created_at FROM chat_messages ORDER BY id DESC LIMIT ?"
		    Dim rs As RowSet = DBManager.DB.SelectSQL(sql, maxRows)
		    Return rs
		  Catch
		    Return Nil
		  End Try
		End Function
	#tag EndMethod

	#tag Property, Flags = &h0
		Public LastReadChatID As Integer = 0
	#tag EndProperty

	#tag Property, Flags = &h0
		Public UnreadCount As Integer = 0
	#tag EndProperty
End Module
#tag EndModule
