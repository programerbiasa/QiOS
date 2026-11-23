package main

import (
	"crypto/rand"
	"database/sql"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io"
	"log"
	"net/http"
	"net/http/httputil"
	"net/url"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"time"

	_ "github.com/go-sql-driver/mysql"
	"golang.org/x/crypto/bcrypt"
)

// ==========================================
// KONFIGURASI DATABASE
// ==========================================
type DBConfig struct {
	Host     string
	Port     string
	User     string
	Password string
	Database string
	PiWebUrl string
}

func loadConfig() DBConfig {
	cfg := DBConfig{
		Host:     "172.16.5.11",
		Port:     "3306",
		User:     "root",
		Password: "pastijuara1",
		Database: "kios_db",
		PiWebUrl: "http://172.16.5.11/",
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
				} else if strings.HasPrefix(line, "pi_web_url=") {
					cfg.PiWebUrl = strings.TrimPrefix(line, "pi_web_url=")
				}
			}
		}
	}
	return cfg
}

var (
	db     *sql.DB
	config DBConfig
)

func enableCors(w http.ResponseWriter, r *http.Request) bool {
	w.Header().Set("Access-Control-Allow-Origin", "*")
	w.Header().Set("Access-Control-Allow-Methods", "GET, POST, OPTIONS, PUT, DELETE")
	w.Header().Set("Access-Control-Allow-Headers", "Content-Type, Authorization, X-Requested-With")
	if r.Method == "OPTIONS" {
		w.WriteHeader(http.StatusOK)
		return true
	}
	return false
}

func sendJSON(w http.ResponseWriter, statusCode int, payload interface{}) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(statusCode)
	_ = json.NewEncoder(w).Encode(payload)
}

func generateToken() string {
	b := make([]byte, 16)
	_, _ = rand.Read(b)
	return hex.EncodeToString(b)
}

func main() {
	config = loadConfig()
	dsn := fmt.Sprintf("%s:%s@tcp(%s:%s)/%s?parseTime=true&charset=utf8mb4",
		config.User, config.Password, config.Host, config.Port, config.Database)

	var err error
	db, err = sql.Open("mysql", dsn)
	if err != nil {
		log.Fatalf("Gagal membuka koneksi database: %v", err)
	}
	defer db.Close()

	db.SetConnMaxLifetime(time.Minute * 5)
	db.SetMaxOpenConns(25)
	db.SetMaxIdleConns(10)

	if err := db.Ping(); err != nil {
		log.Printf("Peringatan: Database belum dapat dihubungi saat startup: %v", err)
	} else {
		log.Printf("Terkoneksi langsung ke MariaDB di %s:%s/%s", config.Host, config.Port, config.Database)
	}

	mux := http.NewServeMux()

	// 1. Health check
	mux.HandleFunc("/api/index.php", handleHealthCheck)

	// 2. Auth API
	mux.HandleFunc("/api/auth.php", handleAuth)

	// 3. Kategori API
	mux.HandleFunc("/api/kategori.php", handleKategori)

	// 4. Produk API
	mux.HandleFunc("/api/produk.php", handleProduk)

	// 5. Transaksi API
	mux.HandleFunc("/api/transaksi.php", handleTransaksi)

	// 6. Chat API
	mux.HandleFunc("/api/chat.php", handleChat)

	// 7. Proxy aset foto produk dari Raspberry Pi
	piURL, _ := url.Parse(config.PiWebUrl)
	proxy := httputil.NewSingleHostReverseProxy(piURL)
	mux.HandleFunc("/assets/", func(w http.ResponseWriter, r *http.Request) {
		r.Host = piURL.Host
		proxy.ServeHTTP(w, r)
	})

	serverAddr := "127.0.0.1:5050"
	log.Printf("QiOS Golang Engine berjalan di http://%s/", serverAddr)
	if err := http.ListenAndServe(serverAddr, mux); err != nil {
		log.Fatalf("Gagal menjalankan server: %v", err)
	}
}

