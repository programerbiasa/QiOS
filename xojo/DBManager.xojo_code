#tag Module
Protected Module DBManager
	#tag Method, Flags = &h0
		Public Function Connect() As Boolean
		  If DB Is Nil Then
		    DB = New MySQLCommunityServer
		  End If
		  
		  // Tutup koneksi sebelumnya jika ada
		  Try
		    DB.Close
		  Catch
		  End Try
		  
		  // Muat konfigurasi terbaru
		  LoadConfig
		  
		  DB.Host = ServerIP
		  DB.Port = ServerPort
		  DB.DatabaseName = DBName
		  DB.UserName = DBUser
		  DB.Password = DBPassword
		  
		  Try
		    DB.Connect
		    IsOnline = True
		    Return True
		  Catch err As DatabaseException
		    IsOnline = False
		    LastErrorMessage = err.Message
		    Return False
		  End Try
		End Function
	#tag EndMethod

	#tag Method, Flags = &h0
		Public Sub LoadConfig()
		  Dim cfgFile As FolderItem = App.ExecutableFile.Parent.Child("config.ini")
		  If Not cfgFile.Exists Then
		    cfgFile = SpecialFolder.CurrentWorkingDirectory.Child("config.ini")
		  End If
		  
		  If cfgFile.Exists Then
		    Try
		      Dim tis As TextInputStream = TextInputStream.Open(cfgFile)
		      While Not tis.EndOfFile
		        Dim line As String = Trim(tis.ReadLine)
		        If InStr(line, "=") > 0 And Left(line, 1) <> "#" And Left(line, 1) <> ";" Then
		          Dim parts() As String = line.Split("=")
		          Dim key As String = Trim(parts(0)).Lowercase
		          Dim val As String = Trim(parts(1))
		          
		          Select Case key
		          Case "db_host", "host", "server_ip"
		            ServerIP = val
		          Case "db_port", "port"
		            ServerPort = Val(val)
		          Case "db_name", "database"
		            DBName = val
		          Case "db_user", "username"
		            DBUser = val
		          Case "db_password", "db_pass", "password"
		            DBPassword = val
		          End Select
		        End If
		      Wend
		      tis.Close
		    Catch e As IOException
		    End Try
		  End If
		End Sub
	#tag EndMethod

	#tag Method, Flags = &h0
		Public Function SaveConfig(newHost As String, newPort As Integer, newDb As String, newUser As String, newPass As String) As Boolean
		  ServerIP = Trim(newHost)
		  ServerPort = newPort
		  DBName = Trim(newDb)
		  DBUser = Trim(newUser)
		  DBPassword = newPass
		  
		  Dim cfgFile As FolderItem = App.ExecutableFile.Parent.Child("config.ini")
		  If Not cfgFile.Exists Then
		    cfgFile = SpecialFolder.CurrentWorkingDirectory.Child("config.ini")
		  End If
		  
		  Try
		    Dim tos As TextOutputStream = TextOutputStream.Create(cfgFile)
		    tos.WriteLine("[SERVER]")
		    tos.WriteLine("server_url=http://" + ServerIP + "/qios/")
		    tos.WriteLine("")
		    tos.WriteLine("[DATABASE]")
		    tos.WriteLine("db_host=" + ServerIP)
		    tos.WriteLine("db_port=" + Str(ServerPort))
		    tos.WriteLine("db_user=" + DBUser)
		    tos.WriteLine("db_password=" + DBPassword)
		    tos.WriteLine("db_name=" + DBName)
		    tos.Close
		    Return True
		  Catch e As IOException
		    Return False
		  End Try
		End Function
	#tag EndMethod

	#tag Method, Flags = &h0
		Public Function TestConnection(host As String, port As Integer, dbName As String, user As String, pass As String, ByRef errMsg As String) As Boolean
		  Dim testDB As New MySQLCommunityServer
		  testDB.Host = Trim(host)
		  testDB.Port = port
		  testDB.DatabaseName = Trim(dbName)
		  testDB.UserName = Trim(user)
		  testDB.Password = pass
		  
		  Try
		    testDB.Connect
		    testDB.Close
		    Return True
		  Catch err As DatabaseException
		    errMsg = err.Message
		    Return False
		  End Try
		End Function
	#tag EndMethod

	#tag Property, Flags = &h0
		Public DB As MySQLCommunityServer
	#tag EndProperty

	#tag Property, Flags = &h0
		Public ServerIP As String = "100.88.230.65"
	#tag EndProperty

	#tag Property, Flags = &h0
		Public ServerPort As Integer = 3306
	#tag EndProperty

	#tag Property, Flags = &h0
		Public DBName As String = "kios_db"
	#tag EndProperty

	#tag Property, Flags = &h0
		Public DBUser As String = "root"
	#tag EndProperty

	#tag Property, Flags = &h0
		Public DBPassword As String = "pastijuara1"
	#tag EndProperty

	#tag Property, Flags = &h0
		Public IsOnline As Boolean = False
	#tag EndProperty

	#tag Property, Flags = &h0
		Public LastErrorMessage As String = ""
	#tag EndProperty
End Module
#tag EndModule
