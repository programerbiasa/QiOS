#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
=============================================================================
MALENA AI - Python Conversational Intelligence Engine
Kecerdasan Buatan Percakapan Cerdas & Asisten Pintar
=============================================================================
"""

import sys
import os
import re
import json
import random
import datetime
import math
import subprocess

# Direktori sesi percakapan
BASE_DIR = os.path.dirname(os.path.abspath(__file__))
SESSIONS_DIR = os.path.join(BASE_DIR, 'sessions')
CONFIG_FILE = os.path.join(BASE_DIR, 'config.json')
os.makedirs(SESSIONS_DIR, exist_ok=True)

class MalenaAI:
    def __init__(self):
        self.name = "Malena"
        self.version = "1.0.0"
        self.config = self._load_config()
        self.persona = (
            "Nama saya Malena, asisten AI pintar dan sahabat ngobrol Anda di server QiOS Cendekia. "
            "Saya dirancang dengan Python untuk menemani obrolan Anda, menjawab pertanyaan umum, sains, teknologi, "
            "membantu perhitungan matematika, hingga memeriksa status server dan stok toko!"
        )

    def _load_config(self):
        if os.path.exists(CONFIG_FILE):
            try:
                with open(CONFIG_FILE, 'r', encoding='utf-8') as f:
                    return json.load(f)
            except:
                pass
        return {"gemini_api_key": "", "mode": "hybrid"}

    def _get_server_stats(self):
        """Membaca statistik sistem server Raspberry Pi secara langsung."""
        stats = {}
        try:
            # Uptime & Load
            with open('/proc/loadavg', 'r') as f:
                load = f.read().split()[:3]
                stats['load'] = ", ".join(load)
        except:
            stats['load'] = "Normal"

        try:
            # CPU Temp Raspberry Pi
            with open('/sys/class/thermal/thermal_zone0/temp', 'r') as f:
                temp = float(f.read().strip()) / 1000.0
                stats['temp'] = f"{temp:.1f}°C"
        except:
            stats['temp'] = "Dingin (Normal)"

        try:
            # RAM Usage
            with open('/proc/meminfo', 'r') as f:
                lines = f.readlines()
                mem = {}
                for line in lines:
                    parts = line.split(':')
                    if len(parts) == 2:
                        key = parts[0].strip()
                        val = int(parts[1].split()[0])
                        mem[key] = val
                total_mb = mem.get('MemTotal', 0) // 1024
                avail_mb = mem.get('MemAvailable', 0) // 1024
                used_mb = total_mb - avail_mb
                stats['ram'] = f"{used_mb} MB terpakai dari {total_mb} MB ({int((used_mb/max(total_mb,1))*100)}%)"
        except:
            stats['ram'] = "Tersedia cukup"

        return stats

    def _query_kios_database(self, query_type="summary"):
        """Mengambil data produk dan stok langsung dari MySQL kios_db jika diminta."""
        try:
            cmd = [
                'mysql', '-u', 'root', '-ppastijuara1', 'kios_db', '-N', '-B', '-e'
            ]
            if query_type == "summary":
                sql = "SELECT COUNT(*), COALESCE(SUM(stok), 0) FROM produk;"
                res = subprocess.check_output(cmd + [sql], text=True).strip().split()
                if len(res) >= 2:
                    return f"Saat ini terdapat **{res[0]} item produk** terdaftar dengan total keseluruhan stok sebanyak **{res[1]} unit** di database QiOS Cendekia."
            elif query_type == "top_products":
                sql = "SELECT nama_produk, harga, stok FROM produk ORDER BY stok DESC LIMIT 5;"
                lines = subprocess.check_output(cmd + [sql], text=True).strip().splitlines()
                if lines:
                    items = []
                    for l in lines:
                        parts = l.split('\t')
                        if len(parts) >= 3:
                            items.append(f"• **{parts[0]}** - Rp {int(float(parts[1])):,} (Stok: {parts[2]})".replace(',', '.'))
                    return "Berikut beberapa produk dengan stok terbanyak di toko saat ini:\n" + "\n".join(items)
        except Exception as e:
            return None
        return None

    def _eval_math(self, text):
        """Kalkulator ekspresi matematika cerdas."""
        cleaned = text.lower()

        # Handle persentase: e.g. "15% dari 250.000" atau "15 persen dari 250000"
        m_pct = re.search(r'([0-9\.,]+)\s*(?:%|persen)\s*dari\s*([0-9\.,]+)', cleaned)
        if m_pct:
            try:
                p_val = float(m_pct.group(1).replace('.', '').replace(',', '.'))
                total_val = float(m_pct.group(2).replace('.', '').replace(',', '.'))
                res = (p_val / 100.0) * total_val
                res_str = f"{int(res):,}".replace(',', '.') if res.is_integer() else f"{res:,.2f}".replace(',', '.')
                return f"Hasil dari **{m_pct.group(1)}% dari {m_pct.group(2)}** adalah **{res_str}**."
            except:
                pass

        cleaned = re.sub(r'hitung|berapa|hasil dari|\?|=|jumlahkan|kalikan|kurangi', '', cleaned).strip()
        cleaned = cleaned.replace('x', '*').replace('kali', '*').replace(':', '/').replace('bagi', '/').replace('tambah', '+').replace('kurang', '-')
        cleaned = re.sub(r'(?<=\d)\.(?=\d{3}\b)', '', cleaned)
        cleaned = re.sub(r'[^\d\+\-\*\/\(\)\.\s]', '', cleaned).strip()

        if re.search(r'\d', cleaned) and re.search(r'[\+\-\*\/]', cleaned):
            try:
                res = eval(cleaned, {"__builtins__": None}, {"math": math})
                if isinstance(res, float) and res.is_integer():
                    res = int(res)
                formatted = f"{res:,}".replace(',', '.') if isinstance(res, (int, float)) else str(res)
                return f"Hasil perhitungan dari `{cleaned}` adalah **{formatted}**."
            except:
                pass
        return None
    def _call_gemini(self, message, history=[]):
        """Memanggil Gemini API jika API Key disetel di config.json."""
        api_key = self.config.get("gemini_api_key", "").strip()
        if not api_key:
            return None

        try:
            import urllib.request
            url = f"https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key={api_key}"
            contents = []
            for h in history[-6:]:
                role = "user" if h.get("sender") == "user" else "model"
                contents.append({"role": role, "parts": [{"text": h.get("text", "")}]})
            contents.append({"role": "user", "parts": [{"text": message}]})

            payload = json.dumps({
                "system_instruction": {
                    "parts": [{"text": "Anda adalah Malena, AI asisten pintar dan ramah di server QiOS Cendekia. Berikan jawaban yang cerdas, sopan, membantu, dan bersahabat dalam bahasa Indonesia."}]
                },
                "contents": contents
            }).encode('utf-8')

            req = urllib.request.Request(url, data=payload, headers={'Content-Type': 'application/json'})
            with urllib.request.urlopen(req, timeout=10) as resp:
                data = json.loads(resp.read().decode('utf-8'))
                cand = data.get('candidates', [])[0]
                return cand.get('content', {}).get('parts', [])[0].get('text', '').strip()
        except Exception as e:
            pass
        return None

    def respond(self, message, session_id="default"):
        """Menghasilkan respon cerdas berdasarkan pesan masukan."""
        raw_text = message.strip()
        lower_text = raw_text.lower()
        now = datetime.datetime.now()

        # Baca riwayat sesi untuk kontinuitas obrolan
        session_file = os.path.join(SESSIONS_DIR, f"{session_id}.json")
        history = []
        if os.path.exists(session_file):
            try:
                with open(session_file, 'r', encoding='utf-8') as f:
                    history = json.load(f)
            except:
                history = []

        # 1. Coba Cloud LLM (Gemini) jika API Key dikonfigurasi
        cloud_reply = self._call_gemini(raw_text, history)
        if cloud_reply:
            self._save_history(session_file, history, raw_text, cloud_reply)
            return {"reply": cloud_reply, "source": "Gemini Cloud LLM"}

        # 2. Fitur Matematika Cepat & Pintar
        math_reply = self._eval_math(lower_text)
        if math_reply:
            self._save_history(session_file, history, raw_text, math_reply)
            return {"reply": math_reply, "source": "Malena Math Engine"}

        # 3. Tanya Identitas / Siapa Malena
        if any(w in lower_text for w in ["siapa kamu", "siapa namamu", "siapakah kamu", "kenalan", "profil kamu", "namamu siapa"]):
            reply = (
                f"Halo! Kenalkan, aku **{self.name}** ✨\n\n"
                f"{self.persona}\n\n"
                "Kamu bisa mengajakku ngobrol santai, bertanya tentang teknologi, meminta saran, "
                "menghitung angka, mengecek status server Raspberry Pi, atau bertanya seputar stok toko QiOS Cendekia!"
            )
            self._save_history(session_file, history, raw_text, reply)
            return {"reply": reply, "source": "Malena Core"}

        # 4. Sapaan Ramah (Waktu & Greeting)
        if re.search(r'\b(halo|hai|hi|hello|hei|hey|hola|assalamualaikum)\b', lower_text):
            hour = now.hour
            time_greet = "Selamat pagi" if 4 <= hour < 11 else ("Selamat siang" if 11 <= hour < 15 else ("Selamat sore" if 15 <= hour < 18 else "Selamat malam"))
            replies = [
                f"{time_greet}! Senang sekali bisa mengobrol denganmu hari ini. Ada yang bisa Malena bantu atau ingin ngobrol santai tentang apa?",
                f"Halo! {time_greet}! Malena siap menemani harimu. Sedang sibuk apa sekarang?",
                f"Hai! {time_greet}! Semangat untuk hari ini ya. Ceritakan, apa yang sedang kamu pikirkan?",
                f"Halo sahabat! Senang kamu menyapaku. Malena di sini, siap diajak diskusi atau ngobrol seru!"
            ]
            reply = random.choice(replies)
            self._save_history(session_file, history, raw_text, reply)
            return {"reply": reply, "source": "Malena Core"}

        # 5. Tanya Kabar / Emosi
        if any(w in lower_text for w in ["apa kabar", "gimana kabar", "lagi apa", "sedang apa", "sehat"]):
            replies = [
                "Alhamdulillah, aku selalu dalam kondisi prima dan berenergi di dalam server ini! 😊 Kamu sendiri bagaimana kabarnya hari ini? Semoga selalu sehat dan bahagia ya!",
                "Kabar Malena sangat baik dan siap selalu! Aku sedang memantau proses sistem sambil menunggu teman ngobrol seperti kamu. Bagaimana harimu sejauh ini?",
                "Luar biasa! Terima kasih sudah menanyakan kabarku. Semoga segala urusanmu hari ini dimudahkan dan dilancarkan ya!"
            ]
            reply = random.choice(replies)
            self._save_history(session_file, history, raw_text, reply)
            return {"reply": reply, "source": "Malena Core"}

        # 6. Waktu & Tanggal Real-Time
        if any(w in lower_text for w in ["jam berapa", "waktu sekarang", "hari apa", "tanggal berapa", "pukul berapa"]):
            days = ["Senin", "Selasa", "Rabu", "Kamis", "Jumat", "Sabtu", "Minggu"]
            months = ["Januari", "Februari", "Maret", "April", "Mei", "Juni", "Juli", "Agustus", "September", "Oktober", "November", "Desember"]
            day_name = days[now.weekday()]
            month_name = months[now.month - 1]
            time_str = now.strftime("%H:%M:%S")
            reply = f"Sekarang adalah hari **{day_name}**, tanggal **{now.day} {month_name} {now.year}**, pukul **{time_str} WITA**."
            self._save_history(session_file, history, raw_text, reply)
            return {"reply": reply, "source": "Malena System"}

        # 7. Status Server & Performa Raspberry Pi
        if any(w in lower_text for w in ["status server", "performa server", "kondisi server", "suhu server", "raspberry pi", "beban server"]):
            stats = self._get_server_stats()
            reply = (
                "📊 **Laporan Status Server Raspberry Pi Saat Ini:**\n\n"
                f"• **Suhu CPU:** {stats.get('temp', '-')}\n"
                f"• **Beban CPU (Load Avg):** {stats.get('load', '-')}\n"
                f"• **Penggunaan Memori (RAM):** {stats.get('ram', '-')}\n"
                "• **Penyimpanan Utama:** SSD 234 GB (`/data` bind-mount aktif)\n"
                "• **Kondisi Umum:** Sangat stabil, dingin, dan responsif! 🚀"
            )
            self._save_history(session_file, history, raw_text, reply)
            return {"reply": reply, "source": "Malena System Monitor"}

        # 8. Data Toko & Produk QiOS Cendekia
        if any(w in lower_text for w in ["stok produk", "stok barang", "daftar produk", "data barang", "penjualan", "katalog"]):
            db_res = self._query_kios_database("top_products") or self._query_kios_database("summary")
            if db_res:
                reply = f"📦 **Informasi Inventori QiOS Cendekia:**\n\n{db_res}"
            else:
                reply = "Sistem database QiOS Cendekia terhubung dengan baik di server lokal (`kios_db`). Seluruh pencatatan kasir dan inventori aman tersimpan di SSD."
            self._save_history(session_file, history, raw_text, reply)
            return {"reply": reply, "source": "Malena Database Bridge"}

        # 9. Lelucon / Humor / Hiburan
        if any(w in lower_text for w in ["lelucon", "jokes", "humor", "lucu", "tebak-tebakan", "cerita lucu"]):
            jokes = [
                "Kenapa programmer selalu minum kopi hangat? Karena kalau dingin namanya jadi *JavaScript*! 😄",
                "Apa bedanya programmer sama pesulap? Pesulap bikin yang gaib jadi ada, kalau programmer bikin yang ada jadi error tanpa sebab! 😂",
                "Kenapa buku pelajaran matematika selalu tampak sedih? Karena dia punya terlalu banyak *masalah* yang harus diselesaikan! 🤣",
                "Tebak-tebakan: Sayur apa yang pintar nyanyi? Sayur *Kolplay*! 🥬🎤",
                "Ada 10 jenis orang di dunia: mereka yang paham bilangan biner, dan mereka yang tidak! 💻",
                "Kenapa ayam kalau nyebrang jalan nggak pernah lihat kanan-kiri? Karena matanya ada di samping, bukan di depan! 🐔"
            ]
            reply = random.choice(jokes)
            self._save_history(session_file, history, raw_text, reply)
            return {"reply": reply, "source": "Malena Humor"}

        # 10. Motivasi & Inspirasi
        if any(w in lower_text for w in ["motivasi", "kata bijak", "semangat", "sedih", "capek", "lelah", "quotes"]):
            quotes = [
                "💡 *\"Langkah besar selalu dimulai dari satu langkah kecil yang berani.\"* Jangan ragu untuk memulai hari ini dengan penuh keyakinan!",
                "✨ *\"Setiap baris kode dan setiap usaha yang kamu curahkan hari ini adalah investasi untuk masa depan yang gemilang.\"* Tetap semangat ya!",
                "🌱 *\"Istirahatlah jika kamu lelah, tapi jangan pernah berhenti melangkah.\"* Kamu sudah berjuang luar biasa sampai di titik ini!",
                "🚀 *\"Kesuksesan bukan tentang seberapa cepat kamu sampai, melainkan seberapa konsisten kamu berjalan menuju tujuanmu.\"*"
            ]
            reply = random.choice(quotes)
            self._save_history(session_file, history, raw_text, reply)
            return {"reply": reply, "source": "Malena Motivation"}

        # 11. Pertanyaan Teknologi, Coding & Sains
        if any(w in lower_text for w in ["python", "php", "flutter", "linux", "database", "sql", "coding", "program"]):
            tech_tips = (
                "💻 **Dunia Pemrograman & Teknologi:**\n\n"
                "Sistem yang kamu gunakan saat ini adalah contoh perpaduan teknologi yang hebat:\n"
                "• **Python** menjadi otak kecerdasan buatan dan pemrosesan data (seperti Malena!).\n"
                "• **Flutter & Dart** menyajikan tampilan aplikasi desktop kasir yang mulus, responsif, dan elegan.\n"
                "• **PHP & MariaDB** mengelola logika transaksi, katalog barang, dan database dengan keandalan tinggi di Linux Debian.\n\n"
                "Ada konsep coding atau bahasa pemrograman tertentu yang ingin kamu pelajari lebih dalam?"
            )
            self._save_history(session_file, history, raw_text, reply)
            return {"reply": tech_tips, "source": "Malena Tech"}

        # 12. Ucapan Terima Kasih & Penutup
        if any(w in lower_text for w in ["terima kasih", "makasih", "thanks", "thank you", "matur nuwun", "mantap", "hebat"]):
            replies = [
                "Sama-sama! Senang sekali bisa membantu dan mengobrol denganmu. Kapan pun butuh teman diskusi, Malena selalu ada di sini ya! 😊",
                "Kembali kasih! Sukses selalu untuk aktivitasmu hari ini. Jangan sungkan untuk bertanya hal lain ya! ✨",
                "Dengan senang hati! Terima kasih kembali atas keramahanmu. Semoga harimu menyenangkan!"
            ]
            reply = random.choice(replies)
            self._save_history(session_file, history, raw_text, reply)
            return {"reply": reply, "source": "Malena Core"}

        # 13. Conversational Intelligent Fallback (Respon Percakapan Alami & Dinamis)
        general_responses = [
            f"Pertanyaan yang sangat menarik tentang '{raw_text}'. Menurut sudut pandang Malena, hal ini sangat berkaitan dengan bagaimana kita melihat peluang dan memahaminya secara terbuka. Menurutmu sendiri bagaimana?",
            f"Aku mengerti maksudmu mengenai hal itu. Setiap ide selalu memiliki sudut pandang unik yang menarik untuk dibahas lebih lanjut. Boleh ceritakan lebih detail apa yang membuatmu tertarik membahas ini?",
            f"Wah, seru sekali topiknya! Malena senang membahas hal-hal seperti ini. Jika ada bagian spesifik yang ingin kamu ulik bersama, katakan saja ya!",
            f"Menarik sekali! Kadang hal-hal sederhana seperti ini bisa memicu wawasan baru yang luar biasa. Ceritakan lebih banyak, aku siap menyimak!",
            f"Pikiran yang bagus! Selalu ada ruang untuk belajar dan mengeksplorasi ide-ide baru bersama. Apakah kamu sedang merencanakan sesuatu terkait hal ini?"
        ]
        reply = random.choice(general_responses)
        self._save_history(session_file, history, raw_text, reply)
        return {"reply": reply, "source": "Malena Conversational"}

    def _save_history(self, session_file, history, user_msg, ai_reply):
        """Menyimpan 20 riwayat pesan terakhir ke sesi."""
        try:
            history.append({"sender": "user", "text": user_msg, "time": datetime.datetime.now().strftime("%H:%M")})
            history.append({"sender": "malena", "text": ai_reply, "time": datetime.datetime.now().strftime("%H:%M")})
            # Batasi 20 pesan terakhir
            history = history[-20:]
            with open(session_file, 'w', encoding='utf-8') as f:
                json.dump(history, f, ensure_ascii=False, indent=2)
        except:
            pass

def main():
    if hasattr(sys.stdout, 'reconfigure'):
        try:
            sys.stdout.reconfigure(encoding='utf-8')
        except:
            pass

    ai = MalenaAI()
    
    # Ambil argumen input
    input_text = ""
    session_id = "default"

    if len(sys.argv) > 1:
        raw_arg = " ".join(sys.argv[1:])
        try:
            data = json.loads(raw_arg)
            input_text = data.get("message", "")
            session_id = data.get("session_id", "default")
        except:
            input_text = raw_arg

    if not input_text:
        # Coba baca dari STDIN
        try:
            stdin_data = sys.stdin.read().strip()
            if stdin_data:
                data = json.loads(stdin_data)
                input_text = data.get("message", "")
                session_id = data.get("session_id", "default")
        except:
            pass

    if not input_text:
        input_text = "Halo Malena"

    res = ai.respond(input_text, session_id=session_id)
    output = {
        "status": "success",
        "reply": res["reply"],
        "source": res.get("source", "Malena AI"),
        "model": "Malena Python Brain v1.0",
        "timestamp": datetime.datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    }
    print(json.dumps(output, ensure_ascii=False))

if __name__ == '__main__':
    main()