// ----------------------------------------------------
// HANDLERS
// ----------------------------------------------------

func handleHealthCheck(w http.ResponseWriter, r *http.Request) {
	if enableCors(w, r) {
		return
	}
	dbStatus := "ready"
	if err := db.Ping(); err != nil {
		dbStatus = "db_disconnected"
	}

	sendJSON(w, http.StatusOK, map[string]interface{}{
		"success": true,
		"message": "QiOS Cendekia Go Engine REST API Server Aktif",
		"data": map[string]interface{}{
			"version":   "1.0.0-golang",
			"status":    dbStatus,
			"base_url":  "http://127.0.0.1:5050/",
			"db_target": config.Host,
		},
	})
}

func handleAuth(w http.ResponseWriter, r *http.Request) {
	if enableCors(w, r) {
		return
	}
	if r.Method != "POST" {
		sendJSON(w, http.StatusMethodNotAllowed, map[string]interface{}{"success": false, "message": "Method not allowed"})
		return
	}

	body, _ := io.ReadAll(r.Body)
	var req map[string]interface{}
	if err := json.Unmarshal(body, &req); err != nil {
		sendJSON(w, http.StatusBadRequest, map[string]interface{}{"success": false, "message": "Invalid JSON"})
		return
	}

	action, _ := req["action"].(string)

	if action == "logout" {
		userID := int(req["user_id"].(float64))
		_, _ = db.Exec("UPDATE users SET active_session_token = NULL WHERE id = ?", userID)
		sendJSON(w, http.StatusOK, map[string]interface{}{"success": true, "message": "Logout berhasil"})
		return
	}

	if action == "heartbeat" {
		userID := int(req["user_id"].(float64))
		token, _ := req["token"].(string)
		var validToken string
		err := db.QueryRow("SELECT COALESCE(active_session_token, '') FROM users WHERE id = ?", userID).Scan(&validToken)
		isValid := err == nil && (validToken == "" || validToken == token)
		if isValid {
			_, _ = db.Exec("UPDATE users SET last_active_at = NOW() WHERE id = ?", userID)
		}
		sendJSON(w, http.StatusOK, map[string]interface{}{
			"success": true,
			"data": map[string]interface{}{
				"is_valid": isValid,
			},
		})
		return
	}

	// Login
	username, _ := req["username"].(string)
	password, _ := req["password"].(string)
	deviceName, _ := req["device_name"].(string)
	forceLogin, _ := req["force_login"].(bool)

	if username == "" || password == "" {
		sendJSON(w, http.StatusBadRequest, map[string]interface{}{"success": false, "message": "Username dan password wajib diisi"})
		return
	}

	var (
		uID          int
		uUsername    string
		uPassword    string
		uNamaLengkap string
		uRole        string
		uToken       sql.NullString
		uDevice      sql.NullString
		uLastActive  sql.NullTime
	)

	err := db.QueryRow(`
		SELECT id, username, password, nama_lengkap, role, active_session_token, active_device_name, last_active_at 
		FROM users WHERE username = ?
	`, username).Scan(&uID, &uUsername, &uPassword, &uNamaLengkap, &uRole, &uToken, &uDevice, &uLastActive)

	if err != nil {
		sendJSON(w, http.StatusUnauthorized, map[string]interface{}{"success": false, "message": "Username atau password salah"})
		return
	}

	// Validasi password bcrypt
	passMatch := false
	if strings.HasPrefix(uPassword, "$2y$") || strings.HasPrefix(uPassword, "$2a$") || strings.HasPrefix(uPassword, "$2b$") {
		// Ubah $2y$ ke $2a$ jika diperlukan untuk bcrypt Go
		hashFixed := uPassword
		if strings.HasPrefix(hashFixed, "$2y$") {
			hashFixed = "$2a$" + hashFixed[4:]
		}
		if err := bcrypt.CompareHashAndPassword([]byte(hashFixed), []byte(password)); err == nil {
			passMatch = true
		}
	} else if uPassword == password {
		passMatch = true
	}

	if !passMatch {
		sendJSON(w, http.StatusUnauthorized, map[string]interface{}{"success": false, "message": "Username atau password salah"})
		return
	}

	// Cek jika akun sedang aktif di komputer lain
	isActiveSession := uToken.Valid && uToken.String != ""
	if isActiveSession && uLastActive.Valid {
		if time.Since(uLastActive.Time) > 15*time.Minute {
			isActiveSession = false
		}
	}

	if isActiveSession && !forceLogin {
		activeDev := "Komputer Lain"
		if uDevice.Valid && uDevice.String != "" {
			activeDev = uDevice.String
		}
		sendJSON(w, http.StatusConflict, map[string]interface{}{
			"success": false,
			"message": fmt.Sprintf("Akun sedang aktif di: %s", activeDev),
			"data": map[string]interface{}{
				"is_already_active":  true,
				"active_device_name": activeDev,
			},
		})
		return
	}

	newToken := generateToken()
	_, _ = db.Exec(`
		UPDATE users SET active_session_token = ?, active_device_name = ?, last_active_at = NOW() 
		WHERE id = ?
	`, newToken, deviceName, uID)

	sendJSON(w, http.StatusOK, map[string]interface{}{
		"success": true,
		"message": "Login berhasil",
		"data": map[string]interface{}{
			"token": newToken,
			"user": map[string]interface{}{
				"id":           uID,
				"username":     uUsername,
				"nama_lengkap": uNamaLengkap,
				"role":         uRole,
			},
		},
	})
}

