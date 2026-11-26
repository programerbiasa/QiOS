#tag Module
Protected Module AppUpdater
	#tag Method, Flags = &h0
		Sub ApplyUpdate(url As String)
		  Dim socket As New URLConnection
		  socket.RequestHeader("User-Agent") = "QiOS-Desktop-POS"
		  
		  Dim tempExe As FolderItem = SpecialFolder.Temporary.Child("QiOS_update.exe")
		  If tempExe.Exists Then tempExe.Delete
		  
		  Try
		    socket.SendSync("GET", url, tempExe, 90)
		    
		    If tempExe.Exists And tempExe.Length > 10000 Then
		      Dim targetExe As FolderItem = App.ExecutableFile
		      Dim batFile As FolderItem = SpecialFolder.Temporary.Child("qios_patch.bat")
		      
		      Dim q As String = Chr(34)
		      Dim script As String = "@echo off" + EndOfLine + _
		      "timeout /t 1 /nobreak > nul" + EndOfLine + _
		      "copy /y " + q + tempExe.NativePath + q + " " + q + targetExe.NativePath + q + EndOfLine + _
		      "start " + q + q + " " + q + targetExe.NativePath + q + EndOfLine + _
		      "del " + q + tempExe.NativePath + q + EndOfLine + _
		      "del " + q + "%~f0" + q
		      
		      Dim tos As TextOutputStream = TextOutputStream.Create(batFile)
		      tos.Write(script)
		      tos.Close
		      
		      batFile.Open
		      Quit
		    Else
		      MessageBox("Gagal mengunduh berkas update. Silakan ulangi.")
		    End If
		  Catch err As RuntimeException
		    MessageBox("Error pembaruan: " + err.Message)
		  End Try
		End Sub
	#tag EndMethod

	#tag Method, Flags = &h0
		Sub CheckForUpdate(interactive As Boolean = False)
		  Dim socket As New URLConnection
		  socket.RequestHeader("User-Agent") = "QiOS-Desktop-POS"
		  
		  Dim apiUrl As String = "https://api.github.com/repos/" + RepoOwner + "/" + RepoName + "/releases/latest"
		  
		  Try
		    Dim response As String = socket.SendSync("GET", apiUrl, 12)
		    Dim root As New JSONItem(response)
		    
		    If root.HasKey("tag_name") Then
		      Dim remoteTag As String = root.Value("tag_name")
		      Dim releaseNotes As String = ""
		      If root.HasKey("body") Then releaseNotes = root.Value("body")
		      
		      Dim downloadUrl As String = ""
		      If root.HasKey("assets") Then
		        Dim assets As JSONItem = root.Value("assets")
		        For i As Integer = 0 To assets.Count - 1
		          Dim asset As JSONItem = assets.Value(i)
		          Dim aName As String = asset.Value("name")
		          If Right(aName, 4) = ".exe" Or Right(aName, 4) = ".zip" Then
		            downloadUrl = asset.Value("browser_download_url")
		            Exit For
		          End If
		        Next
		      End If
		      
		      If remoteTag <> CurrentVersion And downloadUrl <> "" Then
		        Dim d As New MessageDialog
		        d.Title = "Pembaruan QiOS"
		        d.Message = "Versi Baru Ditemukan: " + remoteTag
		        d.Explanation = "Catatan Pembaruan:" + EndOfLine + releaseNotes + EndOfLine + EndOfLine + "Apakah Anda ingin mengunduh dan memperbarui sekarang?"
		        d.ActionButton.Caption = "Perbarui Sekarang"
		        d.CancelButton.Visible = True
		        d.CancelButton.Caption = "Nanti"
		        
		        Dim btn As MessageDialogButton = d.ShowModal
		        If btn = d.ActionButton Then
		          ApplyUpdate(downloadUrl)
		        End If
		      Else
		        If interactive Then
		          MessageBox("Aplikasi QiOS Anda sudah versi paling mutakhir (" + CurrentVersion + ").")
		        End If
		      End If
		    End If
		  Catch err As RuntimeException
		    If interactive Then
		      MessageBox("Gagal memeriksa pembaruan: " + err.Message)
		    End If
		  End Try
		End Sub
	#tag EndMethod


	#tag Property, Flags = &h0
		CurrentVersion As String = "v1.0.0"
	#tag EndProperty

	#tag Property, Flags = &h0
		RepoName As String = "QiOS"
	#tag EndProperty

	#tag Property, Flags = &h0
		RepoOwner As String = "programerbiasa"
	#tag EndProperty


	#tag ViewBehavior
		#tag ViewProperty
			Name="Name"
			Visible=true
			Group="ID"
			InitialValue=""
			Type="String"
			EditorType=""
		#tag EndViewProperty
		#tag ViewProperty
			Name="Index"
			Visible=true
			Group="ID"
			InitialValue="-2147483648"
			Type="Integer"
			EditorType=""
		#tag EndViewProperty
		#tag ViewProperty
			Name="Super"
			Visible=true
			Group="ID"
			InitialValue=""
			Type="String"
			EditorType=""
		#tag EndViewProperty
		#tag ViewProperty
			Name="Left"
			Visible=true
			Group="Position"
			InitialValue="0"
			Type="Integer"
			EditorType=""
		#tag EndViewProperty
		#tag ViewProperty
			Name="Top"
			Visible=true
			Group="Position"
			InitialValue="0"
			Type="Integer"
			EditorType=""
		#tag EndViewProperty
		#tag ViewProperty
			Name="RepoOwner"
			Visible=false
			Group="Behavior"
			InitialValue="programerbiasa"
			Type="String"
			EditorType=""
		#tag EndViewProperty
		#tag ViewProperty
			Name="RepoName"
			Visible=false
			Group="Behavior"
			InitialValue="QiOS"
			Type="String"
			EditorType=""
		#tag EndViewProperty
		#tag ViewProperty
			Name="CurrentVersion"
			Visible=false
			Group="Behavior"
			InitialValue="v1.0.0"
			Type="String"
			EditorType=""
		#tag EndViewProperty
	#tag EndViewBehavior
End Module
#tag EndModule
