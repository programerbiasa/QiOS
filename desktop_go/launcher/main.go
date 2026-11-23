package main

import (
	"net/http"
	"os"
	"os/exec"
	"path/filepath"
	"syscall"
	"time"
)

func isEngineRunning() bool {
	client := http.Client{Timeout: 500 * time.Millisecond}
	resp, err := client.Get("http://127.0.0.1:5050/api/index.php")
	if err == nil && resp.StatusCode == 200 {
		resp.Body.Close()
		return true
	}
	return false
}

func main() {
	exeDir, _ := filepath.Abs(filepath.Dir(os.Args[0]))

	var engineCmd *exec.Cmd

	// 1. Jalankan Go Engine jika belum berjalan
	if !isEngineRunning() {
		enginePath := filepath.Join(exeDir, "qios_engine.exe")
		if _, err := os.Stat(enginePath); err == nil {
			engineCmd = exec.Command(enginePath)
			engineCmd.Dir = exeDir
			engineCmd.SysProcAttr = &syscall.SysProcAttr{
				HideWindow:    true,
				CreationFlags: 0x08000000, // CREATE_NO_WINDOW
			}
			_ = engineCmd.Start()
		}
	}

	// Tunggu engine siap
	for i := 0; i < 20; i++ {
		if isEngineRunning() {
			break
		}
		time.Sleep(100 * time.Millisecond)
	}

	// 2. Jalankan Flutter Desktop POS
	flutterPath := filepath.Join(exeDir, "kiosk_cendekia.exe")
	if _, err := os.Stat(flutterPath); err != nil {
		flutterPath = filepath.Join(exeDir, "QiOS-Cendekia.exe")
	}

	flutterCmd := exec.Command(flutterPath)
	flutterCmd.Dir = exeDir
	_ = flutterCmd.Run()

	// 3. Setelah Flutter ditutup, matikan background engine jika kita yang menyalakan
	if engineCmd != nil && engineCmd.Process != nil {
		_ = engineCmd.Process.Kill()
	}
}