func handleKategori(w http.ResponseWriter, r *http.Request) {
	if enableCors(w, r) {
		return
	}

	rows, err := db.Query(`
		SELECT k.id, k.nama_kategori, COALESCE(k.deskripsi, ''), COUNT(p.id) as total 
		FROM kategori k 
		LEFT JOIN produk p ON k.id = p.kategori_id 
		GROUP BY k.id 
		ORDER BY k.nama_kategori ASC
	`)
	if err != nil {
		sendJSON(w, http.StatusInternalServerError, map[string]interface{}{"success": false, "message": err.Error()})
		return
	}
	defer rows.Close()

	var list []map[string]interface{}
	for rows.Next() {
		var id, total int
		var nama, deskripsi string
		if err := rows.Scan(&id, &nama, &deskripsi, &total); err == nil {
			list = append(list, map[string]interface{}{
				"id":            id,
				"nama_kategori": nama,
				"deskripsi":     deskripsi,
				"total_produk":  total,
			})
		}
	}

	sendJSON(w, http.StatusOK, map[string]interface{}{
		"success":   true,
		"message":   "Data kategori berhasil diambil",
		"data":      list,
		"timestamp": time.Now().Format("2006-01-02 15:04:05"),
	})
}

func handleProduk(w http.ResponseWriter, r *http.Request) {
	if enableCors(w, r) {
		return
	}

	query := r.URL.Query()
	search := strings.TrimSpace(query.Get("search"))
	katIDStr := strings.TrimSpace(query.Get("kat_id"))

	sqlQuery := `
		SELECT p.id, p.kategori_id, COALESCE(k.nama_kategori, ''), COALESCE(p.satuan_id, 0),
		       COALESCE(s.nama_satuan, 'Pcs'), COALESCE(s.kode_satuan, 'Pcs'),
		       p.nama_produk, COALESCE(p.barcode, ''), p.harga, p.stok,
		       COALESCE(p.deskripsi, ''), COALESCE(p.foto, ''), p.created_at
		FROM produk p
		LEFT JOIN kategori k ON p.kategori_id = k.id
		LEFT JOIN satuan s ON p.satuan_id = s.id
		WHERE 1=1
	`
	var args []interface{}

	if search != "" {
		sqlQuery += " AND (p.nama_produk LIKE ? OR p.barcode LIKE ?)"
		pattern := "%" + search + "%"
		args = append(args, pattern, pattern)
	}
	if katIDStr != "" && katIDStr != "0" {
		if kID, err := strconv.Atoi(katIDStr); err == nil && kID > 0 {
			sqlQuery += " AND p.kategori_id = ?"
			args = append(args, kID)
		}
	}

	sqlQuery += " ORDER BY p.nama_produk ASC"

	rows, err := db.Query(sqlQuery, args...)
	if err != nil {
		sendJSON(w, http.StatusInternalServerError, map[string]interface{}{"success": false, "message": err.Error()})
		return
	}
	defer rows.Close()

	var items []map[string]interface{}
	for rows.Next() {
		var (
			pID, kID, sID, stok int
			namaKat, namaSat, kodeSat, namaProd, barcode, deskripsi, foto string
			harga float64
			createdAt time.Time
		)

		if err := rows.Scan(&pID, &kID, &namaKat, &sID, &namaSat, &kodeSat, &namaProd, &barcode, &harga, &stok, &deskripsi, &foto, &createdAt); err == nil {
			fotoURL := ""
			if foto != "" {
				fotoURL = fmt.Sprintf("http://172.16.5.11/%s", strings.TrimPrefix(foto, "/"))
			} else {
				fotoURL = "http://172.16.5.11/assets/images/logo.png"
			}

			items = append(items, map[string]interface{}{
				"id":            pID,
				"kategori_id":   kID,
				"nama_kategori": namaKat,
				"satuan_id":     sID,
				"nama_satuan":   namaSat,
				"kode_satuan":   kodeSat,
				"nama_produk":   namaProd,
				"barcode":       barcode,
				"harga":         harga,
				"stok":          stok,
				"deskripsi":     deskripsi,
				"foto":          foto,
				"foto_url":      fotoURL,
				"created_at":    createdAt.Format("2006-01-02 15:04:05"),
			})
		}
	}

	sendJSON(w, http.StatusOK, map[string]interface{}{
		"success": true,
		"message": "Data produk berhasil diambil",
		"data": map[string]interface{}{
			"total": len(items),
			"items": items,
		},
		"timestamp": time.Now().Format("2006-01-02 15:04:05"),
	})
}

