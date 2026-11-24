<?php
/**
 * ProdukExcelManager.php
 * Generator Template Excel (.xlsx) dan Parser Import Massal Produk untuk QiOS POS.
 * Murni menggunakan ekstensi bawaan PHP (ZipArchive, SimpleXML/DOM, GD) tanpa dependensi Composer.
 */

class ProdukExcelManager {

    /**
     * Helper escaping XML string
     */
    private static function xmlEsc($str) {
        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', (string)$str);
        return htmlspecialchars($clean ?? '', ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /**
     * Mengubah indeks kolom angka (1-based) ke huruf Excel (1 -> A, 2 -> B, ..., 26 -> Z, 27 -> AA)
     */
    private static function colLetter($idx1Based) {
        $letter = '';
        $n = (int)$idx1Based;
        while ($n > 0) {
            $rem = ($n - 1) % 26;
            $letter = chr(65 + $rem) . $letter;
            $n = intdiv($n - 1, 26);
        }
        return $letter;
    }

    /**
     * Generate & Stream file Template Excel (.xlsx) ke browser
     */
    public static function downloadTemplate(PDO $pdo) {
        // Ambil daftar kategori dari database
        try {
            $stmt = $pdo->query("SELECT nama_kategori FROM kategori ORDER BY nama_kategori ASC");
            $kategoriList = $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (Exception $e) {
            $kategoriList = [];
        }
        if (empty($kategoriList)) {
            $kategoriList = ['Makanan', 'Minuman', 'Snack', 'Sembako', 'Lain-lain'];
        }

        // Ambil daftar satuan dari database
        try {
            $stmt = $pdo->query("SELECT kode_satuan FROM satuan ORDER BY kode_satuan ASC");
            $satuanList = $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (Exception $e) {
            $satuanList = [];
        }
        if (empty($satuanList)) {
            $satuanList = ['Pcs', 'Btl', 'Bks', 'Dus', 'Pak', 'Cup', 'Kg'];
        }

        // Siapkan string formula dropdown (dipisahkan koma, maksimal 250 karakter agar aman di Excel)
        $cleanKats = [];
        $lenKats = 0;
        foreach ($kategoriList as $k) {
            $k = trim(str_replace(['"', ',', ';'], '', $k));
            if ($k === '') continue;
            if ($lenKats + strlen($k) + 1 > 240) break;
            $cleanKats[] = $k;
            $lenKats += strlen($k) + 1;
        }
        $strKatValidation = implode(',', $cleanKats);

        $cleanSats = [];
        $lenSats = 0;
        foreach ($satuanList as $s) {
            $s = trim(str_replace(['"', ',', ';'], '', $s));
            if ($s === '') continue;
            if ($lenSats + strlen($s) + 1 > 240) break;
            $cleanSats[] = $s;
            $lenSats += strlen($s) + 1;
        }
        $strSatValidation = implode(',', $cleanSats);

        // Cek apakah ekstensi ZipArchive terpasang di PHP
        if (!class_exists('ZipArchive')) {
            // Fallback Cerdas: Jika PHP server tidak memiliki modul php-zip, buatkan template format CSV/Excel kompatibel
            self::downloadCsvTemplate($kategoriList, $satuanList);
            exit;
        }

        // Buat file temporer ZIP
        $tmpFile = tempnam(sys_get_temp_dir(), 'qios_tpl_') . '.xlsx';
        $zip = new ZipArchive();
        if ($zip->open($tmpFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            self::downloadCsvTemplate($kategoriList, $satuanList);
            exit;
        }

        // 1. [Content_Types].xml
        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>';
        $zip->addFromString('[Content_Types].xml', $contentTypes);

        // 2. _rels/.rels
        $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
        $zip->addFromString('_rels/.rels', $rootRels);

        // 3. xl/_rels/workbook.xml.rels
        $wbRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';
        $zip->addFromString('xl/_rels/workbook.xml.rels', $wbRels);

        // 4. xl/workbook.xml
        $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets>'
            . '<sheet name="Master Produk" sheetId="1" r:id="rId1"/>'
            . '</sheets>'
            . '</workbook>';
        $zip->addFromString('xl/workbook.xml', $workbook);

        // 5. xl/styles.xml
        // Styles:
        // 0: default
        // 1: Header Table (Dark background #1E293B, White text, Bold, Center)
        // 2: Cell Left (Border, align left)
        // 3: Cell Center (Border, align center)
        // 4: Cell Number / Currency (Border, align right, format standard)
        // 5: Cell Note / Hint (Italic, soft gray)
        $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0"/></numFmts>'
            . '<fonts count="4">'
            .   '<font><sz val="10"/><color rgb="FF000000"/><name val="Calibri"/></font>'
            .   '<font><b/><sz val="10.5"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
            .   '<font><i/><sz val="9"/><color rgb="FF64748B"/><name val="Calibri"/></font>'
            .   '<font><b/><sz val="10"/><color rgb="FF0F172A"/><name val="Calibri"/></font>'
            . '</fonts>'
            . '<fills count="3">'
            .   '<fill><patternFill patternType="none"/></fill>'
            .   '<fill><patternFill patternType="gray125"/></fill>'
            .   '<fill><patternFill patternType="solid"><fgColor rgb="FF1E293B"/></patternFill></fill>'
            . '</fills>'
            . '<borders count="2">'
            .   '<border><left/><right/><top/><bottom/><diagonal/></border>'
            .   '<border>'
            .     '<left style="thin"><color rgb="FFCBD5E1"/></left>'
            .     '<right style="thin"><color rgb="FFCBD5E1"/></right>'
            .     '<top style="thin"><color rgb="FFCBD5E1"/></top>'
            .     '<bottom style="thin"><color rgb="FFCBD5E1"/></bottom>'
            .   '</border>'
            . '</borders>'
            . '<cellStyleXfs count="1">'
            .   '<xf numFmtId="0" fontId="0" fillId="0" borderId="0"/>'
            . '</cellStyleXfs>'
            . '<cellXfs count="6">'
            .   '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .   '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            .   '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>'
            .   '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
            .   '<xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>'
            .   '<xf numFmtId="0" fontId="2" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>'
            . '</cellXfs>'
            . '</styleSheet>';
        $zip->addFromString('xl/styles.xml', $styles);

        // 6. xl/worksheets/sheet1.xml
        // Kolom:
        // A: Nama Barang * (width 32)
        // B: Barcode (width 20)
        // C: Kategori (Pilihan) * (width 20)
        // D: Satuan Barang (Pilihan) * (width 16)
        // E: Harga Jual * (width 16)
        // F: Stok Awal * (width 14)
        // G: Deskripsi Singkat (width 36)
        // H: Foto (Sisipkan Gambar / Nama File) (width 34)

        $headers = [
            'Nama Barang *',
            'Barcode',
            'Kategori (Pilihan) *',
            'Satuan Barang (Pilihan) *',
            'Harga Jual *',
            'Stok Awal *',
            'Deskripsi Singkat',
            'Foto (Sisipkan Gambar / Nama File)'
        ];

        // Baris contoh untuk memandu user
        $sampleKat1 = $cleanKats[1] ?? 'Minuman';
        $sampleKat2 = $cleanKats[0] ?? 'Makanan';
        $sampleSat1 = $cleanSats[5] ?? 'Cup';
        $sampleSat2 = $cleanSats[2] ?? 'Bks';
        $sampleSat3 = $cleanSats[1] ?? 'Btl';

        $samples = [
            [
                'Kopi Susu Gula Aren',
                '899275321001',
                $sampleKat1,
                $sampleSat1,
                15000,
                50,
                'Kopi susu espresso segar dengan gula aren murni',
                'Sisipkan foto di sel ini (atau kosongkan)'
            ],
            [
                'Roti Coklat Keju Lumer',
                '899100110022',
                $sampleKat2,
                $sampleSat2,
                8500,
                30,
                'Roti bakar isi coklat pasta dan taburan keju',
                ''
            ],
            [
                'Air Mineral Dingin 600ml',
                '899300550033',
                $sampleKat1,
                $sampleSat3,
                4000,
                100,
                'Air mineral murni kemasan botol',
                ''
            ]
        ];

        $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetViews><sheetView tabSelected="1" workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            . '<sheetFormatPr defaultRowHeight="24"/>'
            . '<cols>'
            .   '<col min="1" max="1" width="34" customWidth="1"/>'
            .   '<col min="2" max="2" width="22" customWidth="1"/>'
            .   '<col min="3" max="3" width="22" customWidth="1"/>'
            .   '<col min="4" max="4" width="20" customWidth="1"/>'
            .   '<col min="5" max="5" width="18" customWidth="1"/>'
            .   '<col min="6" max="6" width="16" customWidth="1"/>'
            .   '<col min="7" max="7" width="40" customWidth="1"/>'
            .   '<col min="8" max="8" width="36" customWidth="1"/>'
            . '</cols>'
            . '<sheetData>';

        // Baris Header (Row 1, tinggi 32)
        $sheetXml .= '<row r="1" ht="32" customHeight="1">';
        foreach ($headers as $cIdx => $hText) {
            $colLetter = self::colLetter($cIdx + 1);
            $sheetXml .= '<c r="' . $colLetter . '1" t="inlineStr" s="1"><is><t xml:space="preserve">' . self::xmlEsc($hText) . '</t></is></c>';
        }
        $sheetXml .= '</row>';

        // Baris Contoh (Row 2, 3, 4)
        foreach ($samples as $sIdx => $row) {
            $rNum = $sIdx + 2;
            $sheetXml .= '<row r="' . $rNum . '" ht="26" customHeight="1">';
            // A: Nama Barang (s=2: left)
            $sheetXml .= '<c r="A' . $rNum . '" t="inlineStr" s="2"><is><t xml:space="preserve">' . self::xmlEsc($row[0]) . '</t></is></c>';
            // B: Barcode (s=3: center)
            $sheetXml .= '<c r="B' . $rNum . '" t="inlineStr" s="3"><is><t xml:space="preserve">' . self::xmlEsc($row[1]) . '</t></is></c>';
            // C: Kategori (s=3: center)
            $sheetXml .= '<c r="C' . $rNum . '" t="inlineStr" s="3"><is><t xml:space="preserve">' . self::xmlEsc($row[2]) . '</t></is></c>';
            // D: Satuan (s=3: center)
            $sheetXml .= '<c r="D' . $rNum . '" t="inlineStr" s="3"><is><t xml:space="preserve">' . self::xmlEsc($row[3]) . '</t></is></c>';
            // E: Harga (s=4: num)
            $sheetXml .= '<c r="E' . $rNum . '" s="4"><v>' . (float)$row[4] . '</v></c>';
            // F: Stok (s=4: num)
            $sheetXml .= '<c r="F' . $rNum . '" s="4"><v>' . (int)$row[5] . '</v></c>';
            // G: Deskripsi (s=2: left)
            $sheetXml .= '<c r="G' . $rNum . '" t="inlineStr" s="2"><is><t xml:space="preserve">' . self::xmlEsc($row[6]) . '</t></is></c>';
            // H: Foto (s=5: hint)
            $sheetXml .= '<c r="H' . $rNum . '" t="inlineStr" s="5"><is><t xml:space="preserve">' . self::xmlEsc($row[7]) . '</t></is></c>';
            $sheetXml .= '</row>';
        }

        // Siapkan baris kosong tambahan (5 s/d 100) dengan style border rapi
        for ($rNum = 5; $rNum <= 50; $rNum++) {
            $sheetXml .= '<row r="' . $rNum . '" ht="24" customHeight="1">';
            $sheetXml .= '<c r="A' . $rNum . '" s="2"/>';
            $sheetXml .= '<c r="B' . $rNum . '" s="3"/>';
            $sheetXml .= '<c r="C' . $rNum . '" s="3"/>';
            $sheetXml .= '<c r="D' . $rNum . '" s="3"/>';
            $sheetXml .= '<c r="E' . $rNum . '" s="4"/>';
            $sheetXml .= '<c r="F' . $rNum . '" s="4"/>';
            $sheetXml .= '<c r="G' . $rNum . '" s="2"/>';
            $sheetXml .= '<c r="H' . $rNum . '" s="2"/>';
            $sheetXml .= '</row>';
        }

        $sheetXml .= '</sheetData>';

        // Data Validations untuk Dropdown di Excel (Kategori di Kolom C & Satuan di Kolom D)
        $dataValidationsXml = '';
        $valCount = 0;
        if (!empty($strKatValidation)) {
            $dataValidationsXml .= '<dataValidation type="list" allowBlank="1" showInputMessage="1" showErrorMessage="1" sqref="C2:C500"><formula1>&quot;' . self::xmlEsc($strKatValidation) . '&quot;</formula1></dataValidation>';
            $valCount++;
        }
        if (!empty($strSatValidation)) {
            $dataValidationsXml .= '<dataValidation type="list" allowBlank="1" showInputMessage="1" showErrorMessage="1" sqref="D2:D500"><formula1>&quot;' . self::xmlEsc($strSatValidation) . '&quot;</formula1></dataValidation>';
            $valCount++;
        }

        if ($valCount > 0) {
            $sheetXml .= '<dataValidations count="' . $valCount . '">' . $dataValidationsXml . '</dataValidations>';
        }

        $sheetXml .= '</worksheet>';
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);

        $zip->close();

        // Stream file ke browser
        if (file_exists($tmpFile)) {
            $filename = 'Template_Import_Barang_QiOS_' . date('Ymd_His') . '.xlsx';
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . filesize($tmpFile));
            header('Cache-Control: max-age=0');
            header('Pragma: public');
            readfile($tmpFile);
            @unlink($tmpFile);
            exit;
        } else {
            header('HTTP/1.1 500 Internal Server Error');
            echo 'File template tidak ditemukan.';
            exit;
        }
    }

    /**
     * Parsing dan Import Barang dari file Excel (.xlsx) yang diunggah
     * Mendukung:
     * - Parsing baris data Excel (Nama, Barcode, Kategori, Satuan, Harga, Stok, Deskripsi)
     * - Ekstraksi foto gambar yang disisipkan di sel kolom Foto (drawing1.xml + xl/media/image*.png/jpg)
     * - Opsi pembaruan produk jika barcode/nama sudah ada
     */
    public static function importFromXlsx(PDO $pdo, $filePath, array $options = []) {
        $updateExisting = !empty($options['update_existing']);

        if (!file_exists($filePath) || !is_readable($filePath)) {
            throw new Exception("File dokumen tidak ditemukan atau tidak dapat dibaca di server.");
        }

        // Cek jika file berupa CSV
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        if ($ext === 'csv' || !class_exists('ZipArchive')) {
            // Coba buka sebagai CSV
            $handle = @fopen($filePath, 'r');
            if ($handle !== false) {
                $firstBytes = fread($handle, 4);
                fclose($handle);
                // Jika bukan zip header (PK..), proses sebagai CSV
                if (substr($firstBytes, 0, 2) !== 'PK') {
                    return self::importFromCsv($pdo, $filePath, $options);
                }
            }
        }

        if (!class_exists('ZipArchive')) {
            throw new Exception("Server belum mengaktifkan ekstensi PHP ZipArchive. Silakan unggah format CSV.");
        }

        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) {
            throw new Exception("File yang diunggah bukan format file Excel (.xlsx) yang valid.");
        }

        // 1. Ekstrak dan Petakan Gambar / Foto dari Dokumen Excel
        // Excel menyimpan relasi gambar di xl/drawings/drawing1.xml dan gambarnya di xl/media/
        $rowToImageBytes = self::extractImagesFromXlsx($zip);

        // 2. Baca Shared Strings jika ada (xl/sharedStrings.xml)
        $sharedStrings = [];
        $sharedStringsXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($sharedStringsXml !== false) {
            $sDoc = @simplexml_load_string($sharedStringsXml);
            if ($sDoc && isset($sDoc->si)) {
                foreach ($sDoc->si as $si) {
                    if (isset($si->t)) {
                        $sharedStrings[] = (string)$si->t;
                    } elseif (isset($si->r)) {
                        $rText = '';
                        foreach ($si->r as $r) {
                            $rText .= (string)$r->t;
                        }
                        $sharedStrings[] = $rText;
                    } else {
                        $sharedStrings[] = '';
                    }
                }
            }
        }

        // 3. Baca data lembar kerja (xl/worksheets/sheet1.xml)
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        if ($sheetXml === false) {
            $zip->close();
            throw new Exception("Lembar kerja pertama (sheet1.xml) tidak ditemukan di dalam file Excel.");
        }

        $sheetDoc = @simplexml_load_string($sheetXml);
        if (!$sheetDoc || !isset($sheetDoc->sheetData->row)) {
            $zip->close();
            throw new Exception("Struktur lembar kerja Excel kosong atau tidak terbaca.");
        }

        // Cache Master Kategori & Satuan dari DB
        $stmtKat = $pdo->query("SELECT id, nama_kategori FROM kategori");
        $katMap = [];
        while ($r = $stmtKat->fetch(PDO::FETCH_ASSOC)) {
            $katMap[strtolower(trim($r['nama_kategori']))] = (int)$r['id'];
        }

        $stmtSat = $pdo->query("SELECT id, nama_satuan, kode_satuan FROM satuan");
        $satMap = [];
        while ($r = $stmtSat->fetch(PDO::FETCH_ASSOC)) {
            $satMap[strtolower(trim($r['kode_satuan']))] = (int)$r['id'];
            $satMap[strtolower(trim($r['nama_satuan']))] = (int)$r['id'];
        }

        $uploadDir = __DIR__ . '/../../assets/images/produk/';
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0775, true);
        }

        $stats = [
            'total_rows'       => 0,
            'inserted'         => 0,
            'updated'          => 0,
            'skipped'          => 0,
            'images_saved'     => 0,
            'errors'           => [],
        ];

        // Mulai iterasi baris (Baris 1 = Header, Data mulai baris 2)
        foreach ($sheetDoc->sheetData->row as $rowElem) {
            $rNum = (int)$rowElem['r'];
            if ($rNum <= 1) {
                // Lewati baris judul / header
                continue;
            }

            // Ekstrak sel baris ini ke dalam array kolom huruf => nilai
            $rowCells = [];
            foreach ($rowElem->c as $cell) {
                $ref = (string)$cell['r'];
                // Dapatkan huruf kolom (misal 'A', 'B', 'AA')
                $col = preg_replace('/[0-9]/', '', $ref);
                $type = (string)$cell['t'];

                $val = '';
                if ($type === 's') {
                    $sIdx = (int)$cell->v;
                    $val = $sharedStrings[$sIdx] ?? '';
                } elseif ($type === 'inlineStr') {
                    $val = (string)$cell->is->t;
                } else {
                    $val = (string)$cell->v;
                }
                $rowCells[$col] = trim($val);
            }

            // Ambil masing-masing kolom sesuai template
            // A: Nama Barang
            // B: Barcode
            // C: Kategori
            // D: Satuan
            // E: Harga Jual
            // F: Stok Awal
            // G: Deskripsi
            // H: Foto
            $namaProduk   = trim($rowCells['A'] ?? '');
            $barcode      = trim($rowCells['B'] ?? '');
            $kategoriRaw  = trim($rowCells['C'] ?? '');
            $satuanRaw    = trim($rowCells['D'] ?? '');
            $hargaRaw     = trim($rowCells['E'] ?? '0');
            $stokRaw      = trim($rowCells['F'] ?? '0');
            $deskripsi    = trim($rowCells['G'] ?? '');
            $fotoCellText = trim($rowCells['H'] ?? '');

            // Jika Nama Barang kosong, baris dianggap kosong/selesai
            if ($namaProduk === '') {
                continue;
            }

            // Abaikan jika baris merupakan teks petunjuk bawaan contoh yang belum diedit sama sekali
            if (strpos($namaProduk, 'Kopi Susu Gula Aren') !== false && $barcode === '899275321001') {
                // Baris contoh template, lewati jika belum diubah
                // Tapi jika user ingin tetap menyimpannya, boleh dicek
            }

            $stats['total_rows']++;

            // Pembersihan format harga (Hilangkan Rp, titik, koma, spasi)
            $hargaClean = preg_replace('/[^0-9]/', '', str_replace(['.00', ',00'], '', $hargaRaw));
            $harga = (float)($hargaClean !== '' ? $hargaClean : 0);

            // Pembersihan stok
            $stokClean = preg_replace('/[^0-9\-]/', '', $stokRaw);
            $stok = (int)($stokClean !== '' ? $stokClean : 0);

            // 1. Resolve Kategori ID
            $kategoriId = null;
            if ($kategoriRaw !== '') {
                $katKey = strtolower($kategoriRaw);
                if (isset($katMap[$katKey])) {
                    $kategoriId = $katMap[$katKey];
                } else {
                    // Buat kategori baru secara otomatis jika belum terdaftar di DB
                    try {
                        $stmtNewKat = $pdo->prepare("INSERT INTO kategori (nama_kategori) VALUES (?)");
                        $stmtNewKat->execute([$kategoriRaw]);
                        $kategoriId = (int)$pdo->lastInsertId();
                        $katMap[$katKey] = $kategoriId;
                    } catch (Exception $e) {
                        $kategoriId = null;
                    }
                }
            }

            // 2. Resolve Satuan ID
            $satuanId = null;
            if ($satuanRaw !== '') {
                $satKey = strtolower($satuanRaw);
                if (isset($satMap[$satKey])) {
                    $satuanId = $satMap[$satKey];
                } else {
                    // Buat satuan baru jika belum terdaftar
                    try {
                        $stmtNewSat = $pdo->prepare("INSERT INTO satuan (nama_satuan, kode_satuan) VALUES (?, ?)");
                        $stmtNewSat->execute([$satuanRaw, strtoupper($satuanRaw)]);
                        $satuanId = (int)$pdo->lastInsertId();
                        $satMap[$satKey] = $satuanId;
                    } catch (Exception $e) {
                        $satuanId = null;
                    }
                }
            }

            // 3. Tangani Foto Produk
            $fotoPath = null;
            $imgRawBytes = null;
            if (isset($rowToImageBytes[$rNum]) && !empty($rowToImageBytes[$rNum])) {
                $imgRawBytes = $rowToImageBytes[$rNum];
            } elseif (isset($rowToImageBytes['fallback_single']) && !empty($rowToImageBytes['fallback_single'])) {
                $imgRawBytes = $rowToImageBytes['fallback_single'];
                unset($rowToImageBytes['fallback_single']); // gunakan hanya sekali
            }

            if ($imgRawBytes) {
                $savedImg = self::saveImageBytesToDisk($imgRawBytes, $uploadDir);
                if ($savedImg) {
                    $fotoPath = 'assets/images/produk/' . $savedImg;
                    $stats['images_saved']++;
                }
            }

            // B. Jika tidak ada gambar embedded, cek apakah user mengetik nama file foto yang sudah ada
            if (!$fotoPath && $fotoCellText !== '' && strpos($fotoCellText, 'Sisipkan') === false) {
                // Cek jika file tersebut ada di folder produk
                $cleanFotoName = basename($fotoCellText);
                if (file_exists($uploadDir . $cleanFotoName)) {
                    $fotoPath = 'assets/images/produk/' . $cleanFotoName;
                }
            }

            // 4. Periksa apakah produk sudah ada (berdasarkan Barcode atau Nama Produk)
            $existingProduct = null;
            if ($barcode !== '') {
                $stmtCek = $pdo->prepare("SELECT id, nama_produk, foto, stok FROM produk WHERE barcode = ? LIMIT 1");
                $stmtCek->execute([$barcode]);
                $existingProduct = $stmtCek->fetch(PDO::FETCH_ASSOC);
            }
            if (!$existingProduct) {
                $stmtCekNama = $pdo->prepare("SELECT id, nama_produk, foto, stok FROM produk WHERE LOWER(nama_produk) = ? LIMIT 1");
                $stmtCekNama->execute([strtolower($namaProduk)]);
                $existingProduct = $stmtCekNama->fetch(PDO::FETCH_ASSOC);
            }

            try {
                if ($existingProduct) {
                    if ($updateExisting) {
                        // Perbarui data yang ada
                        $finalFoto = $fotoPath ? $fotoPath : $existingProduct['foto'];
                        $stmtUpdate = $pdo->prepare("
                            UPDATE produk 
                            SET kategori_id = COALESCE(?, kategori_id),
                                satuan_id   = COALESCE(?, satuan_id),
                                nama_produk = ?,
                                barcode     = COALESCE(?, barcode),
                                harga       = ?,
                                stok        = ?,
                                deskripsi   = CASE WHEN ? != '' THEN ? ELSE deskripsi END,
                                foto        = ?
                            WHERE id = ?
                        ");
                        $stmtUpdate->execute([
                            $kategoriId,
                            $satuanId,
                            $namaProduk,
                            $barcode !== '' ? $barcode : null,
                            $harga,
                            $stok,
                            $deskripsi,
                            $deskripsi,
                            $finalFoto,
                            $existingProduct['id']
                        ]);
                        $stats['updated']++;
                    } else {
                        // Jika tidak dicentang update_existing, tapi foto di database masih kosong dan ada foto baru diekstrak dari Excel:
                        if ((empty($existingProduct['foto']) || $existingProduct['foto'] === '') && $fotoPath) {
                            $stmtUpFoto = $pdo->prepare("UPDATE produk SET foto = ? WHERE id = ?");
                            $stmtUpFoto->execute([$fotoPath, $existingProduct['id']]);
                            $stats['updated']++;
                        } else {
                            // Lewati karena sudah ada
                            $stats['skipped']++;
                        }
                    }
                } else {
                    // Tambah produk baru
                    $stmtInsert = $pdo->prepare("
                        INSERT INTO produk (kategori_id, satuan_id, nama_produk, barcode, harga, stok, deskripsi, foto, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                    ");
                    $stmtInsert->execute([
                        $kategoriId,
                        $satuanId,
                        $namaProduk,
                        $barcode !== '' ? $barcode : null,
                        $harga,
                        $stok,
                        $deskripsi,
                        $fotoPath
                    ]);
                    $stats['inserted']++;
                }
            } catch (Exception $e) {
                $stats['errors'][] = "Baris {$rNum} ({$namaProduk}): " . $e->getMessage();
            }
        }

        $zip->close();
        return $stats;
    }

    /**
     * Membaca dan mengekstrak gambar dari dokumen Excel (.xlsx) baik:
     * 1. Modern Excel "Place in Cell" (In-Cell via Rich Data / xl/richData/ atau xl/cellimages.xml)
     * 2. Classic Excel "Place Over Cells" (Floating via xl/drawings/drawing*.xml)
     * 3. Fallback pemetaan cerdas media
     */
    private static function extractImagesFromXlsx(ZipArchive $zip) {
        $rowToImage = [];

        // =========================================================================
        // METODE 1: Modern Excel "Place in Cell" via Rich Data (Excel 365 / 2021+)
        // =========================================================================
        $richRelsXml = $zip->getFromName('xl/richData/_rels/richValueRel.xml.rels');
        if ($richRelsXml !== false) {
            $richRelsDoc = @simplexml_load_string($richRelsXml);
            $richRelMap = [];
            if ($richRelsDoc && isset($richRelsDoc->Relationship)) {
                foreach ($richRelsDoc->Relationship as $r) {
                    $id = (string)$r['Id'];
                    $target = (string)$r['Target'];
                    $cleanTarget = ltrim(str_replace(['../', '\\'], ['', '/'], $target), '/');
                    if (strpos($cleanTarget, 'xl/') !== 0) {
                        $cleanTarget = 'xl/' . $cleanTarget;
                    }
                    $richRelMap[$id] = $cleanTarget;
                }
            }

            $richValXml = $zip->getFromName('xl/richData/richValueRel.xml');
            $richValueRels = [];
            if ($richValXml !== false) {
                if (preg_match_all('/<rel[^>]+(?:r:id|id)="([^"]+)"/i', $richValXml, $m)) {
                    $richValueRels = $m[1];
                }
            }

            $vmToRv = [];
            $metaXml = $zip->getFromName('xl/metadata.xml');
            if ($metaXml !== false) {
                $mDoc = @simplexml_load_string($metaXml);
                if ($mDoc && isset($mDoc->valueMetadata->bk)) {
                    $vmIdx = 1;
                    foreach ($mDoc->valueMetadata->bk as $bk) {
                        $v = isset($bk->rc['v']) ? (int)$bk->rc['v'] : ($vmIdx - 1);
                        $vmToRv[$vmIdx] = $v;
                        $vmIdx++;
                    }
                }
            }

            $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
            if ($sheetXml !== false) {
                $sDoc = @simplexml_load_string($sheetXml);
                if ($sDoc && isset($sDoc->sheetData->row)) {
                    foreach ($sDoc->sheetData->row as $rElem) {
                        $rNum = (int)$rElem['r'];
                        foreach ($rElem->c as $c) {
                            if (isset($c['vm'])) {
                                $vm = (int)$c['vm'];
                                $rvIdx = $vmToRv[$vm] ?? ($vm - 1);
                                $rId = $richValueRels[$rvIdx] ?? null;
                                if ($rId && isset($richRelMap[$rId])) {
                                    $mediaFile = $richRelMap[$rId];
                                    $imgBytes = $zip->getFromName($mediaFile);
                                    if ($imgBytes !== false) {
                                        $rowToImage[$rNum] = $imgBytes;
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        // =========================================================================
        // METODE 2: In-Cell Images via cellimages.xml (_xlfn.DISPIMG)
        // =========================================================================
        $cellImagesXml = $zip->getFromName('xl/cellimages.xml');
        if ($cellImagesXml === false) {
            $cellImagesXml = $zip->getFromName('xl/cellImages.xml');
        }
        if ($cellImagesXml !== false) {
            $cellImgRelsXml = $zip->getFromName('xl/_rels/cellimages.xml.rels');
            if ($cellImgRelsXml === false) {
                $cellImgRelsXml = $zip->getFromName('xl/_rels/cellImages.xml.rels');
            }
            if ($cellImgRelsXml === false) {
                $cellImgRelsXml = $zip->getFromName('xl/cellimages.xml.rels');
            }

            $cellRelMap = [];
            if ($cellImgRelsXml !== false) {
                $ciRelsDoc = @simplexml_load_string($cellImgRelsXml);
                if ($ciRelsDoc && isset($ciRelsDoc->Relationship)) {
                    foreach ($ciRelsDoc->Relationship as $r) {
                        $id = (string)$r['Id'];
                        $target = (string)$r['Target'];
                        $cleanTarget = ltrim(str_replace(['../', '\\'], ['', '/'], $target), '/');
                        if (strpos($cleanTarget, 'xl/') !== 0) {
                            $cleanTarget = 'xl/' . $cleanTarget;
                        }
                        $cellRelMap[$id] = $cleanTarget;
                    }
                }
            }

            $nameToMedia = [];
            $ciDoc = new DOMDocument();
            if (@$ciDoc->loadXML($cellImagesXml)) {
                $xpathCI = new DOMXPath($ciDoc);
                $pics = $xpathCI->query('//*[local-name()="pic"]');
                foreach ($pics as $pic) {
                    $cName = '';
                    $cNvPrs = $xpathCI->query('.//*[local-name()="cNvPr"]', $pic);
                    if ($cNvPrs->length > 0) {
                        $cName = $cNvPrs->item(0)->getAttribute('name');
                    }
                    $blips = $xpathCI->query('.//*[local-name()="blip"]', $pic);
                    if ($blips->length > 0 && $cName !== '') {
                        $blip = $blips->item(0);
                        $embedId = '';
                        foreach ($blip->attributes as $attr) {
                            if ($attr->localName === 'embed' || strpos($attr->name, 'embed') !== false) {
                                $embedId = $attr->nodeValue;
                                break;
                            }
                        }
                        if ($embedId && isset($cellRelMap[$embedId])) {
                            $nameToMedia[$cName] = $cellRelMap[$embedId];
                        }
                    }
                }
            }

            $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
            if ($sheetXml !== false && !empty($nameToMedia)) {
                $sDoc = @simplexml_load_string($sheetXml);
                if ($sDoc && isset($sDoc->sheetData->row)) {
                    foreach ($sDoc->sheetData->row as $rElem) {
                        $rNum = (int)$rElem['r'];
                        if (isset($rowToImage[$rNum])) continue;
                        foreach ($rElem->c as $c) {
                            $formula = isset($c->f) ? (string)$c->f : '';
                            foreach ($nameToMedia as $cName => $mFile) {
                                if (strpos($formula, $cName) !== false) {
                                    $imgBytes = $zip->getFromName($mFile);
                                    if ($imgBytes !== false) {
                                        $rowToImage[$rNum] = $imgBytes;
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        // =========================================================================
        // METODE 3: Classic "Place Over Cells" via Drawings (drawing*.xml)
        // =========================================================================
        $drawingFiles = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entryName = $zip->getNameIndex($i);
            if (preg_match('#^xl/drawings/drawing\d+\.xml$#i', $entryName)) {
                $drawingFiles[] = $entryName;
            }
        }

        foreach ($drawingFiles as $drawingFile) {
            $drawingXml = $zip->getFromName($drawingFile);
            if ($drawingXml === false) continue;

            $drawingDir = dirname(str_replace('\\', '/', $drawingFile));
            $drawingRelsPath = $drawingDir . '/_rels/' . basename($drawingFile) . '.rels';
            $drawingRelsXml = $zip->getFromName($drawingRelsPath);
            $rIdToMedia = [];
            if ($drawingRelsXml !== false) {
                $dRelsDoc = @simplexml_load_string($drawingRelsXml);
                if ($dRelsDoc && isset($dRelsDoc->Relationship)) {
                    foreach ($dRelsDoc->Relationship as $rel) {
                        $rId = (string)$rel['Id'];
                        $target = (string)$rel['Target'];
                        $cleanTarget = ltrim(str_replace(['../', '\\'], ['', '/'], $target), '/');
                        if (strpos($cleanTarget, 'xl/') !== 0) {
                            $cleanTarget = 'xl/' . $cleanTarget;
                        }
                        $rIdToMedia[$rId] = $cleanTarget;
                    }
                }
            }

            $doc = new DOMDocument();
            if (@$doc->loadXML($drawingXml)) {
                $xpath = new DOMXPath($doc);
                $xpath->registerNamespace('xdr', 'http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing');
                $xpath->registerNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');

                $anchors = $xpath->query('//xdr:twoCellAnchor | //xdr:oneCellAnchor');
                foreach ($anchors as $anchor) {
                    $fromRows = $xpath->query('.//xdr:from/xdr:row', $anchor);
                    if ($fromRows->length === 0) continue;
                    $zeroBasedRow = (int)$fromRows->item(0)->textContent;
                    $excelRowNum = $zeroBasedRow + 1;

                    if (isset($rowToImage[$excelRowNum])) continue;

                    $blips = $xpath->query('.//a:blip', $anchor);
                    if ($blips->length === 0) continue;
                    $blip = $blips->item(0);

                    $embedId = '';
                    foreach ($blip->attributes as $attr) {
                        if ($attr->localName === 'embed' || strpos($attr->name, 'embed') !== false) {
                            $embedId = $attr->nodeValue;
                            break;
                        }
                    }
                    if (!$embedId) {
                        $embedId = $blip->getAttribute('r:embed');
                    }

                    if ($embedId && isset($rIdToMedia[$embedId])) {
                        $mediaFile = $rIdToMedia[$embedId];
                        $imageBytes = $zip->getFromName($mediaFile);
                        if ($imageBytes !== false) {
                            $rowToImage[$excelRowNum] = $imageBytes;
                        }
                    }
                }
            }
        }

        // =========================================================================
        // METODE 4: Smart Fallback (Jika media ada di xl/media/ tapi belum terpetakan)
        // =========================================================================
        if (empty($rowToImage)) {
            $mediaFiles = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entryName = $zip->getNameIndex($i);
                if (preg_match('#^xl/media/image\d+\.(png|jpe?g|webp|gif)$#i', $entryName)) {
                    $mediaFiles[] = $entryName;
                }
            }
            sort($mediaFiles);

            if (count($mediaFiles) === 1) {
                $rowToImage['fallback_single'] = $zip->getFromName($mediaFiles[0]);
            }
        }

        return $rowToImage;
    }

    /**
     * Memproses bytes gambar (resize & crop tengah 1:1 persegi 600px) lalu simpan ke disk
     */
    private static function saveImageBytesToDisk($rawBytes, $targetDir) {
        if (empty($rawBytes) || strlen($rawBytes) < 10) {
            return null;
        }

        $uniqueName = 'prod_import_' . uniqid() . '_' . time() . '.jpg';
        $fullPath = rtrim($targetDir, '/\\') . DIRECTORY_SEPARATOR . $uniqueName;

        if (function_exists('imagecreatefromstring')) {
            $src = @imagecreatefromstring($rawBytes);
            if ($src !== false) {
                $sw = imagesx($src);
                $sh = imagesy($src);
                if ($sw > 0 && $sh > 0) {
                    $side = min($sw, $sh);
                    $sx = (int)(($sw - $side) / 2);
                    $sy = (int)(($sh - $side) / 2);
                    $tsize = min($side, 600);

                    $dst = imagecreatetruecolor($tsize, $tsize);
                    imagecopyresampled($dst, $src, 0, 0, $sx, $sy, $tsize, $tsize, $side, $side);
                    imagejpeg($dst, $fullPath, 84);
                    imagedestroy($src);
                    imagedestroy($dst);
                    @chmod($fullPath, 0664);
                    return $uniqueName;
                }
            }
        }

        // Fallback simpan mentah jika GD gagal/tidak memproses
        if (@file_put_contents($fullPath, $rawBytes)) {
            @chmod($fullPath, 0664);
            return $uniqueName;
        }

        return null;
    }
    /**
     * Fallback Template CSV jika modul php-zip belum aktif di PHP server
     * Langsung kompatibel dan bisa dibuka dengan Microsoft Excel
     */
    public static function downloadCsvTemplate(array $kategoriList, array $satuanList) {
        $filename = 'Template_Import_Barang_QiOS_' . date('Ymd_His') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0');
        header('Pragma: public');

        $out = fopen('php://output', 'w');
        // UTF-8 BOM agar terbaca rapi dengan aksen/simbol di Excel
        fputs($out, "ï»¿");

        // Header Kolom
        fputcsv($out, [
            'No',
            'Barcode / Kode Unik',
            'Kategori Produk (Pilih Salah Satu)',
            'Satuan Barang (Pilih Salah Satu)',
            'Nama Produk / Barang (*Wajib)',
            'Harga Jual (Rp) (*Wajib)',
            'Jumlah Stok Awal (*Wajib)',
            'Deskripsi / Catatan'
        ]);

        // Baris Contoh 1
        $katContoh1 = $kategoriList[0] ?? 'Makanan';
        $satContoh1 = $satuanList[0] ?? 'Pcs';
        fputcsv($out, [
            '1',
            '8992761111015',
            $katContoh1,
            $satContoh1,
            'Indomie Goreng Spesial',
            '3500',
            '50',
            'Dus isi 40'
        ]);

        // Baris Contoh 2
        $katContoh2 = $kategoriList[1] ?? 'Minuman';
        $satContoh2 = $satuanList[1] ?? 'Btl';
        fputcsv($out, [
            '2',
            '8991002101015',
            $katContoh2,
            $satContoh2,
            'Teh Pucuk Harum 350ml',
            '4000',
            '100',
            'Karton isi 24'
        ]);

        // Baris Contoh 3
        fputcsv($out, [
            '3',
            '',
            $katContoh1,
            $satContoh1,
            'Jajan Gorengan Tempe',
            '1000',
            '25',
            'Tanpa Barcode'
        ]);

        fclose($out);
        exit;
    }

    /**
     * Import Produk dari Berkas CSV
     */
    public static function importFromCsv(PDO $pdo, $filePath, array $options = []) {
        $updateExisting = !empty($options['update_existing']);
        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            throw new Exception("Gagal membuka file CSV.");
        }

        // Buat map kategori & satuan
        $katMap = [];
        $stmtK = $pdo->query("SELECT id, LOWER(nama_kategori) AS nk FROM kategori");
        while ($rk = $stmtK->fetch()) {
            $katMap[$rk['nk']] = (int)$rk['id'];
        }

        $satMap = [];
        $stmtS = $pdo->query("SELECT id, LOWER(kode_satuan) AS ks FROM satuan");
        while ($rs = $stmtS->fetch()) {
            $satMap[$rs['ks']] = (int)$rs['id'];
        }

        $inserted = 0;
        $updated = 0;
        $errors = [];
        $lineNum = 0;

        while (($row = fgetcsv($handle, 2000, ",")) !== false) {
            $lineNum++;
            // Lewati header baris 1
            if ($lineNum === 1) {
                continue;
            }
            if (empty($row) || (count($row) === 1 && trim($row[0]) === '')) {
                continue;
            }

            // Kolom CSV: No(0), Barcode(1), Kategori(2), Satuan(3), Nama Produk(4), Harga(5), Stok(6), Deskripsi(7)
            $barcode    = isset($row[1]) ? trim($row[1]) : '';
            $namaKat    = isset($row[2]) ? strtolower(trim($row[2])) : '';
            $namaSat    = isset($row[3]) ? strtolower(trim($row[3])) : '';
            $namaProduk = isset($row[4]) ? trim($row[4]) : '';
            $hargaRaw   = isset($row[5]) ? trim($row[5]) : '0';
            $stokRaw    = isset($row[6]) ? trim($row[6]) : '0';
            $deskripsi  = isset($row[7]) ? trim($row[7]) : '';

            // Jika kolom geser (misal tidak ada No)
            if (empty($namaProduk) && !empty($row[3]) && is_numeric(str_replace(['.', ','], '', $row[4] ?? ''))) {
                $namaProduk = trim($row[3]);
                $hargaRaw = trim($row[4]);
                $stokRaw = trim($row[5] ?? '0');
            }

            if (empty($namaProduk)) {
                continue;
            }

            $harga = (float)str_replace(['Rp', '.', ' ', ','], ['', '', '', '.'], $hargaRaw);
            $stok  = (int)str_replace(['.', ','], '', $stokRaw);

            // Kategori ID
            $katId = $katMap[$namaKat] ?? null;
            if (!$katId && !empty($namaKat)) {
                $stK = $pdo->prepare("INSERT INTO kategori (nama_kategori) VALUES (?)");
                $stK->execute([ucwords($namaKat)]);
                $katId = (int)$pdo->lastInsertId();
                $katMap[$namaKat] = $katId;
            }

            // Satuan ID
            $satId = $satMap[$namaSat] ?? null;
            if (!$satId && !empty($namaSat)) {
                $stS = $pdo->prepare("INSERT INTO satuan (kode_satuan, nama_satuan) VALUES (?, ?)");
                $stS->execute([ucwords($namaSat), ucwords($namaSat)]);
                $satId = (int)$pdo->lastInsertId();
                $satMap[$namaSat] = $satId;
            }

            // Cek duplikasi produk
            $existingId = null;
            if (!empty($barcode)) {
                $stE = $pdo->prepare("SELECT id FROM produk WHERE barcode = ? LIMIT 1");
                $stE->execute([$barcode]);
                $ex = $stE->fetch();
                if ($ex) $existingId = (int)$ex['id'];
            }
            if (!$existingId) {
                $stE2 = $pdo->prepare("SELECT id FROM produk WHERE LOWER(nama_produk) = ? LIMIT 1");
                $stE2->execute([strtolower($namaProduk)]);
                $ex2 = $stE2->fetch();
                if ($ex2) $existingId = (int)$ex2['id'];
            }

            if ($existingId) {
                if ($updateExisting) {
                    $stU = $pdo->prepare("UPDATE produk SET kategori_id = ?, satuan_id = ?, nama_produk = ?, barcode = ?, harga = ?, stok = ?, deskripsi = ? WHERE id = ?");
                    $stU->execute([$katId, $satId, $namaProduk, $barcode ?: null, $harga, $stok, $deskripsi, $existingId]);
                    $updated++;
                }
            } else {
                $stI = $pdo->prepare("INSERT INTO produk (kategori_id, satuan_id, nama_produk, barcode, harga, stok, deskripsi) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stI->execute([$katId, $satId, $namaProduk, $barcode ?: null, $harga, $stok, $deskripsi]);
                $inserted++;
            }
        }
        fclose($handle);

        return [
            'inserted' => $inserted,
            'updated'  => $updated,
            'errors'   => $errors
        ];
    }

}
