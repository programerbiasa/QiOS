package main

import (
	"database/sql"
	"fmt"
	"image"
	"image/color"
	"math/rand"
	"os"
	"os/exec"
	"path/filepath"
	"strconv"
	"strings"
	"sync"
	"time"

	"gioui.org/app"
	"gioui.org/font"
	"gioui.org/layout"
	"gioui.org/op"
	"gioui.org/op/clip"
	"gioui.org/op/paint"
	"gioui.org/text"
	"gioui.org/unit"
	"gioui.org/widget"
	"gioui.org/widget/material"

	_ "github.com/go-sql-driver/mysql"
)

// ==========================================
// MODEL DATA DARI MARIADB (kios_db)
// ==========================================
type Kategori struct {
	ID   int
	Nama string
}

type Produk struct {
	ID         int
	KategoriID int
	Nama       string
	Barcode    string
	Harga      float64
	Stok       int
	BtnAdd     widget.Clickable
}

type CartItem struct {
	Produk   Produk
	Qty      int
	BtnInc   widget.Clickable
	BtnDec   widget.Clickable
	BtnDel   widget.Clickable
}

// Konfigurasi Database
type DBConfig struct {
	Host     string
	Port     string
	User     string
	Password string
	Database string
}

func loadConfig() DBConfig {
	cfg := DBConfig{
		Host:     "172.16.5.11",
		Port:     "3306",
		User:     "root",
		Password: "pastijuara1",
		Database: "kios_db",
	}

	exePath, err := os.Executable()
	if err == nil {
		iniPath := filepath.Join(filepath.Dir(exePath), "config.ini")
		data, err := os.ReadFile(iniPath)
		if err == nil {
			lines := strings.Split(string(data), "\n")
			for _, line := range lines {
				line = strings.TrimSpace(line)
				if strings.HasPrefix(line, "db_host=") {
					cfg.Host = strings.TrimPrefix(line, "db_host=")
				} else if strings.HasPrefix(line, "db_port=") {
					cfg.Port = strings.TrimPrefix(line, "db_port=")
				} else if strings.HasPrefix(line, "db_user=") {
					cfg.User = strings.TrimPrefix(line, "db_user=")
				} else if strings.HasPrefix(line, "db_password=") {
					cfg.Password = strings.TrimPrefix(line, "db_password=")
				} else if strings.HasPrefix(line, "db_name=") {
					cfg.Database = strings.TrimPrefix(line, "db_name=")
				}
			}
		}
	}
	return cfg
}

func formatRupiah(val float64) string {
	n := int64(val)
	sign := ""
	if n < 0 {
		sign = "-"
		n = -n
	}
	s := strconv.FormatInt(n, 10)
	var res []string
	for len(s) > 3 {
		res = append([]string{s[len(s)-3:]}, res...)
		s = s[:len(s)-3]
	}
	if len(s) > 0 {
		res = append([]string{s}, res...)
	}
	return sign + "Rp " + strings.Join(res, ".")
}

// ==========================================
// STATE APLIKASI POS
// ==========================================
type AppState struct {
	mu           sync.Mutex
	db           *sql.DB
	dbConnected  bool
	dbError      string
	kategoriList []Kategori
	produkList   []Produk
	selectedKat  int
	searchQuery  string

	cart         []*CartItem
	uangBayarStr string
	pembeliNama  string

	// Status Modal Struk
	showReceiptModal bool
	receiptContent   string
	lastTRXCode      string

	// Widgets
	btnRefresh    widget.Clickable
	btnBayarCetak widget.Clickable
	btnBatalBelanja widget.Clickable
	btnKosongkan  widget.Clickable
	btnPas        widget.Clickable
	btnP50        widget.Clickable
	btnP100       widget.Clickable

	btnCetakStruk    widget.Clickable
	btnTransaksiBaru widget.Clickable
	btnModalKembali  widget.Clickable

	katButtons []widget.Clickable
	searchEdit widget.Editor
	bayarEdit  widget.Editor

	prodListLayout layout.List
	cartListLayout layout.List
}

func NewAppState() *AppState {
	s := &AppState{
		selectedKat:    0,
		searchEdit:     widget.Editor{SingleLine: true},
		bayarEdit:      widget.Editor{SingleLine: true},
		prodListLayout: layout.List{Axis: layout.Vertical},
		cartListLayout: layout.List{Axis: layout.Vertical},
	}
	return s
}