func handleTransaksi(w http.ResponseWriter, r *http.Request) {
	if enableCors(w, r) {
		return
	}
	if r.Method != "POST" {
		sendJSON(w, http.StatusMethodNotAllowed, map[string]interface{}{"success": false, "message": "Method not allowed"})
		return
	}

	body, _ := io.ReadAll(r.Body)
	var req struct {
		UserID      int     `json:"user_id"`
		NamaPembeli string  `json:"nama_pembeli"`
		UangBayar   float64 `json:"uang_bayar"`
		Items       []struct {
			ProdukID int `json:"produk_id"`
			Qty      int `json:"qty"`
		} `json:"items"`
	}

	if err := json.Unmarshal(body, &req); err != nil || len(req.Items) == 0 {
		sendJSON(w, http.StatusBadRequest, map[string]interface{}{"success": false, "message": "Data transaksi tidak valid"})
		return
	}

	tx, err := db.Begin()
	if err != nil {
		sendJSON(w, http.StatusInternalServerError, map[string]interface{}{"success": false, "message": "Gagal memulai transaksi"})
		return
	}

	totalHarga := 0.0
	type CartDetail struct {
		ProdukID int
		Nama     string
		Harga    float64
		Qty      int
		Subtotal float64
	}
	var details []CartDetail

	for _, it := range req.Items {
		var pNama string
		var pHarga float64
		var pStok int

		err := tx.QueryRow("SELECT nama_produk, harga, stok FROM produk WHERE id = ?", it.ProdukID).Scan(&pNama, &pHarga, &pStok)
		if err != nil {
			tx.Rollback()
			sendJSON(w, http.StatusBadRequest, map[string]interface{}{"success": false, "message": fmt.Sprintf("Produk ID %d tidak ditemukan", it.ProdukID)})
			return
		}

		if pStok < it.Qty {
			tx.Rollback()
			sendJSON(w, http.StatusBadRequest, map[string]interface{}{"success": false, "message": fmt.Sprintf("Stok %s tidak mencukupi (sisa %d)", pNama, pStok)})
			return
		}

		sub := pHarga * float64(it.Qty)
		totalHarga += sub
		details = append(details, CartDetail{
			ProdukID: it.ProdukID,
			Nama:     pNama,
			Harga:    pHarga,
			Qty:      it.Qty,
			Subtotal: sub,
		})
	}

	if req.UangBayar < totalHarga {
		tx.Rollback()
		sendJSON(w, http.StatusBadRequest, map[string]interface{}{"success": false, "message": "Uang pembayaran kurang dari total belanja"})
		return
	}

	kembalian := req.UangBayar - totalHarga
	pembeli := req.NamaPembeli
	if pembeli == "" {
		pembeli = "Umum"
	}

	trxCode := fmt.Sprintf("TRX-%s-%04X", time.Now().Format("20060102"), time.Now().UnixNano()%0xFFFF)

	res, err := tx.Exec(`
		INSERT INTO pesanan (kode_pesanan, user_id, nama_pembeli, total_harga, uang_bayar, kembalian, status, created_at)
		VALUES (?, ?, ?, ?, ?, ?, 'SELESAI', NOW())
	`, trxCode, req.UserID, pembeli, totalHarga, req.UangBayar, kembalian)
	if err != nil {
		tx.Rollback()
		sendJSON(w, http.StatusInternalServerError, map[string]interface{}{"success": false, "message": "Gagal menyimpan pesanan"})
		return
	}

	pesananID, _ := res.LastInsertId()

	for _, d := range details {
		_, err = tx.Exec(`
			INSERT INTO pesanan_detail (pesanan_id, produk_id, nama_produk, harga_satuan, jumlah, subtotal)
			VALUES (?, ?, ?, ?, ?, ?)
		`, pesananID, d.ProdukID, d.Nama, d.Harga, d.Qty, d.Subtotal)
		if err != nil {
			tx.Rollback()
			sendJSON(w, http.StatusInternalServerError, map[string]interface{}{"success": false, "message": "Gagal menyimpan detail pesanan"})
			return
		}

		_, err = tx.Exec(`UPDATE produk SET stok = stok - ? WHERE id = ?`, d.Qty, d.ProdukID)
		if err != nil {
			tx.Rollback()
			sendJSON(w, http.StatusInternalServerError, map[string]interface{}{"success": false, "message": "Gagal memperbarui stok"})
			return
		}
	}

	if err := tx.Commit(); err != nil {
		sendJSON(w, http.StatusInternalServerError, map[string]interface{}{"success": false, "message": "Gagal commit database"})
		return
	}

	sendJSON(w, http.StatusOK, map[string]interface{}{
		"success": true,
		"message": "Transaksi berhasil diproses",
		"data": map[string]interface{}{
			"pesanan_id":   pesananID,
			"kode_pesanan": trxCode,
			"total_harga":  totalHarga,
			"uang_bayar":   req.UangBayar,
			"kembalian":    kembalian,
			"nama_pembeli": pembeli,
			"waktu":        time.Now().Format("02/01/2006 15:04:05"),
		},
	})
}

func handleChat(w http.ResponseWriter, r *http.Request) {
	if enableCors(w, r) {
		return
	}
	sendJSON(w, http.StatusOK, map[string]interface{}{
		"status":   "success",
		"messages": []interface{}{},
	})
}
