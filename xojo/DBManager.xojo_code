#tag Module
Protected Module DBManager
	#tag Method, Flags = &h0
		Function AutoDetectServer() As Boolean
		  // Muat settingan terakhir dari config.ini
		  LoadConfig
		  
		  // 1. Coba dulu IP yang tersimpan di config saat ini
		  Dim err As String
		  If TestConnection(ServerIP, ServerPort, DBName, DBUser, DBPassword, err) Then
		    Return Connect()
		  End If
		  
		  // 2. Daftar kandidat IP yang akan dicoba otomatis jika IP saat ini tidak merespons
		  Dim candidateIPs() As String
		  candidateIPs.Add("192.168.1.254")   // IP LAN Raspberry Pi di SD Cendekia
		  candidateIPs.Add("100.88.230.65")   // IP Tailscale Raspberry Pi
		  
		  For Each ip As String In candidateIPs
		    // Jangan tes ulang IP yang sama dengan config
		    If ip <> ServerIP Then
		      If TestConnection(ip, ServerPort, DBName, DBUser, DBPassword, err) Then
		        // Ditemukan server yang aktif! Simpan otomatis ke config.ini
		        Call SaveConfig(ip, ServerPort, DBName, DBUser, DBPassword)
		        Return Connect()
		      End If
		    End If
		  Next
		  
		  // Jika semua kandidat gagal
		  IsOnline = False
		  LastErrorMessage = "Tidak ada server yang merespons (LAN maupun Tailscale)."
		  Return False
		End Function
	#tag EndMethod

	#tag Method, Flags = &h0
		Function CheckServerPort(ip As String, port As Integer, timeoutSeconds As Double) As Boolean
		  Dim sock As New TCPSocket
		  sock.Address = ip
		  sock.Port = port
		  sock.Connect
		  
		  Dim startMicro As Double = System.Microseconds
		  Dim maxMicro As Double = timeoutSeconds * 1000000
		  
		  // Cukup panggil sock.Poll saja di dalam loop
		  While Not sock.IsConnected And sock.LastErrorCode = 0 And (System.Microseconds - startMicro) < maxMicro
		    sock.Poll
		  Wend
		  
		  Dim connected As Boolean = sock.IsConnected
		  sock.Close
		  Return connected
		End Function
	#tag EndMethod

	#tag Method, Flags = &h0
		Function Connect() As Boolean
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
		Function EnsureDatabase(host As String, port As Integer, user As String, pass As String, ByRef msg As String) As Boolean
		  Dim adminDB As New MySQLCommunityServer
		  adminDB.Host = Trim(host)
		  adminDB.Port = port
		  adminDB.UserName = Trim(user)
		  adminDB.Password = pass
		  adminDB.DatabaseName = "" // Konek ke server MySQL tanpa memilih DB terlebih dahulu
		  
		  Try
		    adminDB.Connect
		  Catch err As DatabaseException
		    msg = "Gagal terhubung ke MySQL Server: " + err.Message
		    Return False
		  End Try
		  
		  Try
		    // 1. Buat Database kios_db jika belum ada
		    adminDB.ExecuteSQL("CREATE DATABASE IF NOT EXISTS `kios_db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;")
		    adminDB.ExecuteSQL("USE `kios_db`;")
		    
		    // 2. Buat Tabel users
		    adminDB.ExecuteSQL("CREATE TABLE IF NOT EXISTS `users` (" + _
		    "`id` INT AUTO_INCREMENT PRIMARY KEY, " + _
		    "`username` VARCHAR(50) NOT NULL UNIQUE, " + _
		    "`password` VARCHAR(255) NOT NULL, " + _
		    "`password_plain` VARCHAR(255) NULL, " + _
		    "`nama_lengkap` VARCHAR(100) NOT NULL, " + _
		    "`role` ENUM('superadmin', 'admin', 'kasir') NOT NULL DEFAULT 'kasir', " + _
		    "`active_session_token` VARCHAR(255) NULL, " + _
		    "`created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;")
		    
		    // Masukkan akun kasir & admin default jika tabel masih kosong
		    Dim rsUser As RowSet = adminDB.SelectSQL("SELECT COUNT(*) AS total FROM users")
		    If rsUser <> Nil And Not rsUser.AfterLastRow Then
		      If rsUser.Column("total").IntegerValue = 0 Then
		        adminDB.ExecuteSQL("INSERT INTO `users` (`username`, `password`, `password_plain`, `nama_lengkap`, `role`) VALUES " + _
		        "('kasir', '$2y$10$RIVJSj7F0Xht97lQOg1bSevVBHsEFZ.j.AWHvyTky5guuMUcGuihC', 'kasir123', 'Petugas Kasir 1', 'kasir'), " + _
		        "('cendekia', '$2y$10$GhYwaSQri5txSUQwcLamBOkFOojoA7H4xT.roRUyMOt3dyK5k6DIS', 'pastijuara1', 'Administrator Cendekia', 'superadmin');")
		      End If
		    End If
		    
		    // 3. Buat Tabel kategori
		    adminDB.ExecuteSQL("CREATE TABLE IF NOT EXISTS `kategori` (`id` INT AUTO_INCREMENT PRIMARY KEY, `nama_kategori` VARCHAR(100) NOT NULL UNIQUE, `deskripsi` VARCHAR(255) NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;")
		    
		    // 4. Buat Tabel satuan
		    adminDB.ExecuteSQL("CREATE TABLE IF NOT EXISTS `satuan` (`id` INT AUTO_INCREMENT PRIMARY KEY, `nama_satuan` VARCHAR(100) NOT NULL UNIQUE, `kode_satuan` VARCHAR(20) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;")
		    
		    // 5. Buat Tabel produk
		    adminDB.ExecuteSQL("CREATE TABLE IF NOT EXISTS `produk` (`id` INT AUTO_INCREMENT PRIMARY KEY, `kategori_id` INT NULL, `satuan_id` INT NULL, `nama_produk` VARCHAR(150) NOT NULL, `barcode` VARCHAR(100) NULL, `harga` DECIMAL(12, 2) NOT NULL DEFAULT 0.00, `stok` INT NOT NULL DEFAULT 0, `deskripsi` TEXT NULL, `foto` VARCHAR(255) NULL, `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;")
		    
		    // 6. Buat Tabel pesanan & pesanan_detail
		    adminDB.ExecuteSQL("CREATE TABLE IF NOT EXISTS `pesanan` (`id` INT AUTO_INCREMENT PRIMARY KEY, `kode_pesanan` VARCHAR(50) NOT NULL UNIQUE, `user_id` INT NULL, `nama_pembeli` VARCHAR(100) NOT NULL DEFAULT 'Pelanggan Umum', `total_harga` DECIMAL(12, 2) NOT NULL DEFAULT 0.00, `uang_bayar` DECIMAL(12, 2) NOT NULL DEFAULT 0.00, `kembalian` DECIMAL(12, 2) NOT NULL DEFAULT 0.00, `status` ENUM('SELESAI', 'PENDING', 'BATAL') DEFAULT 'SELESAI', `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;")
		    adminDB.ExecuteSQL("CREATE TABLE IF NOT EXISTS `pesanan_detail` (`id` INT AUTO_INCREMENT PRIMARY KEY, `pesanan_id` INT NOT NULL, `produk_id` INT NULL, `nama_produk` VARCHAR(150) NOT NULL, `harga_satuan` DECIMAL(12, 2) NOT NULL, `jumlah` INT NOT NULL, `subtotal` DECIMAL(12, 2) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;")
		    
		    adminDB.Close
		    msg = "Database dan tabel 'kios_db' baru saja dibuat otomatis!"
		    Return True
		  Catch err As DatabaseException
		    msg = "Gagal inisialisasi tabel: " + err.Message
		    Try
		      adminDB.Close
		    Catch
		    End Try
		    Return False
		  End Try
		End Function
	#tag EndMethod

	#tag Method, Flags = &h0
		Function GetNamaAplikasi() As string
		  // Jika database belum terhubung, kembalikan nama default
		  If DB Is Nil Or Not IsOnline Then Return "QiOS - Super App"
		  
		  Try
		    // Ambil nama toko/aplikasi dari tabel pengaturan di server
		    Dim rs As RowSet = DB.SelectSQL("SELECT nilai FROM pengaturan WHERE kunci = 'nama_aplikasi' LIMIT 1")
		    If rs <> Nil And Not rs.AfterLastRow Then
		      Dim val As String = Trim(rs.Column("nilai").StringValue)
		      If val <> "" Then
		        Return val
		      End If
		    End If
		  Catch
		  End Try
		  
		  Return "QiOS Cendekia"
		End Function
	#tag EndMethod

	#tag Method, Flags = &h0
		Sub LoadConfig()
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
		Function SaveConfig(newHost As String, newPort As Integer, newDb As String, newUser As String, newPass As String) As Boolean
		  ServerIP = Trim(newHost)
		  ServerPort = newPort
		  DBName = Trim(newDb)
		  DBUser = Trim(newUser)
		  DBPassword = newPass
		  
		  // Lokasi penyimpanan config.ini selalu di samping file .exe
		  Dim cfgFile As FolderItem = App.ExecutableFile.Parent.Child("config.ini")
		  
		  Try
		    // Hapus file lama jika ada agar bisa ditimpa bersih
		    If cfgFile.Exists Then
		      cfgFile.Delete
		    End If
		    
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
		Function TestConnection(host As String, port As Integer, dbName As String, user As String, pass As String, ByRef errMsg As String) As Boolean
		  // 1. Cek Cepat Port Menggunakan TCPSocket (Batas waktu 1.5 detik)
		  // Ini yang MENCEGAH Windows hang/freeze 21 detik jika IP sedang offline!
		  If Not CheckServerPort(host, port, 1.5) Then
		    errMsg = "Server '" + host + "' tidak merespons (Timeout 1.5 detik)." + EndOfLine + _
		    "Pastikan perangkat Anda terhubung ke jaringan WiFi/LAN yang benar."
		    Return False
		  End If
		  
		  // 2. Jika port terbuka dan server menyala, lanjutkan koneksi MySQL
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
		    // Jika database kios_db belum ada di host tersebut, otomatis buatkan sekarang!
		    Dim initMsg As String
		    If EnsureDatabase(host, port, user, pass, initMsg) Then
		      errMsg = initMsg
		      Return True
		    Else
		      errMsg = err.Message
		      Return False
		    End If
		  End Try
		End Function
	#tag EndMethod


	#tag Property, Flags = &h0
		DB As MySQLCommunityServer
	#tag EndProperty

	#tag Property, Flags = &h0
		DBName As String = "kios_db"
	#tag EndProperty

	#tag Property, Flags = &h0
		DBPassword As String = "pastijuara1"
	#tag EndProperty

	#tag Property, Flags = &h0
		DBUser As String = "root"
	#tag EndProperty

	#tag Property, Flags = &h0
		IsOnline As Boolean = False
	#tag EndProperty

	#tag Property, Flags = &h0
		LastErrorMessage As String = ""
	#tag EndProperty

	#tag Property, Flags = &h0
		ServerIP As String = "100.88.230.65"
	#tag EndProperty

	#tag Property, Flags = &h0
		ServerPort As Integer = 3306
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
			Name="ServerIP"
			Visible=false
			Group="Behavior"
			InitialValue="100.88.230.65"
			Type="String"
			EditorType=""
		#tag EndViewProperty
		#tag ViewProperty
			Name="ServerPort"
			Visible=false
			Group="Behavior"
			InitialValue="3306"
			Type="Integer"
			EditorType=""
		#tag EndViewProperty
		#tag ViewProperty
			Name="DBName"
			Visible=false
			Group="Behavior"
			InitialValue="kios_db"
			Type="String"
			EditorType=""
		#tag EndViewProperty
		#tag ViewProperty
			Name="DBUser"
			Visible=false
			Group="Behavior"
			InitialValue="root"
			Type="String"
			EditorType=""
		#tag EndViewProperty
		#tag ViewProperty
			Name="DBPassword"
			Visible=false
			Group="Behavior"
			InitialValue="pastijuara1"
			Type="String"
			EditorType=""
		#tag EndViewProperty
		#tag ViewProperty
			Name="IsOnline"
			Visible=false
			Group="Behavior"
			InitialValue="False"
			Type="Boolean"
			EditorType=""
		#tag EndViewProperty
		#tag ViewProperty
			Name="LastErrorMessage"
			Visible=false
			Group="Behavior"
			InitialValue=""
			Type="String"
			EditorType=""
		#tag EndViewProperty
	#tag EndViewBehavior
End Module
#tag EndModule