func (s *AppState) ConnectDB() {
	cfg := loadConfig()
	dsn := fmt.Sprintf("%s:%s@tcp(%s:%s)/%s?parseTime=true&charset=utf8mb4",
		cfg.User, cfg.Password, cfg.Host, cfg.Port, cfg.Database)

	db, err := sql.Open("mysql", dsn)
	if err != nil {
		s.mu.Lock()
		s.dbConnected = false
		s.dbError = err.Error()
		s.mu.Unlock()
		return
	}

	db.SetConnMaxLifetime(time.Minute * 5)
	db.SetMaxOpenConns(10)
	db.SetMaxIdleConns(5)

	err = db.Ping()
	s.mu.Lock()
	if err != nil {
		s.dbConnected = false
		s.dbError = err.Error()
	} else {
		s.db = db
		s.dbConnected = true
		s.dbError = ""
	}
	s.mu.Unlock()

	if s.dbConnected {
		s.ReloadData()
	}
}

func (s *AppState) ReloadData() {
	s.mu.Lock()
	db := s.db
	s.mu.Unlock()

	if db == nil {
		return
	}

	// 1. Load Kategori
	kRows, err := db.Query("SELECT id, nama_kategori FROM kategori ORDER BY nama_kategori ASC")
	var kats []Kategori
	if err == nil {
		defer kRows.Close()
		for kRows.Next() {
			var k Kategori
			if err := kRows.Scan(&k.ID, &k.Nama); err == nil {
				kats = append(kats, k)
			}
		}
	}

	// 2. Load Produk
	pRows, err := db.Query("SELECT id, kategori_id, nama_produk, COALESCE(barcode, ''), harga, stok FROM produk ORDER BY nama_produk ASC")
	var prods []Produk
	if err == nil {
		defer pRows.Close()
		for pRows.Next() {
			var p Produk
			if err := pRows.Scan(&p.ID, &p.KategoriID, &p.Nama, &p.Barcode, &p.Harga, &p.Stok); err == nil {
				prods = append(prods, p)
			}
		}
	}

	s.mu.Lock()
	s.kategoriList = kats
	s.produkList = prods
	s.katButtons = make([]widget.Clickable, len(kats)+1)
	s.mu.Unlock()
}

func (s *AppState) AddToCart(p Produk) {
	s.mu.Lock()
	defer s.mu.Unlock()

	for _, item := range s.cart {
		if item.Produk.ID == p.ID {
			if item.Qty < p.Stok {
				item.Qty++
			}
			return
		}
	}

	if p.Stok > 0 {
		s.cart = append(s.cart, &CartItem{
			Produk: p,
			Qty:    1,
		})
	}
}

func (s *AppState) TotalBelanja() float64 {
	total := 0.0
	for _, item := range s.cart {
		total += float64(item.Qty) * item.Produk.Harga
	}
	return total
}

func (s *AppState) ParseUangBayar() float64 {
	str := strings.ReplaceAll(s.bayarEdit.Text(), ".", "")
	str = strings.ReplaceAll(str, ",", "")
	str = strings.TrimSpace(str)
	v, _ := strconv.ParseFloat(str, 64)
	return v
}

