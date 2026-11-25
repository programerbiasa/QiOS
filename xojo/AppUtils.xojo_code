#tag Module
Protected Module AppUtils
	#tag Method, Flags = &h0
		Public Function FormatRupiah(value As Double) As String
		  Dim s As String = Format(value, "###,###,##0")
		  s = s.ReplaceAll(",", ".")
		  Return "Rp " + s
		End Function
	#tag EndMethod

	#tag Method, Flags = &h0
		Public Function ParseCurrency(text As String) As Double
		  Dim clean As String = text.ReplaceAll("Rp", "").ReplaceAll(".", "").ReplaceAll(" ", "").Trim
		  Return Val(clean)
		End Function
	#tag EndMethod

	#tag Method, Flags = &h0
		Public Function GenerateOrderCode() As String
		  Return "ORD-" + DateTime.Now.ToString("yyyyMMdd-HHmmss")
		End Function
	#tag EndMethod
End Module
#tag EndModule
