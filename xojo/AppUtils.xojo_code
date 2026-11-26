#tag Module
Protected Module AppUtils
	#tag Method, Flags = &h0
		Function FormatRupiah(value As Double) As String
		  // Format angka ke format mata uang Rupiah (contoh: Rp 25.000)
		  Dim s As String = Format(value, "###,###,##0")
		  // Mengubah pemisah standar US ke format Indonesia (, ke .)
		  s = s.ReplaceAll(",", ".")
		  Return "Rp " + s
		End Function
	#tag EndMethod

	#tag Method, Flags = &h0
		Function FormatTanggalIndo(dt As DateTime = Nil) As String
		  If dt Is Nil Then dt = DateTime.Now
		  Dim hari() As String = Array("", "Minggu", "Senin", "Selasa", "Rabu", "Kamis", "Jumat", "Sabtu")
		  Dim bulan() As String = Array("", "Jan", "Feb", "Mar", "Apr", "Mei", "Jun", "Jul", "Agu", "Sep", "Okt", "Nov", "Des")
		  
		  Dim hName As String = hari(dt.DayOfWeek)
		  Dim bName As String = bulan(dt.Month)
		  
		  Return hName + ", " + Str(dt.Day) + " " + bName + " " + Str(dt.Year) + "  " + dt.ToString("HH:mm:ss")
		End Function
	#tag EndMethod

	#tag Method, Flags = &h0
		Function GenerateOrderCode() As String
		  // Format kode transaksi unik: ORD-YYYYMMDD-HHMMSS
		  Return "ORD-" + DateTime.Now.ToString("yyyyMMdd-HHmmss")
		End Function
	#tag EndMethod

	#tag Method, Flags = &h0
		Function ParseCurrency(text As String) As Double
		  Dim clean As String = text.ReplaceAll("Rp", "").ReplaceAll(".", "").ReplaceAll(" ", "").Trim
		  Return Val(clean)
		End Function
	#tag EndMethod


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
	#tag EndViewBehavior
End Module
#tag EndModule