func (s *AppState) ExecuteTransaction() bool {
	s.mu.Lock()
	defer s.mu.Unlock()

	if len(s.cart) == 0 || s.db == nil {
		return false
	}

	total := 0.0
	for _, item := range s.cart {
		total += float64(item.Qty) * item.Produk.Harga
	}

	bayar := 0.0
	str := strings.ReplaceAll(s.bayarEdit.Text(), ".", "")
	str = strings.ReplaceAll(str, ",", "")
	str = strings.TrimSpace(str)
	bayar, _ = strconv.ParseFloat(str, 64)

	if bayar < total {
		return false
	}
	kembalian := bayar - total

	// Generate No Transaksi
	trxCode := fmt.Sprintf("TRX-%s-%04X", time.Now().Format("20060102"), rand.Intn(0xFFFF))

	tx, err := s.db.Begin()
	if err != nil {
		return false
	}

	res, err := tx.Exec(`
		INSERT INTO pesanan (kode_pesanan, user_id, nama_pembeli, total_harga, uang_bayar, kembalian, status, created_at)
		VALUES (?, 1, 'Umum', ?, ?, ?, 'SELESAI', NOW())
	`, trxCode, total, bayar, kembalian)
	if err != nil {
		tx.Rollback()
		return false
	}

	pesananID, err := res.LastInsertId()
	if err != nil {
		tx.Rollback()
		return false
	}

	for _, item := range s.cart {
		subtotal := float64(item.Qty) * item.Produk.Harga
		_, err = tx.Exec(`
			INSERT INTO pesanan_detail (pesanan_id, produk_id, nama_produk, harga_satuan, jumlah, subtotal)
			VALUES (?, ?, ?, ?, ?, ?)
		`, pesananID, item.Produk.ID, item.Produk.Nama, item.Produk.Harga, item.Qty, subtotal)
		if err != nil {
			tx.Rollback()
			return false
		}

		_, err = tx.Exec(`UPDATE produk SET stok = stok - ? WHERE id = ?`, item.Qty, item.Produk.ID)
		if err != nil {
			tx.Rollback()
			return false
		}
	}

	if err := tx.Commit(); err != nil {
		return false
	}

	// Buat format struk 58mm
	var sb strings.Builder
	sb.WriteString("================================\n")
	sb.WriteString("         QIOS CENDEKIA          \n")
	sb.WriteString("    Sistem Kasir POS Cerdas     \n")
	sb.WriteString("================================\n")
	sb.WriteString(fmt.Sprintf("No.TRX : %s\n", trxCode))
	sb.WriteString(fmt.Sprintf("Waktu  : %s\n", time.Now().Format("02/01/2006 15:04:05")))
	sb.WriteString("Kasir  : Kasir Native Go\n")
	sb.WriteString("Plggn  : Umum\n")
	sb.WriteString("--------------------------------\n")
	for _, item := range s.cart {
		sub := float64(item.Qty) * item.Produk.Harga
		sb.WriteString(fmt.Sprintf("%s\n", item.Produk.Nama))
		sb.WriteString(fmt.Sprintf("  %d x %s = %s\n", item.Qty, formatRupiah(item.Produk.Harga), formatRupiah(sub)))
	}
	sb.WriteString("--------------------------------\n")
	sb.WriteString(fmt.Sprintf("TOTAL    : %s\n", formatRupiah(total)))
	sb.WriteString(fmt.Sprintf("TUNAI    : %s\n", formatRupiah(bayar)))
	sb.WriteString(fmt.Sprintf("KEMBALI  : %s\n", formatRupiah(kembalian)))
	sb.WriteString("================================\n")
	sb.WriteString("      *** TERIMA KASIH ***      \n")
	sb.WriteString("  Barang yang sudah dibeli tidak\n")
	sb.WriteString("    dapat ditukar/dikembalikan  \n")
	sb.WriteString("================================\n")

	s.receiptContent = sb.String()
	s.lastTRXCode = trxCode
	s.showReceiptModal = true

	// Simpan juga ke file struk_terakhir.txt untuk printer thermal
	_ = os.WriteFile("struk_terakhir.txt", []byte(s.receiptContent), 0644)

	return true
}

func drawRoundedBox(gtx layout.Context, c color.NRGBA, r int) {
	rect := image.Rect(0, 0, gtx.Constraints.Min.X, gtx.Constraints.Min.Y)
	defer clip.UniformRRect(rect, r).Push(gtx.Ops).Pop()
	paint.Fill(gtx.Ops, c)
}

// ==========================================
// MAIN GUI LOOP
// ==========================================
func main() {
	state := NewAppState()
	go state.ConnectDB()

	go func() {
		w := new(app.Window)
		w.Option(
			app.Title("QiOS Cendekia - Sistem POS Native (Go)"),
			app.Size(unit.Dp(1280), unit.Dp(820)),
			app.MinSize(unit.Dp(1024), unit.Dp(700)),
		)

		var ops op.Ops
		th := material.NewTheme()

		// Palet Tema Dark
		bgColor := color.NRGBA{R: 15, G: 23, B: 42, A: 255}       // #0f172a
		cardColor := color.NRGBA{R: 30, G: 41, B: 59, A: 255}     // #1e293b
		cardHover := color.NRGBA{R: 51, G: 65, B: 85, A: 255}     // #334155
		primaryColor := color.NRGBA{R: 2, G: 132, B: 199, A: 255} // #0284c7
		successColor := color.NRGBA{R: 16, G: 185, B: 129, A: 255} // #10b981
		dangerColor := color.NRGBA{R: 239, G: 68, B: 68, A: 255}  // #ef4444
		textWhite := color.NRGBA{R: 248, G: 250, B: 252, A: 255}  // #f8fafc
		textMuted := color.NRGBA{R: 148, G: 163, B: 184, A: 255}  // #94a3b8

		th.Palette.Fg = textWhite
		th.Palette.Bg = bgColor

		for {
			switch e := w.Event().(type) {
			case app.DestroyEvent:
				os.Exit(0)
			case app.FrameEvent:
				gtx := app.NewContext(&ops, e)

				// Event Handling
				if state.btnRefresh.Clicked(gtx) {
					go state.ReloadData()
				}

				if state.btnPas.Clicked(gtx) {
					total := state.TotalBelanja()
					state.bayarEdit.SetText(fmt.Sprintf("%.0f", total))
				}
				if state.btnP50.Clicked(gtx) {
					v := state.ParseUangBayar() + 50000
					state.bayarEdit.SetText(fmt.Sprintf("%.0f", v))
				}
				if state.btnP100.Clicked(gtx) {
					v := state.ParseUangBayar() + 100000
					state.bayarEdit.SetText(fmt.Sprintf("%.0f", v))
				}

				if state.btnKosongkan.Clicked(gtx) {
					state.mu.Lock()
					state.cart = nil
					state.bayarEdit.SetText("")
					state.mu.Unlock()
				}

				if state.btnBatalBelanja.Clicked(gtx) {
					// Tombol Batal / Kembali Belanja: Tutup modal atau batalkan bayar, keranjang tetap utuh!
					state.mu.Lock()
					state.showReceiptModal = false
					state.mu.Unlock()
				}

				if state.btnBayarCetak.Clicked(gtx) {
					if state.ExecuteTransaction() {
						go state.ReloadData()
					}
				}

				if state.btnTransaksiBaru.Clicked(gtx) {
					state.mu.Lock()
					state.cart = nil
					state.bayarEdit.SetText("")
					state.showReceiptModal = false
					state.mu.Unlock()
					go state.ReloadData()
				}

				if state.btnCetakStruk.Clicked(gtx) {
					// Buka file struk_terakhir.txt atau kirim ke printer Windows
					_ = exec.Command("notepad.exe", "struk_terakhir.txt").Start()
				}

				// Cat background layar
				paint.Fill(gtx.Ops, bgColor)

				// Render Layout Utama
				layout.Flex{
					Axis: layout.Vertical,
				}.Layout(gtx,
					// 1. TOP NAVBAR
					layout.Rigid(func(gtx layout.Context) layout.Dimensions {
						return layout.Inset{Top: unit.Dp(10), Bottom: unit.Dp(10), Left: unit.Dp(16), Right: unit.Dp(16)}.Layout(gtx, func(gtx layout.Context) layout.Dimensions {
							return layout.Flex{
								Axis:      layout.Horizontal,
								Alignment: layout.Middle,
							}.Layout(gtx,
								// Judul Brand
								layout.Rigid(func(gtx layout.Context) layout.Dimensions {
									title := material.H5(th, "QiOS CENDEKIA")
									title.Color = color.NRGBA{R: 56, G: 189, B: 248, A: 255}
									title.Font.Weight = font.Bold
									return title.Layout(gtx)
								}),
								layout.Rigid(layout.Spacer{Width: unit.Dp(12)}.Layout),
								layout.Rigid(func(gtx layout.Context) layout.Dimensions {
									sub := material.Caption(th, "Native POS Desktop (Go + MariaDB)")
									sub.Color = textMuted
									return sub.Layout(gtx)
								}),
								layout.Flexed(1, layout.Spacer{}.Layout),
								// Status DB
								layout.Rigid(func(gtx layout.Context) layout.Dimensions {
									statusTxt := "● MariaDB: 172.16.5.11 (Terhubung)"
									statusCol := successColor
									if !state.dbConnected {
										statusTxt = "● MariaDB: Terputus (Klik Refresh)"
										statusCol = dangerColor
									}
									lbl := material.Body2(th, statusTxt)
									lbl.Color = statusCol
									return lbl.Layout(gtx)
								}),
								layout.Rigid(layout.Spacer{Width: unit.Dp(12)}.Layout),
								// Tombol Refresh
								layout.Rigid(func(gtx layout.Context) layout.Dimensions {
									btn := material.Button(th, &state.btnRefresh, "Segarkan Data")
									btn.Background = cardColor
									btn.Color = textWhite
									btn.CornerRadius = unit.Dp(8)
									return btn.Layout(gtx)
								}),
							)
						})
					}),

					// 2. KONTEN POS (Kiri: Katalog Produk 60%, Kanan: Keranjang Kasir 40%)
					layout.Flexed(1, func(gtx layout.Context) layout.Dimensions {
						return layout.Inset{Left: unit.Dp(16), Right: unit.Dp(16), Bottom: unit.Dp(16)}.Layout(gtx, func(gtx layout.Context) layout.Dimensions {
							return layout.Flex{
								Axis: layout.Horizontal,
							}.Layout(gtx,
								// PANEL KIRI (KATALOG PRODUK)
								layout.Flexed(0.62, func(gtx layout.Context) layout.Dimensions {
									return layout.Flex{
										Axis: layout.Vertical,
									}.Layout(gtx,
										// Bar Pencarian & Kategori
										layout.Rigid(func(gtx layout.Context) layout.Dimensions {
											return layout.Inset{Bottom: unit.Dp(10)}.Layout(gtx, func(gtx layout.Context) layout.Dimensions {
												ed := material.Editor(th, &state.searchEdit, "Cari nama barang atau scan barcode...")
												ed.Color = textWhite
												ed.HintColor = textMuted
												return ed.Layout(gtx)
											})
										}),

										// Grid / List Produk
										layout.Flexed(1, func(gtx layout.Context) layout.Dimensions {
											search := strings.ToLower(strings.TrimSpace(state.searchEdit.Text()))

											// Filter produk
											var filtered []Produk
											state.mu.Lock()
											for _, p := range state.produkList {
												if state.selectedKat != 0 && p.KategoriID != state.selectedKat {
													continue
												}
												if search != "" {
													nama := strings.ToLower(p.Nama)
													barcode := strings.ToLower(p.Barcode)
													if !strings.Contains(nama, search) && !strings.Contains(barcode, search) {
														continue
													}
												}
												filtered = append(filtered, p)
											}
											state.mu.Unlock()

											return state.prodListLayout.Layout(gtx, len(filtered), func(gtx layout.Context, i int) layout.Dimensions {
												p := filtered[i]

												// Klik tambah produk ke keranjang
												if p.BtnAdd.Clicked(gtx) {
													state.AddToCart(p)
												}

												return layout.Inset{Bottom: unit.Dp(8), Right: unit.Dp(10)}.Layout(gtx, func(gtx layout.Context) layout.Dimensions {
													return material.Clickable(gtx, &p.BtnAdd, func(gtx layout.Context) layout.Dimensions {
														return layout.Stack{}.Layout(gtx,
															layout.Expanded(func(gtx layout.Context) layout.Dimensions {
																bg := cardColor
																if p.BtnAdd.Hovered() {
																	bg = cardHover
																}
																drawRoundedBox(gtx, bg, 10)
																return layout.Dimensions{Size: gtx.Constraints.Min}
															}),
															layout.Stacked(func(gtx layout.Context) layout.Dimensions {
																return layout.Inset{Top: unit.Dp(12), Bottom: unit.Dp(12), Left: unit.Dp(16), Right: unit.Dp(16)}.Layout(gtx, func(gtx layout.Context) layout.Dimensions {
																	return layout.Flex{
																		Axis:      layout.Horizontal,
																		Alignment: layout.Middle,
																	}.Layout(gtx,
																		layout.Flexed(1, func(gtx layout.Context) layout.Dimensions {
																			return layout.Flex{
																				Axis: layout.Vertical,
																			}.Layout(gtx,
																				layout.Rigid(func(gtx layout.Context) layout.Dimensions {
																					lbl := material.Body1(th, p.Nama)
																					lbl.Font.Weight = font.Bold
																					lbl.Color = textWhite
																					return lbl.Layout(gtx)
																				}),
																				layout.Rigid(func(gtx layout.Context) layout.Dimensions {
																					txt := fmt.Sprintf("Stok: %d  |  Barcode: %s", p.Stok, p.Barcode)
																					lbl := material.Caption(th, txt)
																					lbl.Color = textMuted
																					return lbl.Layout(gtx)
																				}),
																			)
																		}),
																		layout.Rigid(func(gtx layout.Context) layout.Dimensions {
																			priceLbl := material.Body1(th, formatRupiah(p.Harga))
																			priceLbl.Color = color.NRGBA{R: 56, G: 189, B: 248, A: 255}
																			priceLbl.Font.Weight = font.Bold
																			return priceLbl.Layout(gtx)
																		}),
																		layout.Rigid(layout.Spacer{Width: unit.Dp(12)}.Layout),
																		layout.Rigid(func(gtx layout.Context) layout.Dimensions {
																			btn := material.Button(th, new(widget.Clickable), "+ Tambah")
																			btn.Background = primaryColor
																			btn.CornerRadius = unit.Dp(6)
																			btn.TextSize = unit.Sp(12)
																			return btn.Layout(gtx)
																		}),
																	)
																})
															}),
														)
													})
												})
											})
										}),
									)
								}),

								// PEMISAH
								layout.Rigid(layout.Spacer{Width: unit.Dp(16)}.Layout),

								// PANEL KANAN (KERANJANG KASIR & CHECKOUT)
								layout.Flexed(0.38, func(gtx layout.Context) layout.Dimensions {
									return layout.Stack{}.Layout(gtx,
										// Background Card Panel Kasir
										layout.Expanded(func(gtx layout.Context) layout.Dimensions {
											drawRoundedBox(gtx, cardColor, 12)
											return layout.Dimensions{Size: gtx.Constraints.Min}
										}),
										layout.Stacked(func(gtx layout.Context) layout.Dimensions {
											return layout.Inset{Top: unit.Dp(16), Bottom: unit.Dp(16), Left: unit.Dp(16), Right: unit.Dp(16)}.Layout(gtx, func(gtx layout.Context) layout.Dimensions {
												return layout.Flex{
													Axis: layout.Vertical,
												}.Layout(gtx,
													// Header Keranjang
													layout.Rigid(func(gtx layout.Context) layout.Dimensions {
														return layout.Flex{
															Axis:      layout.Horizontal,
															Alignment: layout.Middle,
														}.Layout(gtx,
															layout.Rigid(func(gtx layout.Context) layout.Dimensions {
																lbl := material.H6(th, fmt.Sprintf("Pesanan (%d item)", len(state.cart)))
																lbl.Font.Weight = font.Bold
																return lbl.Layout(gtx)
															}),
															layout.Flexed(1, layout.Spacer{}.Layout),
															layout.Rigid(func(gtx layout.Context) layout.Dimensions {
																btn := material.Button(th, &state.btnKosongkan, "Kosongkan")
																btn.Background = dangerColor
																btn.CornerRadius = unit.Dp(6)
																btn.TextSize = unit.Sp(11)
																return btn.Layout(gtx)
															}),
														)
													}),
													layout.Rigid(layout.Spacer{Height: unit.Dp(10)}.Layout),

													// Daftar Item di Keranjang
													layout.Flexed(1, func(gtx layout.Context) layout.Dimensions {
														if len(state.cart) == 0 {
															return layout.Center.Layout(gtx, func(gtx layout.Context) layout.Dimensions {
																lbl := material.Body2(th, "Keranjang belanja kosong.\nPilih produk di sebelah kiri.")
																lbl.Alignment = text.Middle
																lbl.Color = textMuted
																return lbl.Layout(gtx)
															})
														}

														return state.cartListLayout.Layout(gtx, len(state.cart), func(gtx layout.Context, i int) layout.Dimensions {
															item := state.cart[i]

															if item.BtnInc.Clicked(gtx) {
																state.mu.Lock()
																if item.Qty < item.Produk.Stok {
																	item.Qty++
																}
																state.mu.Unlock()
															}
															if item.BtnDec.Clicked(gtx) {
																state.mu.Lock()
																if item.Qty > 1 {
																	item.Qty--
																} else {
																	state.cart = append(state.cart[:i], state.cart[i+1:]...)
																}
																state.mu.Unlock()
															}
															if item.BtnDel.Clicked(gtx) {
																state.mu.Lock()
																state.cart = append(state.cart[:i], state.cart[i+1:]...)
																state.mu.Unlock()
															}

															sub := float64(item.Qty) * item.Produk.Harga

															return layout.Inset{Bottom: unit.Dp(8)}.Layout(gtx, func(gtx layout.Context) layout.Dimensions {
																return layout.Flex{
																	Axis:      layout.Horizontal,
																	Alignment: layout.Middle,
																}.Layout(gtx,
																	layout.Flexed(1, func(gtx layout.Context) layout.Dimensions {
																		return layout.Flex{
																			Axis: layout.Vertical,
																		}.Layout(gtx,
																			layout.Rigid(func(gtx layout.Context) layout.Dimensions {
																				lbl := material.Body2(th, item.Produk.Nama)
																				lbl.Font.Weight = font.Bold
																				return lbl.Layout(gtx)
																			}),
																			layout.Rigid(func(gtx layout.Context) layout.Dimensions {
																				lbl := material.Caption(th, fmt.Sprintf("%s x %d", formatRupiah(item.Produk.Harga), item.Qty))
																				lbl.Color = textMuted
																				return lbl.Layout(gtx)
																			}),
																		)
																	}),
																	// Tombol Minus
																	layout.Rigid(func(gtx layout.Context) layout.Dimensions {
																		btn := material.Button(th, &item.BtnDec, "-")
																		btn.Background = cardHover
																		btn.TextSize = unit.Sp(12)
																		return btn.Layout(gtx)
																	}),
																	layout.Rigid(layout.Spacer{Width: unit.Dp(6)}.Layout),
																	layout.Rigid(func(gtx layout.Context) layout.Dimensions {
																		lbl := material.Body2(th, strconv.Itoa(item.Qty))
																		lbl.Font.Weight = font.Bold
																		return lbl.Layout(gtx)
																	}),
																	layout.Rigid(layout.Spacer{Width: unit.Dp(6)}.Layout),
																	// Tombol Plus
																	layout.Rigid(func(gtx layout.Context) layout.Dimensions {
																		btn := material.Button(th, &item.BtnInc, "+")
																		btn.Background = cardHover
																		btn.TextSize = unit.Sp(12)
																		return btn.Layout(gtx)
																	}),
																	layout.Rigid(layout.Spacer{Width: unit.Dp(10)}.Layout),
																	// Subtotal
																	layout.Rigid(func(gtx layout.Context) layout.Dimensions {
																		lbl := material.Body2(th, formatRupiah(sub))
																		lbl.Font.Weight = font.Bold
																		lbl.Color = color.NRGBA{R: 56, G: 189, B: 248, A: 255}
																		return lbl.Layout(gtx)
																	}),
																)
															})
														})
													}),

													layout.Rigid(layout.Spacer{Height: unit.Dp(12)}.Layout),

													// SUMMARY TOTAL & PEMBAYARAN
													layout.Rigid(func(gtx layout.Context) layout.Dimensions {
														total := state.TotalBelanja()
														bayar := state.ParseUangBayar()

														return layout.Flex{
															Axis: layout.Vertical,
														}.Layout(gtx,
															// Label TOTAL
															layout.Rigid(func(gtx layout.Context) layout.Dimensions {
																return layout.Flex{
																	Axis:      layout.Horizontal,
																	Alignment: layout.Middle,
																}.Layout(gtx,
																	layout.Rigid(func(gtx layout.Context) layout.Dimensions {
																		lbl := material.H6(th, "TOTAL:")
																		lbl.Color = textMuted
																		return lbl.Layout(gtx)
																	}),
																	layout.Flexed(1, layout.Spacer{}.Layout),
																	layout.Rigid(func(gtx layout.Context) layout.Dimensions {
																		lbl := material.H5(th, formatRupiah(total))
																		lbl.Color = color.NRGBA{R: 56, G: 189, B: 248, A: 255}
																		lbl.Font.Weight = font.Bold
																		return lbl.Layout(gtx)
																	}),
																)
															}),
															layout.Rigid(layout.Spacer{Height: unit.Dp(8)}.Layout),

															// Input Uang Bayar
															layout.Rigid(func(gtx layout.Context) layout.Dimensions {
																ed := material.Editor(th, &state.bayarEdit, "Uang Bayar (Rp)...")
																ed.Color = textWhite
																ed.HintColor = textMuted
																return ed.Layout(gtx)
															}),
															layout.Rigid(layout.Spacer{Height: unit.Dp(6)}.Layout),

															// Tombol Preset Uang Cepat
															layout.Rigid(func(gtx layout.Context) layout.Dimensions {
																return layout.Flex{
																	Axis: layout.Horizontal,
																}.Layout(gtx,
																	layout.Flexed(1, func(gtx layout.Context) layout.Dimensions {
																		btn := material.Button(th, &state.btnPas, "Uang Pas")
																		btn.Background = cardHover
																		btn.TextSize = unit.Sp(11)
																		return btn.Layout(gtx)
																	}),
																	layout.Rigid(layout.Spacer{Width: unit.Dp(4)}.Layout),
																	layout.Flexed(1, func(gtx layout.Context) layout.Dimensions {
																		btn := material.Button(th, &state.btnP50, "+50rb")
																		btn.Background = cardHover
																		btn.TextSize = unit.Sp(11)
																		return btn.Layout(gtx)
																	}),
																	layout.Rigid(layout.Spacer{Width: unit.Dp(4)}.Layout),
																	layout.Flexed(1, func(gtx layout.Context) layout.Dimensions {
																		btn := material.Button(th, &state.btnP100, "+100rb")
																		btn.Background = cardHover
																		btn.TextSize = unit.Sp(11)
																		return btn.Layout(gtx)
																	}),
																)
															}),
															layout.Rigid(layout.Spacer{Height: unit.Dp(10)}.Layout),

															// Kembalian
															layout.Rigid(func(gtx layout.Context) layout.Dimensions {
																kembaliTxt := "Kembalian: Rp 0"
																kembaliCol := textMuted
																if bayar > 0 && total > 0 {
																	if bayar >= total {
																		kembaliTxt = fmt.Sprintf("Kembalian: %s", formatRupiah(bayar-total))
																		kembaliCol = successColor
																	} else {
																		kembaliTxt = fmt.Sprintf("Kurang: %s", formatRupiah(total-bayar))
																		kembaliCol = dangerColor
																	}
																}
																lbl := material.Body1(th, kembaliTxt)
																lbl.Font.Weight = font.Bold
																lbl.Color = kembaliCol
																return lbl.Layout(gtx)
															}),
															layout.Rigid(layout.Spacer{Height: unit.Dp(12)}.Layout),

															// TOMBOL BAYAR & CETAK STRUK
															layout.Rigid(func(gtx layout.Context) layout.Dimensions {
																canPay := len(state.cart) > 0 && bayar >= total && total > 0
																btnBg := primaryColor
																if !canPay {
																	btnBg = cardHover
																}
																btn := material.Button(th, &state.btnBayarCetak, "BAYAR & CETAK STRUK (58mm)")
																btn.Background = btnBg
																btn.Font.Weight = font.Bold
																btn.CornerRadius = unit.Dp(8)
																btn.TextSize = unit.Sp(13)
																return btn.Layout(gtx)
															}),
															layout.Rigid(layout.Spacer{Height: unit.Dp(6)}.Layout),

															// TOMBOL KEMBALI / TAMBAH BARANG
															layout.Rigid(func(gtx layout.Context) layout.Dimensions {
																btn := material.Button(th, &state.btnBatalBelanja, "↩️ Batal / Tambah Barang")
																btn.Background = cardHover
																btn.Color = textWhite
																btn.CornerRadius = unit.Dp(8)
																btn.TextSize = unit.Sp(11)
																return btn.Layout(gtx)
															}),
														)
													}),
												)
											})
										}),
									)
								}),
							)
						})
					}),
				)

				// 3. MODAL POPUP STRUK PEMBAYARAN SUKSES
				if state.showReceiptModal {
					// Overlay semi-transparan
					layout.Stack{}.Layout(gtx,
						layout.Expanded(func(gtx layout.Context) layout.Dimensions {
							paint.Fill(gtx.Ops, color.NRGBA{R: 0, G: 0, B: 0, A: 210})
							return layout.Dimensions{Size: gtx.Constraints.Min}
						}),
						layout.Stacked(func(gtx layout.Context) layout.Dimensions {
							return layout.Center.Layout(gtx, func(gtx layout.Context) layout.Dimensions {
								gtx.Constraints.Min.X = gtx.Dp(unit.Dp(480))
								gtx.Constraints.Max.X = gtx.Dp(unit.Dp(520))

								return layout.Stack{}.Layout(gtx,
									layout.Expanded(func(gtx layout.Context) layout.Dimensions {
										drawRoundedBox(gtx, cardColor, 16)
										return layout.Dimensions{Size: gtx.Constraints.Min}
									}),
									layout.Stacked(func(gtx layout.Context) layout.Dimensions {
										return layout.Inset{Top: unit.Dp(20), Bottom: unit.Dp(20), Left: unit.Dp(24), Right: unit.Dp(24)}.Layout(gtx, func(gtx layout.Context) layout.Dimensions {
											return layout.Flex{
												Axis: layout.Vertical,
											}.Layout(gtx,
												layout.Rigid(func(gtx layout.Context) layout.Dimensions {
													lbl := material.H6(th, "Transaksi Berhasil Disimpan!")
													lbl.Color = successColor
													lbl.Font.Weight = font.Bold
													return lbl.Layout(gtx)
												}),
												layout.Rigid(layout.Spacer{Height: unit.Dp(8)}.Layout),
												// Tampilan Preview Struk 58mm
												layout.Rigid(func(gtx layout.Context) layout.Dimensions {
													lbl := material.Caption(th, state.receiptContent)
													lbl.Font.Typeface = "Courier New, monospace"
													lbl.Color = color.NRGBA{R: 226, G: 232, B: 240, A: 255}
													return lbl.Layout(gtx)
												}),
												layout.Rigid(layout.Spacer{Height: unit.Dp(16)}.Layout),
												// Tombol Aksi Modal
												layout.Rigid(func(gtx layout.Context) layout.Dimensions {
													return layout.Flex{
														Axis: layout.Horizontal,
													}.Layout(gtx,
														layout.Flexed(1, func(gtx layout.Context) layout.Dimensions {
															btn := material.Button(th, &state.btnCetakStruk, "🖨️ Cetak / Buka Struk")
															btn.Background = primaryColor
															btn.CornerRadius = unit.Dp(8)
															return btn.Layout(gtx)
														}),
														layout.Rigid(layout.Spacer{Width: unit.Dp(8)}.Layout),
														layout.Flexed(1, func(gtx layout.Context) layout.Dimensions {
															btn := material.Button(th, &state.btnTransaksiBaru, "🔄 Transaksi Baru")
															btn.Background = successColor
															btn.CornerRadius = unit.Dp(8)
															return btn.Layout(gtx)
														}),
													)
												}),
											)
										})
									}),
								)
							})
						}),
					)
				}

				e.Frame(gtx.Ops)
			}
		}
	}()
	app.Main()
}
